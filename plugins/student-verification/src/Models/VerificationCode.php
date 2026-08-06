<?php

namespace StudentVerification\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 管理员发放的邀请码（用于校友/外校人员验证）。
 *
 * @property int $id
 * @property string $code
 * @property string $school      'yit' | 'uem' | 'external'
 * @property string $remark
 * @property int $created_by
 * @property int|null $used_by
 * @property string|null $used_at
 * @property string|null $expires_at
 * @property bool $revoked
 */
class VerificationCode extends Model
{
    protected $table = 'verification_codes';

    protected $fillable = [
        'code',
        'school',
        'remark',
        'created_by',
        'used_by',
        'used_at',
        'expires_at',
        'revoked',
    ];

    protected $casts = [
        'revoked' => 'bool',
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * 使用该邀请码的用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by', 'uid');
    }

    /**
     * 邀请码是否仍可使用
     */
    public function isUsable(): bool
    {
        if ($this->revoked) {
            return false;
        }
        if ($this->used_by !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->lt(now())) {
            return false;
        }

        return true;
    }
}