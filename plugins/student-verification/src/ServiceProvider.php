<?php

namespace StudentVerification;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        // 自动创建数据库表（如果不存在）
        if (!Schema::hasTable('student_verifications')) {
            Schema::create('student_verifications', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->unique();
                $table->string('school', 16);       // 'yit' | 'uem'
                $table->string('student_id', 32);    // 学号
                $table->string('student_name', 64);  // 姓名
                $table->boolean('verified')->default(false);
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();

                $table->index('school');
                $table->index('verified');
            });
        }


        // 邀请码表（管理员发放给外校人员的验证码）
        if (!Schema::hasTable('verification_codes')) {
            Schema::create('verification_codes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('code', 32)->unique();
                $table->string('school', 16)->default('external');
                $table->string('remark', 255)->default('');
                $table->unsignedInteger('created_by');
                $table->unsignedInteger('used_by')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('revoked')->default(false);
                $table->timestamps();

                $table->index('revoked');
            });
        }

        // 兼容旧表：补充学校字段
        if (Schema::hasTable('verification_codes') && !Schema::hasColumn('verification_codes', 'school')) {
            Schema::table('verification_codes', function (Blueprint $table) {
                $table->string('school', 16)->default('external')->after('code');
            });
        }
        // 注册视图命名空间
        $this->loadViewsFrom(
            dirname(__DIR__) . '/views',
            'StudentVerification'
        );

        // 注册语言文件
        $this->loadTranslationsFrom(
            dirname(__DIR__) . '/lang',
            'StudentVerification'
        );
    }
}
