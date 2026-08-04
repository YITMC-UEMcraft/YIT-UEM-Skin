<?php

namespace StudentVerification\Services;

/**
 * 燕京理工学院教务系统认证服务。
 *
 * 对接 jw.yit.edu.cn（正方教务系统，响应为 GBK/GB18030 编码）。
 * 登录方式：encoded = Base64(学号) . "%%%" . Base64(密码)
 * 登录成功后访问学籍卡片页解析真实姓名，并核对学号一致。
 * 密码只用于本次请求，不落库。
 */
class YitAuthService extends BaseAuthService
{
    private const BASE_URL = 'http://jw.yit.edu.cn/yjlgxy_jsxsd';
    private const LOGIN_URL = 'http://jw.yit.edu.cn/yjlgxy_jsxsd/xk/LoginToXk';
    private const PROFILE_URL = 'http://jw.yit.edu.cn/yjlgxy_jsxsd/grxx/xsxx'; // 学籍卡片

    public function getSchool(): string
    {
        return 'yit';
    }

    public function verify(string $studentId, string $password): array
    {
        $studentId = trim($studentId);
        if ($studentId === '' || $password === '') {
            return $this->fail('请输入学号和密码');
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'yit_cookie_');

        try {
            // Step 1: Base64 编码提交登录
            $encodedUser = base64_encode($studentId);
            $encodedPass = base64_encode($password);
            $encodedValue = "{$encodedUser}%%%{$encodedPass}";

            $response = $this->httpPost(
                self::LOGIN_URL,
                ['encoded' => $encodedValue],
                ['Referer: ' . self::BASE_URL . '/'],
                $cookieFile
            );

            // Step 2: 判定登录结果（正方系统失败时会回到登录页，编码为 GBK）
            $loginPage = $this->decodeHtml($response);
            $failedMessage = $this->loginFailureMessage($loginPage);
            if ($failedMessage !== null) {
                return $this->fail($failedMessage);
            }
            if ($this->isStillOnLoginPage()) {
                return $this->fail('教务系统登录失败，请检查学号和密码');
            }

            // Step 3: 访问学籍卡片页
            $profileHtml = $this->decodeHtml($this->httpGet(
                self::PROFILE_URL,
                ['Referer: ' . self::BASE_URL . '/'],
                $cookieFile
            ));

            // Step 4: 解析姓名与学号
            $studentName = $this->extractStudentName($profileHtml);
            $pageStudentId = $this->extractStudentId($profileHtml);

            if ($studentName === '') {
                return $this->fail('获取学生信息失败，请联系管理员');
            }

            // 学号核对：若页面中能解析出学号，则必须与输入一致
            if ($pageStudentId !== '' && strcasecmp($pageStudentId, $studentId) !== 0) {
                return $this->fail('学号与教务系统信息不一致');
            }

            return [
                'success' => true,
                'student_name' => $studentName,
                'message' => '验证通过',
            ];
        } catch (\Exception $e) {
            return $this->fail($this->friendlyError($e));
        } finally {
            @unlink($cookieFile);
        }
    }

    /**
     * 从登录响应中识别失败原因。
     */
    private function loginFailureMessage(string $html): ?string
    {
        $patterns = [
            '用户名或密码错误' => '教务系统登录失败，请检查学号和密码',
            '密码错误' => '教务系统登录失败，请检查学号和密码',
            '账号或密码错误' => '教务系统登录失败，请检查学号和密码',
            '用户被锁定' => '账号已被锁定，请联系学校教务处',
            '验证码错误' => '验证码错误，请稍后再试',
        ];

        foreach ($patterns as $needle => $message) {
            if (str_contains($html, $needle)) {
                return $message;
            }
        }

        // 回到登录页且存在账号输入框，视为登录失败
        if (str_contains($html, '用户登录') && str_contains($html, 'userAccount')) {
            return '教务系统登录失败，请检查学号和密码';
        }

        return null;
    }

    /**
     * 登录后是否仍停留在登录入口（说明未登录成功）。
     */
    private function isStillOnLoginPage(): bool
    {
        $url = $this->lastEffectiveUrl();

        return $url !== null
            && (str_contains($url, 'LoginToXk') || str_ends_with($url, '/login'));
    }

    /**
     * 将教务系统响应（GBK/GB18030）统一转为 UTF-8。
     */
    private function decodeHtml(string $html): string
    {
        if ($html === '' || mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        $converted = @mb_convert_encoding($html, 'UTF-8', 'GB18030');

        return $converted !== false && $converted !== '' ? $converted : $html;
    }

    /**
     * 从教务系统个人信息页面提取学生姓名。
     */
    private function extractStudentName(string $html): string
    {
        $patterns = [
            // 姓名[：:] 后跟值
            '/姓名[：:]\s*([^\s<&]+)/u',
            // 表格：<td>姓名</td><td>值</td>
            '/<td[^>]*>姓名<\/td>\s*<td[^>]*>(?:&nbsp;)?\s*([^<]{2,10})<\/td>/u',
            // input value 形式：name="xm" value="张三"
            '/name="xm"[^>]*value="([^"]+)"/i',
            '/id="xm"[^>]*value="([^"]+)"/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $name = trim($matches[1]);
                $name = str_replace('&nbsp;', '', $name);
                if (mb_strlen($name) >= 2) {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * 从教务系统个人信息页面提取学号，用于交叉核对。
     */
    private function extractStudentId(string $html): string
    {
        $patterns = [
            '/学号[：:]\s*([A-Za-z0-9]+)/u',
            '/<td[^>]*>学号<\/td>\s*<td[^>]*>(?:&nbsp;)?\s*([A-Za-z0-9]+)<\/td>/u',
            '/name="xh"[^>]*value="([^"]+)"/i',
            '/id="xh"[^>]*value="([^"]+)"/i',
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
     * 构造失败返回。
     */
    private function fail(string $message): array
    {
        return [
            'success' => false,
            'student_name' => '',
            'message' => $message,
        ];
    }
}