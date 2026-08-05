<?php

namespace StudentVerification\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use StudentVerification\Models\StudentVerification;
use StudentVerification\Services\YitAuthService;
use StudentVerification\Services\UemAuthService;
use StudentVerification\Services\UemJwAuthService;
use StudentVerification\Services\UemQrService;

class VerificationController extends Controller
{
    private YitAuthService $yitAuth;

    private UemAuthService $uemAuth;

    private UemJwAuthService $uemJwAuth;

    private UemQrService $uemQr;

    public function __construct(YitAuthService $yitAuth, UemAuthService $uemAuth, UemJwAuthService $uemJwAuth, UemQrService $uemQr)
    {
        $this->yitAuth = $yitAuth;
        $this->uemAuth = $uemAuth;
        $this->uemJwAuth = $uemJwAuth;
        $this->uemQr = $uemQr;
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

        // 选择认证服务（UEM 备用密码通道走教务系统直连，统一认证受滑块验证码限制）
        $authService = $school === 'yit' ? $this->yitAuth : $this->uemJwAuth;

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
     * UEM：创建扫码验证会话，返回二维码图片地址。
     */
    public function uemQrCreate(Request $request): JsonResponse
    {
        $studentId = trim($request->input('student_id', ''));
        $studentName = trim($request->input('student_name', ''));

        if ($studentId === '' || $studentName === '') {
            return response()->json(['success' => false, 'message' => '请先填写学号和姓名']);
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
            'student_name' => $studentName,
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
        $pendingName = $qr['student_name'];

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

        $studentName = $identity['student_name'] !== '' ? $identity['student_name'] : $pendingName;

        StudentVerification::updateOrCreate(
            ['user_id' => auth()->user()->uid],
            [
                'school' => 'uem',
                'student_id' => $pendingId,
                'student_name' => $studentName,
                'verified' => true,
                'verified_at' => now(),
            ]
        );

        @unlink($jar);
        session()->forget('uem_qr');

        return response()->json([
            'status' => 'success',
            'message' => '验证通过！欢迎，' . $studentName,
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
            'student_name' => $verification ? $verification->student_name : null,
            'student_id' => $verification ? $verification->student_id : null,
            'school' => $verification ? $verification->school : null,
            'verified_at' => $verification ? optional($verification->verified_at)->toDateTimeString() : null,
        ]);
    }
}