<?php

namespace StudentVerification\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use StudentVerification\Models\StudentVerification;
use StudentVerification\Services\UemQrService;
use StudentVerification\Services\YitAuthService;

class VerificationController extends Controller
{
    private YitAuthService $yitAuth;

    private UemQrService $uemQr;

    public function __construct(YitAuthService $yitAuth, UemQrService $uemQr)
    {
        $this->yitAuth = $yitAuth;
        $this->uemQr = $uemQr;
        $this->middleware('auth')->except('privacy');
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
            'school' => $verification ? $verification->school : null,
        ]);
    }

    /**
     * 处理验证请求（AJAX，仅燕京理工学院教务系统直连）
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
            ]);
        }

        $school = $request->input('school');

        // UEM 仅支持扫码验证
        if ($school === 'uem') {
            return response()->json([
                'success' => false,
                'message' => '应急管理大学请使用「扫码验证」方式',
            ]);
        }

        $studentId = trim($request->input('student_id'));
        $password = $request->input('password');

        // 执行认证（密码仅在该请求内使用）
        $result = $this->yitAuth->verify($studentId, $password);

        if ($result['success']) {
            // 保存验证记录（不保存密码）
            StudentVerification::updateOrCreate(
                ['user_id' => $user->uid],
                [
                    'school' => 'yit',
                    'student_id' => $studentId,
                    'student_name' => '',
                    'verified' => true,
                    'verified_at' => now(),
                ]
            );

            $result['message'] = '验证通过';
        }

        return response()->json($result);
    }

    /**
     * UEM：创建扫码验证会话，返回二维码图片地址。
     */
    public function uemQrCreate(Request $request): JsonResponse
    {
        $studentId = trim($request->input('student_id', ''));

        if ($studentId === '') {
            return response()->json(['success' => false, 'message' => '请先填写学号']);
        }

        $jarDir = storage_path('framework/uem-qr');
        if (!is_dir($jarDir)) {
            @mkdir($jarDir, 0775, true);
        }
        $jar = $jarDir . '/qr_' . bin2hex(random_bytes(8)) . '.txt';

        try {
            $result = $this->uemQr->create($jar);
        } catch (\Exception $e) {
            @unlink($jar);

            return response()->json(['success' => false, 'message' => '无法连接学校认证系统，请稍后重试']);
        }

        if (!$result['success']) {
            @unlink($jar);

            return response()->json(['success' => false, 'message' => '二维码创建失败，请稍后重试']);
        }

        session(['uem_qr' => [
            'jar' => $jar,
            'uuid' => $result['uuid'],
            'student_id' => $studentId,
        ]]);

        return response()->json([
            'success' => true,
            'uuid' => $result['uuid'],
            'image' => $result['image'],
        ]);
    }

    /**
     * UEM：轮询扫码状态；确认后完成登录并核验身份。
     */
    public function uemQrStatus(Request $request): JsonResponse
    {
        $qr = session('uem_qr', []);
        if (empty($qr['jar']) || empty($qr['uuid'])) {
            return response()->json(['status' => 'expired', 'message' => '二维码会话已失效，请重新生成']);
        }

        $jar = $qr['jar'];
        $uuid = $qr['uuid'];

        if (!is_file($jar)) {
            session()->forget('uem_qr');

            return response()->json(['status' => 'expired', 'message' => '二维码会话已失效，请重新生成']);
        }

        try {
            $status = $this->uemQr->status($jar, $uuid);
        } catch (\Exception $e) {
            return response()->json(['status' => 'pending', 'message' => '网络异常，正在重试…']);
        }

        // 2 = 已扫描待确认
        if ($status === '2') {
            return response()->json(['status' => 'scanned', 'message' => '已扫描，请在手机上确认登录']);
        }

        // 3 = 失效
        if ($status === '3') {
            @unlink($jar);
            session()->forget('uem_qr');

            return response()->json(['status' => 'expired', 'message' => '二维码已失效，请刷新重试']);
        }

        // 0 = 未扫描
        if ($status !== '1') {
            return response()->json(['status' => 'pending', 'message' => '等待扫码…']);
        }

        // 1 = 已在手机上确认，完成登录
        $completed = false;
        try {
            $completed = $this->uemQr->complete($jar, $uuid);
            $identity = $completed ? $this->uemQr->fetchIdentity($jar) : ['student_id' => '', 'student_name' => ''];
        } catch (\Exception $e) {
            $identity = ['student_id' => '', 'student_name' => ''];
        }

        $pendingId = $qr['student_id'];

        if (!$completed) {
            @unlink($jar);
            session()->forget('uem_qr');

            return response()->json(['status' => 'error', 'message' => '登录未完成，请重新扫码']);
        }

        // 若能解析出会话账号，必须与填写的学号一致
        if ($identity['student_id'] !== '' && strcasecmp($identity['student_id'], $pendingId) !== 0) {
            @unlink($jar);
            session()->forget('uem_qr');

            return response()->json(['status' => 'error', 'message' => '扫码登录的账号与填写的学号不一致']);
        }

        StudentVerification::updateOrCreate(
            ['user_id' => auth()->user()->uid],
            [
                'school' => 'uem',
                'student_id' => $pendingId,
                'student_name' => '',
                'verified' => true,
                'verified_at' => now(),
            ]
        );

        @unlink($jar);
        session()->forget('uem_qr');

        return response()->json([
            'status' => 'success',
            'message' => '验证通过',
        ]);
    }


    /**
     * 隐私协议页面
     */
    public function privacy()
    {
        return view('StudentVerification::privacy', [
            'siteName' => option_localized('site_name'),
        ]);
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
            'school' => $verification ? $verification->school : null,
        ]);
    }
}