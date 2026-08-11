# YIT & UEM 联合皮肤站 — 生产部署与运维说明

本文件覆盖：生产环境部署、SMTP 邮件、站点地址、Yggdrasil 密钥、MUA Union 接入。
开发环境（WSL + PHP 内置服务器）仅供测试，正式上线请按下文执行。

## 1. 环境要求

- 服务器：Linux（Debian/Ubuntu 系），PHP >= 8.1（推荐 8.3）+ PHP-FPM
- PHP 扩展：gd（或 imagick）、openssl、pdo_sqlite（或 pdo_mysql）、mbstring、fileinfo、zip
- Web 服务器：Nginx（配置示例见 `deploy/nginx.conf.example`）
- HTTPS 证书：Let's Encrypt（certbot）或学校提供的证书

## 2. 部署步骤

1. 拉取代码：`git clone <你的仓库> /var/www/bss && cd /var/www/bss`
2. 安装依赖：`composer install --no-dev --optimize-autoloader`
3. 前端资源：`public/app/` 下的编译产物**已随仓库提交**，无需构建。仅当修改过 `resources/assets/` 前端源码时才需要重建：

   ```bash
   npm install --legacy-peer-deps --ignore-scripts
   npm run build
   ```

4. 配置环境：
   - `cp .env.example .env`
   - `php artisan key:generate`
   - 修改 `APP_URL` 为正式域名（如 `https://skin.example.com`）
   - 数据库：SQLite 可直接用；生产建议 MySQL：
     - `DB_CONNECTION=mysql`、`DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`
5. 初始化：`php artisan migrate --force`
6. 权限：`chown -R www-data:www-data storage bootstrap/cache plugins/yggdrasil-api`
   - 注意：`plugins/yggdrasil-api` 需要可写，插件自动更新要用
7. Nginx：复制 `deploy/nginx.conf.example` 到 `/etc/nginx/sites-available/`，改域名与证书路径后启用
8. HTTPS：`certbot --nginx -d skin.example.com`
9. 性能：`php artisan config:cache && php artisan route:cache && php artisan view:cache`
10. 一键初始化站点配置（站点名称/公告/插件）：运行 `php scripts/init-site.php`，再 `php artisan options:cache`
11. 创建超级管理员：运行 `php scripts/create-admin.php 你的邮箱 你的密码`
12. 确认 Yggdrasil API 可用：`curl https://你的域名/api/yggdrasil` 应返回 JSON meta

## 3. 邮件（SMTP）

开发环境：`.env` 中 `MAIL_MAILER=log`，邮件会写入 `storage/logs/`（找回密码功能可用，但不会真的发出去）。

生产环境：在 `.env` 中填写真实 SMTP（示例）：

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

修改 `.env` 后必须重新缓存：`php artisan config:cache`。

## 4. 站点地址

上线后需要把「开发环境内网 IP」全部替换为正式域名：

| 位置 | 修改为 |
| --- | --- |
| `.env` 的 `APP_URL` | `https://你的域名` |
| 后台「站点设置」的 `home_pic_url`、`favicon_url` | 保持相对路径即可，无需改 |
| 用户中心公告里的认证服务器地址 | `https://你的域名/api/yggdrasil`（后台 → 站点设置 → 公告） |
| `.env` 的 `PLUGINS_URL` | `https://你的域名/plugins` |

## 5. Yggdrasil 密钥（换机/新部署必做）

- RSA 私钥存放在**数据库选项 `ygg_private_key`** 中（不在仓库里，也不在文件系统里，属于安全设计）。
- 换机器部署后需要重新生成：
  1. 后台 → 插件管理 → Yggdrasil API → 配置
  2. 点击「生成密钥对」按钮，保存
- 重新生成后，旧签名会失效（皮肤签名会短暂报错，属正常现象，玩家重新登录即可）。
- 若将来接入 MUA Union，Union 会下发并同步 Union 私钥，覆盖本站签名逻辑。

## 6. 插件安装（新部署）

- `student-verification`（学生验证/邀请码）插件已包含在本仓库中，无需额外安装。
- `yggdrasil-api` 插件**已包含在仓库内**（RSA 私钥不入库，由「生成密钥对」按钮写入数据库），无需再从插件市场安装：
  1. 后台 → 插件管理 → 插件市场 → 安装 `yggdrasil-api`
  2. 启用插件，按第 5 节生成密钥对

## 7. MUA Union 接入清单

前置条件（必须全部满足）：

- [ ] 正式域名 + HTTPS（Union 要求成员站必须启用 HTTPS）
- [ ] 注册限制（本站已有：双校学籍验证 + 校友/外校邀请码，满足要求）
- [ ] 已向 MUA 提交申请并拿到 Union 插件（联系 @ff98sha，交流群 742221635）

接入步骤：

1. 后台 → 插件管理 → 安装 Union 插件（修改自 Yggdrasil Connect，包含其原有功能）
2. 安装后重新启用插件，进入「Yggdrasil Connect」配置，会出现「Union 相关配置」界面
   - 若没有该界面：删除 `storage/framework/views` 下的缓存后重开管理页
3. 加入界面右侧显示的 MUA Union 交流群
4. 告知联系人：皮肤站根目录网址 + 组织缩写（6 个以内大写字母，建议 `YITUEM`）
5. 等待联系人确认对接完成
6. 对接完成后，把 Minecraft 服务器的 Yggdrasil API Root 改为 Union 地址：
   - 允许全部成员：`https://skin.mualliance.ltd/api/union/yggdrasil`
   - 白名单：`https://skin.mualliance.ltd/api/union/yggdrasil/only/{code}`
   - 黑名单：`https://skin.mualliance.ltd/api/union/yggdrasil/excludes/{code}`

常见问题：

- 需保证服务器时钟校准（NTP），否则无法与 Union 通信
- Web 服务器需配置 `Access-Control-Allow-Origin: *`（本仓库 nginx 模板已包含）
- 1.19+ 服务器需在 Union 内绑定 UUID

## 8. 备份与恢复

必须备份的内容：

- 数据库：开发环境为 `/home/liu23/bss-data/database.db`；生产环境按所选数据库备份
- `storage/options.php`（选项缓存，含 ygg_private_key 的引用）
- `storage/textures/`（玩家上传的材质文件）
- `.env`（含数据库密码、APP_KEY、邮件密码）

恢复顺序：代码 → `.env` → 数据库 → `storage/textures` → `php artisan config:cache`。

## 9. HTTPS 证书（Let's Encrypt，国内服务器注意）

前提：域名已解析到服务器（A 记录 → 服务器公网 IP），且腾讯云/阿里云**安全组已放行 443 端口**。

国内云服务器（如腾讯云）常见问题：Let's Encrypt 的 HTTP-01 校验请求会被腾讯云边缘拦截（返回 DNSPod 拦截页），导致 `certbot --apache` 反复失败。此时改用 **DNS-01 校验**（通过 TXT 记录验证，完全不依赖 HTTP）：

```bash
# 1. 在服务器上运行，它会打印两个 TXT 校验值并等待回车
sudo certbot certonly --manual --preferred-challenges dns-01 \
  -d skin.uemcraft.cn -d skin.yitmc.cn \
  --agree-tos --register-unsafely-without-email --cert-name skin-multi

# 2. 到两个域名的 DNS 控制台分别添加 TXT 记录（主机记录填 _acme-challenge.skin，不要填完整域名）
# 3. 确认 TXT 在全球生效后，回车继续
# 4. 把证书装进 Apache：
sudo certbot --apache install --cert-name skin-multi -d skin.uemcraft.cn -d skin.yitmc.cn
```

注意事项：

- 如果 HTTP-01 方式需要保留，必须确保 `public/.htaccess` 已加入 `.well-known` 例外（仓库已内置），否则挑战路径会被 403 拦截。
- 签发后把 `.env` 的 `APP_URL` 与 `PLUGINS_URL` 改为 `https://skin.uemcraft.cn`，然后依次执行：
  `php artisan config:clear && php scripts/init-site.php && php artisan config:cache && php artisan options:cache`
- **续期**：手动 DNS-01 签发的证书不会自动续期，有效期 90 天。到期前按同样流程重跑一次即可（证书目录不变，`certbot renew` 配合手动 TXT 记录）。
- 多域名共用同一证书时，`ServerAlias` 里所有域名都要在签发命令的 `-d` 参数里列出。