<?php

/**
 * 创建或提升超级管理员（部署到新环境后使用）。
 *
 * 用法：
 *   php scripts/create-admin.php <邮箱> <密码>    创建新超级管理员
 *   php scripts/create-admin.php <邮箱> [新密码]  提升已有账号为超级管理员（可选重置密码）
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Carbon\Carbon;

$args = array_slice($argv, 1);
if (count($args) < 1 || count($args) > 2) {
    fwrite(STDERR, "用法：php scripts/create-admin.php <邮箱> [密码]\n");
    exit(1);
}
$email = $args[0];
$password = $args[1] ?? null;

$user = User::where('email', $email)->first();

if ($user) {
    if ($password) {
        $user->password = app('cipher')->hash($password, config('secure.salt'));
    }
    $user->permission = User::SUPER_ADMIN;
    $user->save();
    echo "已将 {$email} 提升为超级管理员（uid={$user->uid}）\n";
} else {
    if (!$password) {
        fwrite(STDERR, "新账号需要提供密码：php scripts/create-admin.php <邮箱> <密码>\n");
        exit(1);
    }
    $user = new User();
    $user->email = $email;
    $user->nickname = $email;
    $user->score = 1000;
    $user->avatar = 0;
    $user->permission = User::SUPER_ADMIN;
    $user->ip = '127.0.0.1';
    $user->password = app('cipher')->hash($password, config('secure.salt'));
    $user->register_at = Carbon::now();
    $user->last_sign_at = Carbon::now()->subDay();
    $user->save();
    echo "已创建超级管理员：{$email}（uid={$user->uid}）\n";
}

echo "登录后可在后台「用户管理」中添加其他管理员。\n";