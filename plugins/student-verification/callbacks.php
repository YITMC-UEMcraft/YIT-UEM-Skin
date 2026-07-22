<?php

use App\Models\Plugin;
use Illuminate\Support\Facades\Schema;

return [
    'enable' => function (Plugin $plugin) {
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

    'disable' => function (Plugin $plugin) {
        // 保留数据库表（不删除用户验证数据）
    },

    'delete' => function (Plugin $plugin) {
        Schema::dropIfExists('student_verifications');
    },
];
