# YIT & UEM 联合皮肤站

基于 [Blessing Skin Server](https://github.com/bs-community/blessing-skin-server)（v6.0.2，Laravel 10）构建的 Minecraft 联合皮肤站，面向 **燕京理工学院（YIT）** 与 **应急管理大学（UEM）** 的在校学生开放，并已为接入 **MUA Union 联合认证** 做好准备。

## 功能特性

- **学生身份真实验证**：创建角色、上传皮肤前必须通过学校系统验证在校生身份，验证不是手动填写、而是真实调用学校系统：
  - 燕京理工学院（YIT）：调用教务系统 `jw.yit.edu.cn` 登录并核对学籍信息（姓名 + 学号）
  - 应急管理大学（UEM）：扫码验证（学校 App 扫码确认，无需密码，自动获取姓名 + 学号）
- **Yggdrasil 外置登录**：内置 yggdrasil-api 插件，支持 Authlib-Injector，`/api/yggdrasil` 为 Yggdrasil API Root
- **校友 / 外校邀请码**：管理员后台可生成一次性邀请码，校友绑定原学校（需填原学号）、外校人员绑定「外校」，按「学校 + 学号」登记防止两校学号撞号
- **隐私保护**：网页上只显示验证所属学校，不显示学号、姓名等个人信息；提供独立隐私协议页（`/privacy`）
- **完整皮肤站功能**：皮肤库、衣柜、角色管理、积分系统、邮箱验证、多语言等 Blessing Skin 全部功能保留

## 技术栈

- PHP >= 8.1（推荐 8.3）+ Laravel 10
- SQLite（开发）/ MySQL、MariaDB（生产）
- Nginx + PHP-FPM（生产）
- Webpack 构建前端资源

## 目录结构

```
.
├── app/                  # Laravel 应用代码
├── config/               # 站点配置（options.php 为皮肤站默认选项）
├── deploy/               # 生产部署配置与说明
│   ├── nginx.conf.example
│   ├── php-fpm-pool.example.conf
│   └── deploy-notes.md   # 部署与运维手册
├── plugins/
│   └── student-verification/   # 学生身份验证插件（本项目核心开发内容）
├── resources/views/      # 页面模板（home.twig 为定制首页）
├── public/               # Web 根目录
├── routes/
├── .env                  # 环境配置（不入库）
└── README.md
```

> `yggdrasil-api` 插件不在仓库内（其 RSA 私钥不入库是安全设计），新部署时从插件市场安装，详见「生产部署」。

## 学生身份验证插件

插件位于 `plugins/student-verification`，通过 Blessing Skin 的 Filter / Event / Hook 机制实现，不改动核心代码：

- 使用 `can_upload_texture`、`can_delete_texture` 等 Filter 拦截未验证用户的皮肤操作
- 通过 `player.adding` 事件阻止未验证用户创建角色
- 用户中心侧边栏新增「身份验证」菜单，用户面板显示 已认证/未认证 徽章
- 验证接口带限流（5 次/分钟），防止对学校系统暴力尝试

### 验证流程

| 学校 | 系统 | 方式 |
|------|------|------|
| 燕京理工学院 (YIT) | 教务系统 jw.yit.edu.cn | `Base64(学号)%%%Base64(密码)` 提交登录，成功后读取学籍卡片页解析姓名并核对学号 |
| 应急管理大学 (UEM) | 统一认证扫码 auth.ncist.edu.cn | 服务器创建扫码会话，用户用学校 App 扫码确认后读取学号完成验证，无需密码 |

### 隐私与安全

- 密码只存在于单次请求的内存中，不落库、不缓存、不打日志
- 数据库仅保存：学校、学号、验证时间（不保存姓名）
- 网页上只显示验证所属学校，不显示学号、姓名
- 不获取、不保存任何成绩、课表等学业信息
- UEM 验证采用扫码方式，不收集密码

## 本地开发

### 标准方式（任意环境）

```bash
composer install
cp .env.example .env
php artisan key:generate
# SQLite 数据库
touch database/database.sqlite
php artisan migrate
php artisan serve
```

前端资源（CSS/JS）由 webpack 构建生成，首次启动前需要构建：

```bash
npm install --legacy-peer-deps --ignore-scripts
node node_modules/webpack/bin/webpack.js --env production
```

### 本机 WSL 环境（开发机专用）

本项目开发机使用 WSL Ubuntu + 便携 PHP 8.3（`php-env/`，位于工作区、不在仓库内），数据库位于 `/home/liu23/bss-data/database.db`。以下脚本位于开发机工作区 `tools/` 目录（同样不在仓库内），仅本机可用：

```bash
# 启动站点（PHP 内置服务器，0.0.0.0:5001，供局域网访问）
wsl -d Ubuntu -e bash tools/start-dev.sh

# 冒烟测试（首页 / 登录 / 验证页 / 验证 API / Yggdrasil）
wsl -d Ubuntu -e bash tools/smoke-test.sh

# 素材同步（img/ 目录的首页背景与 logo 变更后重新生成）
wsl -d Ubuntu -e bash tools/sync-home-assets.php
```

注意事项：

- 本机路径含 `&`，`php-env/php.ini` 无法解析 `extension_dir`，由 `run-php.sh` 运行时注入
- 已启用 OPCache（`opcache.validate_timestamps=0`），改 PHP 代码后需重启服务器
- 关闭 Twig 自动重载，改模板后需清空 `storage/framework/views/twig/` 缓存
- 选项配置缓存在 `storage/options.php`，用 `php artisan options:cache` 重建
- 首页背景为 WebP 幻灯片（`public/app/bg/N.webp`），logo 为 `public/app/logo-home.png`，favicon 为 `public/app/favicon.png`

## 生产部署

> 详细运维手册见 [deploy/deploy-notes.md](deploy/deploy-notes.md)，以下为完整步骤。

### 1. 环境要求

- 服务器：Linux（Debian/Ubuntu 系），PHP >= 8.1（推荐 8.3）+ PHP-FPM
- PHP 扩展：gd（或 imagick）、openssl、pdo_sqlite（或 pdo_mysql）、mbstring、fileinfo、zip
- Web 服务器：Nginx（配置示例见 `deploy/nginx.conf.example`）
- HTTPS 证书：Let's Encrypt（certbot）或学校提供的证书

### 2. 获取代码与安装依赖

```bash
git clone <你的仓库地址> /var/www/bss
cd /var/www/bss
composer install --no-dev --optimize-autoloader
```

### 3. 配置环境

```bash
cp .env.example .env
php artisan key:generate
```

编辑 `.env` 关键项：

| 配置项 | 说明 |
| --- | --- |
| `APP_URL` | 正式域名，如 `https://skin.example.com` |
| `APP_DEBUG` | 生产环境设为 `false` |
| `DB_CONNECTION` | SQLite 用 `sqlite`；生产建议 `mysql` |
| `DB_DATABASE` / `DB_HOST` / `DB_PORT` / `DB_USERNAME` / `DB_PASSWORD` | MySQL 连接信息 |
| `MAIL_MAILER` 及 `MAIL_*` | 邮件服务，见下文第 8 节 |
| `PLUGINS_URL` | 插件市场地址，改为 `https://你的域名/plugins` |

### 4. 数据库

SQLite（开发）：

```bash
touch database/database.sqlite
php artisan migrate --force
```

MySQL（生产，推荐）：

```sql
CREATE DATABASE bss CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bss'@'localhost' IDENTIFIED BY '强密码';
GRANT ALL PRIVILEGES ON bss.* TO 'bss'@'localhost';
FLUSH PRIVILEGES;
```

```bash
php artisan migrate --force
```

### 5. 目录权限

```bash
chown -R www-data:www-data /var/www/bss
chmod -R 775 /var/www/bss/storage /var/www/bss/bootstrap/cache
```

> `plugins/yggdrasil-api` 目录需要 Web 运行用户可写（插件自动更新要用）。

### 6. Nginx + PHP-FPM

- PHP-FPM 池配置参考 `deploy/php-fpm-pool.example.conf`，放入 `/etc/php/8.3/fpm/pool.d/bss.conf`
- Nginx 站点配置参考 `deploy/nginx.conf.example`，改好域名与证书路径后启用并重载：

```bash
cp deploy/nginx.conf.example /etc/nginx/sites-available/bss
# 编辑替换 skin.example.com 与证书路径
ln -s /etc/nginx/sites-available/bss /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

配置要点：

- 站点根目录指向 `public/`
- `client_max_body_size` 需大于最大材质体积（默认 1024 KB，模板已设 10m）
- 必须配置 `Access-Control-Allow-Origin: *`（MUA Union 要求）
- 隐藏 `.env` 等敏感文件

### 7. HTTPS

```bash
apt install certbot python3-certbot-nginx
certbot --nginx -d skin.example.com
```

Union 要求成员皮肤站必须启用 HTTPS。

### 8. SMTP 邮件

开发环境可用 `MAIL_MAILER=log`（邮件写入 `storage/logs/`，找回密码功能链路可用）。

生产环境填写真实 SMTP：

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=465
MAIL_USERNAME=no-reply@example.com
MAIL_PASSWORD=******
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="YIT & UEM 联合皮肤站"
```

修改 `.env` 后需重建配置缓存：`php artisan config:cache`。

### 9. 缓存与优化

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan options:cache
```

### 10. 插件与 Yggdrasil 密钥

- `student-verification` 插件已包含在本仓库中
- `yggdrasil-api` 插件需从后台「插件管理 → 插件市场」安装并启用
- RSA 私钥存放在数据库选项 `ygg_private_key` 中（不入库）。新部署后到「插件配置 → Yggdrasil API」点击「生成密钥对」
- 验证 Yggdrasil API：`curl https://你的域名/api/yggdrasil` 应返回 JSON meta

### 11. 上线后检查清单

- [ ] 后台 → 站点设置：站点名称、公告（含 HMCL/PCL 认证服务器地址）、首页背景/logo
- [ ] 公告里的认证服务器地址改为 `https://你的域名/api/yggdrasil`
- [ ] 后台 → 插件管理：`student-verification`、`yggdrasil-api` 均已启用
- [ ] 管理员账号权限：必要时设为超级管理员（`users.permission = 2`）
- [ ] 隐私协议页 `/privacy` 可访问
- [ ] 邮箱验证 / 找回密码实际发送成功

## MUA Union 联合认证接入

联合认证文档：https://docs.mualliance.cn/zh/dev/union/auth

本站已满足接入 Union 的关键条件：

1. 基于 Blessing Skin（v6.0.2）
2. 注册限制：Union 要求成员皮肤站必须限制注册，本站采用「学校统一认证账号验证 + 邀请码」方式，属文档允许的方式
3. Yggdrasil API 外置登录可用

接入步骤（由站点管理员完成）：

1. 后台 → 插件管理 → 安装 **Union 插件**（MUA 修改自 Yggdrasil Connect，通过 MUA Union 交流群 / 联系人 @ff98sha 获取）
2. 安装后重新启用插件，进入「插件配置 → Yggdrasil Connect → Union 相关配置」；若无该界面，清空 `storage/framework/views` 缓存后重开
3. 加入界面右侧显示的 MUA Union 交流群
4. 向联系人提供：皮肤站根目录网址、站点/组织名称缩写（6 个以内大写字母，例如 `YITUEM`）
5. 等待联系人确认对接完成
6. 对接完成后，将 Minecraft 服务器 Yggdrasil API 改为 Union 地址：
   - 允许全部成员：`https://skin.mualliance.ltd/api/union/yggdrasil`
   - 白名单：`https://skin.mualliance.ltd/api/union/yggdrasil/only/{code}`
   - 黑名单：`https://skin.mualliance.ltd/api/union/yggdrasil/excludes/{code}`

服务端注意：确保对外域名启用 HTTPS、配置 `Access-Control-Allow-Origin: *`、校准服务器时钟、保持 `plugins/yggdrasil-api` 可写（插件更新需要）。

## 备份与恢复

必须备份：

- 数据库（开发环境为 `/home/liu23/bss-data/database.db`；生产按所选数据库）
- `storage/options.php`（选项缓存，含站点配置）
- `storage/textures/`（玩家上传的材质文件）
- `.env`（含 APP_KEY、数据库密码、邮件密码）

恢复顺序：代码 → `.env` → 数据库 → `storage/textures` → `php artisan config:cache && php artisan options:cache`。

## 维护

- 修改模板（`.twig`）后清空 `storage/framework/views/twig/` 并重启
- 修改 PHP 代码后重启 PHP 进程（OPCache 关闭了自动校验）
- 修改 `.env` / 选项后重建 `config:cache` / `options:cache`
- 首页素材变更：替换 `img/` 目录文件后运行素材同步脚本（开发机 WSL 工作区：`tools/sync-home-assets.php`），并更新 `resources/views/home.twig` 中 logo 的 `?v=` 缓存版本号

## 致谢

- [Blessing Skin Server](https://github.com/bs-community/blessing-skin-server)（MIT）
- [MUA Union 联合认证](https://docs.mualliance.cn/zh/dev/union/auth)
- 燕京理工学院 MC 玩家创作协会、应急管理大学 Minecraft 同好会