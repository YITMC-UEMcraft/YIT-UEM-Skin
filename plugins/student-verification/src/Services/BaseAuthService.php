<?php

namespace StudentVerification\Services;

abstract class BaseAuthService
{
    /** 最近一次请求最终跳转到的 URL */
    protected ?string $effectiveUrl = null;

    /** 最近一次请求的 HTTP 状态码 */
    protected ?int $lastStatusCode = null;

    /**
     * 验证学生身份
     * @param string $studentId 学号
     * @param string $password 密码
     * @return array{success: bool, student_name: string, message: string}
     */
    abstract public function verify(string $studentId, string $password): array;

    /**
     * 获取学校标识
     */
    abstract public function getSchool(): string;

    /**
     * 最近一次请求最终跳转到的 URL（跟随重定向后）
     */
    protected function lastEffectiveUrl(): ?string
    {
        return $this->effectiveUrl;
    }

    /**
     * HTTP GET 请求
     */
    protected function httpGet(string $url, array $headers = [], $cookieJar = null): string
    {
        return $this->request($url, $headers, $cookieJar);
    }

    /**
     * HTTP POST 请求
     */
    protected function httpPost(string $url, array $data, array $headers = [], $cookieJar = null): string
    {
        return $this->request($url, $headers, $cookieJar, $data);
    }

    /**
     * 发起 cURL 请求并跟踪最终 URL。
     */
    private function request(string $url, array $headers = [], $cookieJar = null, ?array $data = null): string
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/x-www-form-urlencoded',
            ], $headers),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
        ];

        if ($data !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($data);
        }

        if ($cookieJar) {
            $options[CURLOPT_COOKIEFILE] = $cookieJar;
            $options[CURLOPT_COOKIEJAR] = $cookieJar;
        }

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $this->effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $this->lastStatusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("HTTP request failed: {$error}");
        }

        return $result ?: '';
    }
}