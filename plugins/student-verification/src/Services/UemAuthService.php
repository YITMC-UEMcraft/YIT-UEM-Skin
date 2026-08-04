<?php

namespace StudentVerification\Services;

/**
 * 应急管理大学（原华北科技学院）统一身份认证服务。
 *
 * 对接 auth.ncist.edu.cn（金智 Wisedu 统一认证平台）。
 * 加密算法与登录页 encrypt.js 保持一致：
 *   - AES-128-CBC / PKCS7
 *   - key  = pwdEncryptSalt（登录页动态下发）
 *   - iv   = 随机 16 字符
 *   - 明文 = 随机 64 字符 + 真实密码
 * 密码只用于本次请求，不落库。
 *
 * 注意：该校统一认证在部分场景下要求图形/滑块验证码（captchaSwitch=2），
 * 此时无法纯服务端自动验证，本服务会如实返回提示，而不会伪造验证成功。
 */
class UemAuthService extends BaseAuthService
{
    private const LOGIN_URL = 'https://auth.ncist.edu.cn/authserver/login';

    private const INDEX_URL = 'https://auth.ncist.edu.cn/authserver/index.do';

    /** 学校信息门户（登录后可从中解析姓名） */
    private const PORTAL_URL = 'https://my.ncist.edu.cn/';

    /** encrypt.js 中的随机字符串字符集 */
    private const RANDOM_CHARS = 'ABCDEFGHJKMNPQRSTWXYZabcdefhijkmnprstwxyz2345678';

    public function getSchool(): string
    {
        return 'uem';
    }

    public function verify(string $studentId, string $password): array
    {
        $studentId = trim($studentId);
        if ($studentId === '' || $password === '') {
            return $this->fail('请输入学号和密码');
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'uem_cookie_');

        try {
            // Step 1: 读取登录页，获取 execution 与动态盐值
            $loginPage = $this->httpGet(self::LOGIN_URL, [], $cookieFile);
            $execution = $this->extractHiddenField($loginPage, 'execution');
            $salt = $this->extractHiddenField($loginPage, 'pwdEncryptSalt');

            if ($execution === '' || $salt === '') {
                return $this->fail('无法获取认证令牌，请稍后重试');
            }

            // Step 2: 加密密码并提交登录
            $encryptedPassword = $this->encryptPassword($password, $salt);
            $loginData = [
                'username' => $studentId,
                'password' => $encryptedPassword,
                'lt' => '',
                'execution' => $execution,
                '_eventId' => 'submit',
                'cllt' => 'userNameLogin',
                'dllt' => 'generalLogin',
                'rmShown' => 'true',
            ];

            $loginResponse = $this->httpPost(
                self::LOGIN_URL,
                $loginData,
                ['Referer: ' . self::LOGIN_URL],
                $cookieFile
            );

            // Step 3: 判定登录结果
            $failedMessage = $this->loginFailureMessage($loginResponse);
            if ($failedMessage !== null) {
                return $this->fail($failedMessage);
            }
            if ($this->isStillOnLoginPage()) {
                return $this->fail('统一认证登录失败，请检查学号和密码');
            }

            // Step 4: 拉取个人信息页，解析真实姓名
            $name = $this->fetchStudentName($cookieFile);
            if ($name === '') {
                return $this->fail('登录成功，但未能获取个人信息，请稍后重试或联系管理员');
            }

            return [
                'success' => true,
                'student_name' => $name,
                'message' => '验证通过',
            ];
        } catch (\Exception $e) {
            return $this->fail($this->friendlyError($e));
        } finally {
            @unlink($cookieFile);
        }
    }

    /**
     * 从登录响应中识别失败原因；未识别到失败则返回 null。
     */
    private function loginFailureMessage(string $html): ?string
    {
        $patterns = [
            '用户名或密码错误' => '学号或密码错误',
            '账号或密码错误' => '学号或密码错误',
            '用户名或密码不正确' => '学号或密码错误',
            '密码错误' => '学号或密码错误',
            '账号不存在' => '账号不存在，请检查学号',
            '用户不存在' => '账号不存在，请检查学号',
            '被锁定' => '账号已被锁定，请联系学校信息中心',
            '已锁定' => '账号已被锁定，请联系学校信息中心',
            '请输入验证码' => '该账号需要图形验证码，请稍后再试或使用浏览器登录',
            '图形验证码错误' => '图形验证码错误，请稍后再试',
            '图形动态码错误' => '该账号需要图形验证码，请稍后再试或使用浏览器登录',
            '动态码错误' => '动态验证码错误，请稍后再试',
        ];

        foreach ($patterns as $needle => $message) {
            if (str_contains($html, $needle)) {
                return $message;
            }
        }

        // 兜底：读取登录页错误提示条中的原文
        if (preg_match('/showErrorTip[^>]*>\s*<span>([^<]+)<\/span>/u', $html, $matches)) {
            $tip = trim($matches[1]);
            if ($tip !== '') {
                return $tip;
            }
        }

        return null;
    }

    /**
     * 登录后是否仍停留在登录页（说明登录未成功）。
     */
    private function isStillOnLoginPage(): bool
    {
        $url = $this->lastEffectiveUrl();

        return $url !== null && str_ends_with($url, '/login');
    }

    /**
     * 依次尝试认证平台首页与学校门户，解析学生真实姓名。
     */
    private function fetchStudentName(string $cookieFile): string
    {
        $pages = [
            self::INDEX_URL,
            self::PORTAL_URL,
        ];

        foreach ($pages as $page) {
            try {
                $html = $this->httpGet($page, [], $cookieFile);
            } catch (\Exception $e) {
                continue;
            }

            $name = $this->extractStudentName($html);
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * 从 HTML 中提取隐藏字段值。
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
     * 模拟登录页 encrypt.js 的 AES 加密。
     */
    private function encryptPassword(string $password, string $salt): string
    {
        $salt = trim($salt);
        if ($salt === '') {
            return $password;
        }

        $iv = $this->randomString(16);
        $plain = $this->randomString(64) . $password;
        $encrypted = openssl_encrypt($plain, 'AES-128-CBC', $salt, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            return $password;
        }

        return base64_encode($encrypted);
    }

    /**
     * 生成与 encrypt.js randomString 同字符集的随机字符串。
     */
    private function randomString(int $length): string
    {
        $result = '';
        $max = strlen(self::RANDOM_CHARS) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= self::RANDOM_CHARS[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * 从页面中提取学生姓名。
     */
    private function extractStudentName(string $html): string
    {
        $patterns = [
            // 内嵌 JSON：{"displayName":"张三", ...}
            '/"displayName"\s*:\s*"([^"]+)"/',
            '/"realName"\s*:\s*"([^"]+)"/',
            '/"name"\s*:\s*"([^"]{2,20})"/',
            // 欢迎语：欢迎您，张三 / 你好，张三
            '/欢迎(?:您|你)?[，,\s]*([\x{4e00}-\x{9fa5}]{2,10})/u',
            '/你好[，,\s]*([\x{4e00}-\x{9fa5}]{2,10})/u',
            '/您好[，,\s]*([\x{4e00}-\x{9fa5}]{2,10})/u',
            // 个人中心表格：<td>姓名</td><td>张三</td>
            '/<td[^>]*>姓名<\/td>\s*<td[^>]*>([^<]{2,10})<\/td>/u',
            '/姓名[：:]\s*([\x{4e00}-\x{9fa5}]{2,10})/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $name = trim($matches[1]);
                $name = preg_replace('/[\s\x{3000}]/u', '', $name) ?? $name;
                if ($name !== '' && !str_contains($name, 'json') && !str_contains($name, 'function')) {
                    return $name;
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