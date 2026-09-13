<?php

namespace StudentVerification\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use StudentVerification\Models\StudentVerification;

class VerifiedStudent
{
    /**
     * 需要校验学生身份的 Yggdrasil 接口：
     * authenticate / refresh / validate 负责登录与令牌续期，
     * sessionserver join 是进服时的权威校验。
     */
    private const YGGDRASIL_GATE_PATHS = [
        'api/yggdrasil/authserver/authenticate',
        'api/yggdrasil/authserver/refresh',
        'api/yggdrasil/authserver/validate',
        'api/yggdrasil/sessionserver/session/minecraft/join',
    ];

    public function handle(Request $request, Closure $next)
    {
        $path = $request->path();

        // 未完成学生身份验证的账号不允许通过 Yggdrasil 外置登录进入游戏
        if (in_array($path, self::YGGDRASIL_GATE_PATHS, true)) {
            $blocked = $this->enforceStudentVerification($request, $path);

            if ($blocked !== null) {
                return $blocked;
            }
        }

        $protectedPaths = [
            'user/player',
            'user/closet',
            'skinlib/upload',
        ];

        $needsProtection = false;
        foreach ($protectedPaths as $protected) {
            if (str_starts_with($path, $protected)) {
                $needsProtection = true;
                break;
            }
        }

        if ($needsProtection && auth()->check()) {
            $verified = StudentVerification::isUserVerified(auth()->user()->uid);
            if (!$verified) {
                if ($request->expectsJson() || $request->isMethod('POST')) {
                    return response()->json([
                        'code' => 1,
                        'message' => trans('StudentVerification::student-verification.verify_required'),
                    ], 403);
                }
                return redirect()->route('student-verification.page');
            }
        }

        return $next($request);
    }

    /**
     * 未完成学生身份验证的账号不允许通过 Yggdrasil 登录或进服。
     *
     * @return \Illuminate\Http\JsonResponse|null 拦截时返回响应，放行时返回 null
     */
    private function enforceStudentVerification(Request $request, string $path)
    {
        $uid = $path === 'api/yggdrasil/authserver/authenticate'
            ? $this->resolveUidFromCredentials($request)
            : $this->resolveUidFromProfile($request);

        if ($uid === null || StudentVerification::isUserVerified($uid)) {
            return null;
        }

        return response()->json([
            'error' => 'ForbiddenOperationException',
            'errorMessage' => trans('StudentVerification::student-verification.verify_required'),
        ], 403);
    }

    /**
     * authenticate 请求：只有密码正确时才用「未完成身份验证」拦住，
     * 避免用任意密码探测某个邮箱或角色名是否已注册。
     */
    private function resolveUidFromCredentials(Request $request): ?int
    {
        $identification = $request->input('username');
        if (!is_string($identification) || $identification === '') {
            return null;
        }

        if (filter_var($identification, FILTER_VALIDATE_EMAIL)) {
            $uid = DB::table('users')->where('email', $identification)->value('uid');
        } else {
            $uid = DB::table('players')->where('name', $identification)->value('uid');
        }

        if ($uid === null) {
            return null;
        }

        if (StudentVerification::isUserVerified((int) $uid)) {
            // 已通过验证的账号直接交给 Yggdrasil 插件处理，避免重复校验密码
            return null;
        }

        /** @var User|null $user */
        $user = User::find($uid);
        if ($user === null || !$user->verifyPassword((string) $request->input('password'))) {
            // 密码不正确时交给 Yggdrasil 插件返回统一的登录失败信息
            return null;
        }

        return (int) $uid;
    }

    /**
     * join / refresh / validate 请求：用请求里的角色 UUID 反查账号。
     */
    private function resolveUidFromProfile(Request $request): ?int
    {
        $profile = $request->input('selectedProfile');
        if (is_array($profile)) {
            $profile = $profile['id'] ?? null;
        }

        if (!is_string($profile) || $profile === '') {
            return null;
        }

        $uuid = strtolower(str_replace('-', '', $profile));
        $name = DB::table('uuid')->where('uuid', $uuid)->value('name');
        if ($name === null) {
            return null;
        }

        $uid = DB::table('players')->where('name', $name)->value('uid');

        return $uid === null ? null : (int) $uid;
    }
}
