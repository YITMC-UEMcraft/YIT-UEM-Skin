<?php

namespace StudentVerification\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use StudentVerification\Models\StudentVerification;
use StudentVerification\Services\YitAuthService;
use StudentVerification\Services\UemAuthService;

class VerificationController extends Controller
{
    private YitAuthService $yitAuth;

    private UemAuthService $uemAuth;

    public function __construct(YitAuthService $yitAuth, UemAuthService $uemAuth)
    {
        $this->yitAuth = $yitAuth;
        $this->uemAuth = $uemAuth;
        $this->middleware('auth');
        // 防止对学校认证系统进行暴力尝试
        $this->middleware('throttle:5,1')->only('verify');
    }

    /**
     * 显示验证页面
     */
    public function showVerifyPage()
    {
        $user = auth()->user();
        $verification = StudentVerification::forUser($user->uid);

        return view('StudentVerification::verify-page', [
            'verified' => $verification ? $verification->verified : false,
            'studentName' => $verification ? $verification->student_name : null,
            'studentId' => $verification ? $verification->student_id : null,
            'school' => $verification ? $verification->school : null,
            'verifyError' => session('verify_error'),
            'verifySuccess' => session('verify_success'),
        ]);
    }

    /**
     * 处理验证请求（AJAX，服务端代理登录）
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'school' => 'required|in:yit,uem',
            'student_id' => 'required|string|max:32',
            'password' => 'required|string|max:64',
        ]);

        $user = auth()->user();

        // 已通过验证的用户无需重复验证（每人只能绑定一个学校，不可更改）
        $existing = StudentVerification::forUser($user->uid);
        if ($existing && $existing->verified) {
            return response()->json([
                'success' => true,
                'message' => '您已经通过验证',
                'student_name' => $existing->student_name,
            ]);
        }

        $school = $request->input('school');
        $studentId = trim($request->input('student_id'));
        $password = $request->input('password');

        // 选择认证服务
        $authService = $school === 'yit' ? $this->yitAuth : $this->uemAuth;

        // 执行认证（密码仅在该请求内使用）
        $result = $authService->verify($studentId, $password);

        if ($result['success']) {
            // 保存验证记录（不保存密码）
            StudentVerification::updateOrCreate(
                ['user_id' => $user->uid],
                [
                    'school' => $school,
                    'student_id' => $studentId,
                    'student_name' => $result['student_name'],
                    'verified' => true,
                    'verified_at' => now(),
                ]
            );

            $result['message'] = '验证通过！欢迎，' . $result['student_name'];
        }

        return response()->json($result);
    }

    /**
     * UEM：跳转到学校统一认证（CAS）登录页
     *
     * 用户在自己的浏览器里完成登录（可复用已登录会话，秒过），
     * 学校回跳 callback 后由我们校验票据，全程不经过本站服务器代理密码。
     */
    public function uemLogin(Request $request)
    {
        $studentId = trim($request->query('student_id', ''));
        $studentName = trim($request->query('student_name', ''));

        if ($studentId === '' || $studentName === '') {
            return redirect()->route('student-verification.page');
        }

        // 把用户声称的学号/姓名暂存，回调时与 CAS 返回的学号交叉核对
        session(['uem_pending' => [
            'student_id' => $studentId,
            'student_name' => $studentName,
        ]]);

        $service = route('student-verification.uem-callback', [], true);

        return redirect()->away(
            'https://auth.ncist.edu.cn/authserver/login?service=' . urlencode($service)
        );
    }

    /**
     * UEM：CAS 回调，校验票据并完成验证
     */
    public function uemCallback(Request $request)
    {
        $ticket = trim($request->query('ticket', ''));
        $pending = session()->pull('uem_pending', []);

        if ($ticket === '' || empty($pending['student_id'])) {
            return redirect()->route('student-verification.page')
                ->with('verify_error', '验证未完成，请重新点击「前往学校统一认证」');
        }

        $service = route('student-verification.uem-callback', [], true);

        try {
            $response = Http::timeout(20)->get(
                'https://auth.ncist.edu.cn/authserver/serviceValidate',
                [
                    'service' => $service,
                    'ticket' => $ticket,
                ]
            );
        } catch (\Exception $e) {
            return redirect()->route('student-verification.page')
                ->with('verify_error', '无法连接学校认证系统，请稍后重试');
        }

        $xml = $response->body();
        if (!preg_match('/<cas:user>([^<]+)<\/cas:user>/', $xml, $matches)) {
            return redirect()->route('student-verification.page')
                ->with('verify_error', '统一认证验证失败，请重试');
        }

        $casUsername = trim($matches[1]);
        if (strcasecmp($casUsername, $pending['student_id']) !== 0) {
            return redirect()->route('student-verification.page')
                ->with('verify_error', '统一认证账号与填写的学号不一致，请检查后重试');
        }

        // 若学校 CAS 配置了属性释放，优先使用返回的真实姓名
        $studentName = $pending['student_name'];
        if (preg_match('/<cas:attribute\s+name="(?:displayName|realName|姓名|xm)"[^>]*>([^<]+)<\/cas:attribute>/', $xml, $nameMatches)) {
            $studentName = trim($nameMatches[1]);
        }

        StudentVerification::updateOrCreate(
            ['user_id' => auth()->user()->uid],
            [
                'school' => 'uem',
                'student_id' => $pending['student_id'],
                'student_name' => $studentName,
                'verified' => true,
                'verified_at' => now(),
            ]
        );

        return redirect()->route('student-verification.page')
            ->with('verify_success', '验证通过！欢迎，' . $studentName);
    }

    /**
     * 检查验证状态（AJAX）
     */
    public function status(): JsonResponse
    {
        $user = auth()->user();
        $verification = StudentVerification::forUser($user->uid);

        return response()->json([
            'verified' => $verification ? (bool) $verification->verified : false,
            'student_name' => $verification ? $verification->student_name : null,
            'student_id' => $verification ? $verification->student_id : null,
            'school' => $verification ? $verification->school : null,
            'verified_at' => $verification ? optional($verification->verified_at)->toDateTimeString() : null,
        ]);
    }
}