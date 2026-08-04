<?php

use App\Events\PluginWasDeleted;
use App\Events\PluginWasDisabled;
use App\Events\PluginWasEnabled;
use Illuminate\Support\Facades\Schema;

return [
    PluginWasEnabled::class => function () {
        // 确保数据库表存在
        if (!Schema::hasTable('student_verifications')) {
            Schema::create('student_verifications', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->unique();
                $table->string('school', 16);
                $table->string('student_id', 32);
                $table->string('student_name', 64);
                $table->boolean('verified')->default(false);
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }
    },

    PluginWasDisabled::class => function () {
        // 保留数据库表（不删除用户验证数据）
    },

    PluginWasDeleted::class => function () {
        // 清理数据库表
        Schema::dropIfExists('student_verifications');
    },
];