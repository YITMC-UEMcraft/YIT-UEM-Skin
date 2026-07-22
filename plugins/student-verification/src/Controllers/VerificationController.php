<?php

namespace StudentVerification\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
        ]);
    }

    /**
     * 处理验证请求（AJAX）
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'school' => 'required|in:yit,uem',
            'student_id' => 'required|string|max:32',
            'password' => 'required|string|max:64',
        ]);

        $user = auth()->user();

        // 检查是否已验证
        $existing = StudentVerification::forUser($user->uid);
        if ($existing && $existing->verified) {
            return response()->json([
                'success' => true,
                'message' => '您已经通过验证',
                'student_name' => $existing->student_name,
            ]);
        }

        $school = $request->input('school');
        $studentId = $request->input('student_id');
        $password = $request->input('password');

        // 选择认证服务
        $authService = $school === 'yit' ? $this->yitAuth : $this->uemAuth;

        // 执行认证
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
        }

        return response()->json($result);
    }

    /**
     * 检查验证状态（AJAX）
     */
    public function status(): JsonResponse
    {
        $user = auth()->user();
        $verification = StudentVerification::forUser($user->uid);

        return response()->json([
            'verified' => $verification ? $verification->verified : false,
            'student_name' => $verification ? $verification->student_name : null,
            'student_id' => $verification ? $verification->student_id : null,
            'school' => $verification ? $verification->school : null,
        ]);
    }
}
