<?php

namespace StudentVerification\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $school        'yit' | 'uem'
 * @property string $student_id    学号
 * @property string $student_name  姓名
 * @property bool $verified
 * @property string|null $verified_at
 */
class StudentVerification extends Model
{
    protected $table = 'student_verifications';

    protected $fillable = [
        'user_id',
        'school',
        'student_id',
        'student_name',
        'verified',
        'verified_at',
    ];

    protected $casts = [
        'verified' => 'bool',
        'verified_at' => 'datetime',
    ];

    /**
     * 检查用户是否已验证
     */
    public static function isUserVerified(int $userId): bool
    {
        return (bool) static::where('user_id', $userId)->value('verified');
    }

    /**
     * 获取用户的验证记录
     */
    public static function forUser(int $userId): ?self
    {
        return static::where('user_id', $userId)->first();
    }
}
