<?php

namespace StudentVerification\Services;

/**
 * 应急管理大学统一认证扫码登录服务。
 *
 * 对接 Wisedu authserver 的二维码登录接口：
 *   - /qrCode/getToken      创建二维码会话，返回令牌
 *   - /qrCode/getCode       按令牌返回二维码图片
 *   - /qrCode/getStatus.htl 轮询状态：0=未扫 1=已确认 2=已扫待确认 3=失效
 *   - 状态为 1 后提交 qrLogin 表单完成登录，会话即由本服务持有
 *
 * 用户用学校 App（今日校园/企业微信/钉钉）扫码并在手机上确认，
 * 全程不需要密码，也不需要 CAS 服务登记。
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

    public function create(string $cookieFile): array
    {
        $token = trim($this->httpGet(
            self::BASE_URL . '/qrCode/getToken?ts=' . (int) (microtime(true) * 1000),
            [],
            $cookieFile
        ));

        if ($token === '') {
            return ['success' => false, 'uuid' => '', 'image' => ''];
        }

        return [
            'success' => true,
            'uuid' => $token,
            'image' => self::BASE_URL . '/qrCode/getCode?uuid=' . rawurlencode($token),
        ];
    }

    /**
     * 轮询扫码状态：0 未扫 / 1 已确认 / 2 已扫待确认 / 3 失效
     */
    public function status(string $cookieFile, string $uuid): string
    {
        return trim($this->httpGet(
            self::BASE_URL . '/qrCode/getStatus.htl?ts=' . (int) (microtime(true) * 1000)
                . '&uuid=' . rawurlencode($uuid),
            [],
            $cookieFile
        ));
    }

    /**
     * 状态为已确认后，提交 qrLogin 表单完成登录。
     */
    public function complete(string $cookieFile, string $uuid): bool
    {
        // 先从登录页取 execution / lt（与扫码会话共用 cookie）
        $loginPage = $this->httpGet(self::BASE_URL . '/login', [], $cookieFile);
        $execution = $this->extractField($loginPage, 'execution');
        $lt = $this->extractField($loginPage, 'lt');

        if ($execution === '') {
            $execution = 'e1s1';
        }

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

        // 登录成功后应离开登录页（跳转到 index.do 等）
        $url = $this->lastEffectiveUrl();

        return $url !== null && !str_contains($url, '/login')
            || str_contains($response, 'logout')
            || str_contains($response, 'index.do');
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
            self::BASE_URL . '/personalCenter',
            self::BASE_URL . '/login',
        ];

        foreach ($pages as $page) {
            try {
                $html = $this->httpGet($page, [], $cookieFile);
            } catch (\Exception $e) {
                continue;
            }

            $studentId = $this->extractStudentId($html);
            $studentName = $this->extractStudentName($html);

            if ($studentId !== '') {
                return ['student_id' => $studentId, 'student_name' => $studentName];
            }
        }

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