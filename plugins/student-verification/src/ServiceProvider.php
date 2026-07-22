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
