<?php

namespace StudentVerification\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use StudentVerification\Models\VerificationCode;

class CodeAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:admin']);
    }

    /**
     * 邀请码管理页
     */
    public function index()
    {
        $codes = VerificationCode::with('user')->orderByDesc('id')->paginate(20);

        return view('StudentVerification::admin-codes', ['codes' => $codes]);
    }

    /**
     * 批量生成邀请码
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'count' => 'required|integer|min:1|max:100',
            'remark' => 'nullable|string|max:255',
            'expire_days' => 'nullable|integer|min:1|max:365',
        ]);

        $count = (int) $request->input('count');
        $remark = trim($request->input('remark', ''));
        $expiresAt = null;
        $days = (int) $request->input('expire_days');
        if ($days > 0) {
            $expiresAt = now()->addDays($days);
        }

        $created = 0;
        for ($i = 0; $i < $count; $i++) {
            $code = 'YITUEM-' . strtoupper(bin2hex(random_bytes(5)));
            VerificationCode::create([
                'code' => $code,
                'remark' => $remark,
                'created_by' => auth()->user()->uid,
                'expires_at' => $expiresAt,
                'revoked' => false,
            ]);
            $created++;
        }

        return redirect()->route('student-verification.admin-codes')
            ->with('success', '已生成 ' . $created . ' 个邀请码');
    }

    /**
     * 作废邀请码
     */
    public function revoke(int $id): RedirectResponse
    {
        $code = VerificationCode::findOrFail($id);
        $code->revoked = true;
        $code->save();

        return redirect()->route('student-verification.admin-codes');
    }
}