<?php

/**
 * 一键初始化站点配置（部署到新环境后运行一次）。
 *
 * 用法：php scripts/init-site.php
 *
 * 会设置：站点名称、描述、公告（HMCL/PCL 教程，认证地址自动取 .env 的 APP_URL）、
 * favicon、首页背景、启用 student-verification 与 yggdrasil-api 插件。
 *
 * 运行后仍需手动：生成 Yggdrasil 密钥对、创建管理员账号。
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$apiRoot = rtrim(config('app.url'), '/') . '/api/yggdrasil';
$siteName = 'YIT & UEM 联合皮肤站';
$description = '由燕理MC玩家创作协会与应大Minecraft同好会联合研发的 Minecraft 皮肤站';

$announcement = <<<MD
欢迎来到 {$siteName}！

本站面向燕京理工学院（YIT）与应急管理大学（UEM）在校学生开放。注册后请先在「用户中心 → 身份验证」中完成学生身份验证，之后即可创建角色、上传皮肤，并通过 Authlib-Injector 使用 Yggdrasil 外置登录进入 MUA Union 联合认证服务器。

### HMCL 启动器设置

1. 点击左上角「账户」，点击左下角「添加认证服务器」；
2. 输入认证服务器地址：{$apiRoot}，点击「下一步」完成；
3. 点击左侧新创建的认证服务器，输入用户名和密码，即可登录。

### PCL 启动器设置

1. 依次打开：[启动游戏] → 下方 [版本设置] → 左侧 [设置] → 页面下方 [服务器选项]；
2. 登录方式：第三方登录 Authlib-Injector；
3. 认证服务器：{$apiRoot}；
4. 服务器名称：YIT&UEM联合认证；
5. 添加完成后返回主页，在启动游戏时登录。
MD;

option([
    'site_name'             => $siteName,
    'site_name_zh_CN'       => $siteName,
    'site_description_zh_CN' => $description,
    'announcement'          => $announcement,
    'announcement_zh_CN'    => $announcement,
    'favicon_url'           => 'app/favicon.png',
    'home_pic_url'          => 'app/bg/1.webp',
    'plugins_enabled'       => json_encode([
        ['name' => 'student-verification', 'version' => '1.0.0'],
        ['name' => 'yggdrasil-api', 'version' => '5.2.1'],
    ], JSON_UNESCAPED_UNICODE),
    'copyright_text'        => '<b>Copyright &copy; 2026 <a href="{site_url}">{site_name}</a>.</b> All rights reserved. <a href="/privacy">隐私协议</a>',
]);

echo "✓ 站点初始化完成" . PHP_EOL;
echo "  站点名称：{$siteName}" . PHP_EOL;
echo "  认证服务器地址：{$apiRoot}" . PHP_EOL;
echo "  已启用插件：student-verification、yggdrasil-api" . PHP_EOL;
echo PHP_EOL;
echo "请继续手动完成：" . PHP_EOL;
echo "1. 后台 → 插件管理 → Yggdrasil API → 配置 → 点击「生成密钥对」（换机/新部署必须）" . PHP_EOL;
echo "2. 创建管理员账号，或把已有账号 permission 设为 2（超级管理员）" . PHP_EOL;
echo "3. 刷新配置缓存：php artisan options:cache" . PHP_EOL;