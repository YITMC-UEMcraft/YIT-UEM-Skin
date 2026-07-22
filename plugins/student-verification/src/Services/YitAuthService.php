<?php

namespace StudentVerification\Services;

class YitAuthService extends BaseAuthService
{
    private const BASE_URL = 'http://jw.yit.edu.cn/yjlgxy_jsxsd';
    private const LOGIN_URL = 'http://jw.yit.edu.cn/yjlgxy_jsxsd/xk/LoginToXk';
    private const PROFILE_URL = 'http://jw.yit.edu.cn/yjlgxy_jsxsd/xsxj/xsxjxx';

    public function getSchool(): string
    {
        return 'yit';
    }

    public function verify(string $studentId, string $password): array
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'yit_cookie_');

        try {
            // Step 1: Base64 编码登录
            $encodedUser = base64_encode($studentId);
            $encodedPass = base64_encode($password);
            $encodedValue = "{$encodedUser}%%%{$encodedPass}";

            $headers = [
                'Referer: ' . self::BASE_URL . '/',
            ];

            $response = $this->httpPost(
                self::LOGIN_URL,
                ['encoded' => $encodedValue],
                $headers,
                $cookieFile
            );

            // Step 2: 检查登录结果
            if (str_contains($response, '用户登录') && str_contains($response, 'userAccount')) {
                return [
                    'success' => false,
                    'student_name' => '',
                    'message' => '教务系统登录失败，请检查学号和密码',
                ];
            }

            // Step 3: 获取学生信息
            $profileHtml = $this->httpGet(self::PROFILE_URL, [
                'Referer: ' . self::BASE_URL . '/'
            ], $cookieFile);

            // Step 4: 从 HTML 中解析姓名
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
     * 从教务系统个人信息页面提取学生姓名
     */
    private function extractStudentName(string $html): string
    {
        // 模式1: "姓名" 标签后面跟着的值
        if (preg_match('/姓名[：:]\s*([^\s<&]+)/u', $html, $matches)) {
            return trim($matches[1]);
        }
        // 模式2: 在 table 中查找
        if (preg_match('/<td[^>]*>姓名<\/td>\s*<td[^>]*>([^<]+)<\/td>/u', $html, $matches)) {
            return trim($matches[1]);
        }
        // 模式3: 在 input value 中
        if (preg_match('/name="xm"[^>]*value="([^"]+)"/', $html, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
}
