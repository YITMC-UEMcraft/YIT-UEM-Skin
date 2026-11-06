<?php

namespace StudentVerification\Services;

use Illuminate\Support\Facades\Log;

/**
 * 应急管理大学统一认证扫码登录服务。
 *
 * 对接 Wisedu authserver 的二维码登录接口：
 *   - /qrCode/getToken      创建二维码会话，返回令牌
 *   - /qrCode/getCode       按令牌返回二维码图片
 *   - /qrCode/getStatus.htl 轮询状态：0=未扫 1=已确认 2=已扫待确认 3=失效
 *     （2026-10 起未扫码状态对新会话可能返回空串，按未扫处理，学校自家页面同样如此）
 *   - 状态为 1 后提交 qrLogin 表单完成登录，会话即由本服务持有
 *
 * 用户用学校 App（今日校园/企业微信/钉钉）扫码并在手机上确认，
 * 全程不需要密码，也不需要 CAS 服务登记。
 *
 * 2026-10-11 修正：学校升级后校验二维码令牌与登录 WebFlow 会话的绑定顺序，
 * 必须复刻学校登录页的时序——先 GET /login 建立会话，再 getToken，
 * 确认后用同一个 execution 提交（与学校 qrcode.js 行为一致）。
 * 登录成功后学校跳转新个人中心（经 my1.ncist.edu.cn 中转），成功判定以
 * cookie jar 中的 CASTGC 为权威信号。
 */
class UemQrService extends BaseAuthService
{
    private const BASE_URL = 'https://auth.ncist.edu.cn/authserver';

    public function getSchool(): string
    {
        return 'uem';
    }

    /**
     * 扫码服务不提供密码验证。
     */
    public function verify(string $studentId, string $password): array
    {
        return [
            'success' => false,
            'student_name' => '',
            'message' => '请使用扫码验证',
        ];
    }

    /**
     * 创建扫码会话。必须先加载登录页建立 WebFlow 会话（学校 2026-10 升级后
     * 校验二维码令牌与登录会话的绑定顺序），再取令牌，并记录当时的 execution，
     * complete() 需用同一个 execution 提交。
     */
    public function create(string $cookieFile): array
    {
        $loginPage = $this->httpGet(self::BASE_URL . '/login', [], $cookieFile);
        $execution = $this->extractField($loginPage, 'execution');

        $token = trim($this->httpGet(
            self::BASE_URL . '/qrCode/getToken?ts=' . (int) (microtime(true) * 1000),
            [],
            $cookieFile
        ));

        if ($token === '') {
            return ['success' => false, 'uuid' => '', 'image' => '', 'execution' => ''];
        }

        return [
            'success' => true,
            'uuid' => $token,
            'image' => self::BASE_URL . '/qrCode/getCode?uuid=' . rawurlencode($token),
            'execution' => $execution,
        ];
    }

    /**
     * 轮询扫码状态：0 未扫 / 1 已确认 / 2 已扫待确认 / 3 失效
     */
    public function status(string $cookieFile, string $uuid): string
    {
        $raw = $this->httpGet(
            self::BASE_URL . '/qrCode/getStatus.htl?ts=' . (int) (microtime(true) * 1000)
                . '&uuid=' . rawurlencode($uuid),
            [],
            $cookieFile
        );
        $status = trim($raw);

        if (!in_array($status, ['0', '1', '2', '3'], true)) {
            Log::warning('uem-qr: unexpected getStatus response', [
                'http_code' => $this->lastStatusCode,
                'body' => mb_substr($raw, 0, 300),
            ]);
        }

        return $status;
    }

    /**
     * 获取二维码图片二进制（带会话 cookie，供本站代理给浏览器）。
     */
    public function image(string $cookieFile, string $uuid): string
    {
        return $this->httpGet(
            self::BASE_URL . '/qrCode/getCode?uuid=' . rawurlencode($uuid),
            [],
            $cookieFile
        );
    }

    /**
     * 状态为已确认后，提交 qrLogin 表单完成登录。
     * $execution 必须是 create() 时登录页返回的那个（与扫码会话同一 WebFlow 流程）；
     * 旧会话没有存 execution 时回退为现取登录页。
     */
    public function complete(string $cookieFile, string $uuid, string $execution = ''): bool
    {
        // 学校端偶发抖动时重试一次（2026-10-11 排查：该校 CAS 曾出现 qrLogin 500）
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ok = $this->submitQrLogin($cookieFile, $uuid, $execution, $attempt);
            if ($ok) {
                return true;
            }

            if ($attempt === 1) {
                sleep(1);
            }
        }

        return false;
    }

    private function submitQrLogin(string $cookieFile, string $uuid, string $execution, int $attempt): bool
    {
        if ($execution === '') {
            // 回退：从登录页现取 execution / lt
            $loginPage = $this->httpGet(self::BASE_URL . '/login', [], $cookieFile);
            $execution = $this->extractField($loginPage, 'execution');
        }

        if ($execution === '') {
            $execution = 'e1s1';
        }

        // 学校登录页 qrLoginForm 的 lt 即为空值
        $lt = '';

        $fields = [
            'lt' => $lt,
            'uuid' => $uuid,
            'cllt' => 'qrLogin',
            'dllt' => 'generalLogin',
            'execution' => $execution,
            '_eventId' => 'submit',
            'rmShown' => '1',
        ];

        $response = $this->httpPost(
            self::BASE_URL . '/login',
            $fields,
            ['Referer: ' . self::BASE_URL . '/login'],
            $cookieFile
        );

        // 登录成功后应离开登录页（跳转到 index.do 等）；2026-10 起成功后会跳
        // 新个人中心（经 my1.ncist.edu.cn 中转，URL 含 login 字样），不能再拿
        // "离开 /login"当唯一判据，以 CASTGC cookie 为权威成功信号
        $url = $this->lastEffectiveUrl();

        $leftLogin = $url !== null && !str_contains($url, '/login');
        $landedPortal = $url !== null && (
            str_contains($url, 'my1.ncist.edu.cn')
            || str_contains($url, 'personalInfo')
            || str_contains($url, 'personCenter')
        );
        $hasCastgc = $this->jarHasCastgc($cookieFile);

        $ok = $leftLogin
            || $landedPortal
            || $hasCastgc
            || str_contains($response, 'logout')
            || str_contains($response, 'index.do');

        // 诊断日志：记录学校端 qrLogin 提交的原始返回（2026-10-11 排查用）
        Log::info('uem-qr: qrLogin submit', [
            'attempt' => $attempt,
            'http_code' => $this->lastStatusCode,
            'final_url' => $url,
            'left_login' => $leftLogin,
            'landed_portal' => $landedPortal,
            'has_castgc' => $hasCastgc,
            'result' => $ok,
            'execution' => $execution,
            'body_len' => strlen($response),
            'has_npe' => str_contains($response, 'NullPointerException'),
            'body_head' => mb_substr($response, 0, 200),
        ]);

        return $ok;
    }

    /**
     * 检查会话 cookie jar 中是否已有 CAS 登录凭证（CASTGC）。
     * 扫码登录成功时学校会下发 TGT；失败路径不会设置。
     */
    private function jarHasCastgc(string $cookieFile): bool
    {
        if (!is_file($cookieFile)) {
            return false;
        }

        $lines = @file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) >= 7 && strcasecmp(trim($parts[5]), 'CASTGC') === 0 && trim($parts[6]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * 从已登录会话中解析学号与姓名。
     *
     * @return array{student_id: string, student_name: string}
     */
    public function fetchIdentity(string $cookieFile): array
    {
        $pages = [
            self::BASE_URL . '/index.do',
            // 2026-10 起扫码登录后的落地点（SPA，服务端 HTML 未必含学号，尽力解析）
            'https://auth.ncist.edu.cn/personalInfo/personCenter/index.html',
            self::BASE_URL . '/personalCenter',
            self::BASE_URL . '/login',
        ];

        foreach ($pages as $page) {
            try {
                $html = $this->httpGet($page, [], $cookieFile);
            } catch (\Exception $e) {
                Log::info('uem-qr: identity page fetch failed', ['page' => $page, 'error' => $e->getMessage()]);
                continue;
            }

            $studentId = $this->extractStudentId($html);
            $studentName = $this->extractStudentName($html);

            Log::info('uem-qr: identity page parsed', [
                'page' => $page,
                'http_code' => $this->lastStatusCode,
                'html_len' => strlen($html),
                'student_id_found' => $studentId !== '',
                'student_name_found' => $studentName !== '',
            ]);

            if ($studentId !== '') {
                return ['student_id' => $studentId, 'student_name' => $studentName];
            }
        }

        Log::warning('uem-qr: identity not found on any page');

        return ['student_id' => '', 'student_name' => ''];
    }

    /**
     * 从页面中提取学号/账号。
     */
    private function extractStudentId(string $html): string
    {
        $patterns = [
            '/"userName"\s*:\s*"([A-Za-z0-9]+)"/',
            '/"username"\s*:\s*"([A-Za-z0-9]+)"/',
            '/"loginName"\s*:\s*"([A-Za-z0-9]+)"/',
            '/学号[：:]\s*([A-Za-z0-9]+)/u',
            '/帐号[：:]\s*([A-Za-z0-9]+)/u',
            '/账号[：:]\s*([A-Za-z0-9]+)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $id = trim($matches[1]);
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return '';
    }

    /**
     * 从页面中提取姓名。
     */
    private function extractStudentName(string $html): string
    {
        $patterns = [
            '/"displayName"\s*:\s*"([^"]+)"/',
            '/"realName"\s*:\s*"([^"]+)"/',
            '/欢迎(?:您|你)?[，,\s]*([\x{4e00}-\x{9fa5}]{2,10})/u',
            '/你好[，,\s]*([\x{4e00}-\x{9fa5}]{2,10})/u',
            '/您好[，,\s]*([\x{4e00}-\x{9fa5}]{2,10})/u',
            '/姓名[：:]\s*([\x{4e00}-\x{9fa5}]{2,10})/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $name = trim($matches[1]);
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * 提取隐藏字段值。
     */
    private function extractField(string $html, string $name): string
    {
        if (preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $matches)) {
            return $matches[1];
        }
        if (preg_match('/id="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $matches)) {
            return $matches[1];
        }

        return '';
    }
}