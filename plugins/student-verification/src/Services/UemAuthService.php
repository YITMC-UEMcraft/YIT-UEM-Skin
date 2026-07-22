<?php

namespace StudentVerification\Services;

class UemAuthService extends BaseAuthService
{
    private const LOGIN_URL = 'https://auth.ncist.edu.cn/authserver/login';
    private const PROFILE_URL = 'https://auth.ncist.edu.cn/authserver/personal'; // 待确认

    public function getSchool(): string
    {
        return 'uem';
    }

    public function verify(string $studentId, string $password): array
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'uem_cookie_');

        try {
            // Step 1: GET 登录页面，获取 lt、execution、salt
            $loginPageHtml = $this->httpGet(self::LOGIN_URL, [], $cookieFile);

            $lt = $this->extractHiddenField($loginPageHtml, 'lt');
            $execution = $this->extractHiddenField($loginPageHtml, 'execution');
            $salt = $this->extractHiddenField($loginPageHtml, 'pwdEncryptSalt');

            if (empty($lt) || empty($execution)) {
                return [
                    'success' => false,
                    'student_name' => '',
                    'message' => '无法获取认证令牌，请稍后重试',
                ];
            }

            // Step 2: 加密密码
            $encryptedPassword = $this->encryptPassword($password, $salt);

            // Step 3: POST 登录
            $loginData = [
                'username' => $studentId,
                'password' => $encryptedPassword,
                'lt' => $lt,
                'execution' => $execution,
                '_eventId' => 'submit',
                'cllt' => 'userNameLogin',
                'dllt' => 'generalLogin',
                'rememberMe' => 'false',
            ];

            $loginResponse = $this->httpPost(
                self::LOGIN_URL,
                $loginData,
                ['Referer: ' . self::LOGIN_URL],
                $cookieFile
            );

            // Step 4: 检查登录结果
            if ($this->isLoginFailed($loginResponse)) {
                return [
                    'success' => false,
                    'student_name' => '',
                    'message' => '统一认证登录失败，请检查学号和密码',
                ];
            }

            // Step 5: 登录成功后获取学生信息
            $profileHtml = $this->httpGet(self::PROFILE_URL, [], $cookieFile);
            $studentName = $this->extractStudentName($profileHtml);

            if (empty($studentName)) {
                return [
                    'success' => false,
                    'student_name' => '',
                    'message' => '获取学生信息失败，请联系管理员',
                ];
            }

            return [
                'success' => true,
                'student_name' => $studentName,
                'message' => '验证通过',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'student_name' => '',
                'message' => '验证服务异常: ' . $e->getMessage(),
            ];
        } finally {
            @unlink($cookieFile);
        }
    }

    /**
     * 从 HTML 中提取隐藏字段值
     */
    private function extractHiddenField(string $html, string $name): string
    {
        if (preg_match('/name="' . preg_quote($name, '/') . '"\s+value="([^"]*)"/', $html, $matches)) {
            return $matches[1];
        }
        if (preg_match('/id="' . preg_quote($name, '/') . '"\s+value="([^"]*)"/', $html, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * 模拟前端 AES 加密密码
     * 使用 encrypt.js 中的算法对密码加密
     */
    private function encryptPassword(string $password, string $salt): string
    {
        if (empty($salt)) {
            return $password;
        }

        // 使用 AES-128-ECB 加密（常见方案）
        $key = substr($salt, 0, 16);
        $key = str_pad($key, 16, "\0");

        $encrypted = openssl_encrypt(
            $password,
            'AES-128-ECB',
            $key,
            OPENSSL_RAW_DATA
        );

        if ($encrypted === false) {
            return $password;
        }

        return base64_encode($encrypted);
    }

    /**
     * 判断登录是否失败
     */
    private function isLoginFailed(string $html): bool
    {
        return str_contains($html, '用户名或密码错误')
            || str_contains($html, '账号或密码错误')
            || str_contains($html, '登录失败')
            || str_contains($html, 'pwdFromId')
            || (str_contains($html, 'login') && !str_contains($html, '登录成功'));
    }

    /**
     * 从门户页面提取学生姓名
     */
    private function extractStudentName(string $html): string
    {
        if (preg_match('/姓名[：:]\s*([^\s<&]+)/u', $html, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/欢迎[你您][，,]?\s*([^\s<&,，!！]+)/u', $html, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/class="[^"]*user[^"]*name[^"]*"[^>]*>([^<]+)</', $html, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
}
