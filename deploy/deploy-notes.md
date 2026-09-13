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
- Yggdrasil 认证：使用 **Yggdrasil Connect（MUA Union 版，含 Union 支持）**，插件包不在仓库内（从 MUA 获取 `yggdrasil-connect.zip`）：
  1. 上传/解压到 `plugins/yggdrasil-connect/`（zip 内目录名即 `yggdrasil-connect`）；
  2. 修改数据库选项 `plugins_enabled`：**停用旧 `yggdrasil-api`**（两者冲突），加入 `{"name":"yggdrasil-connect","version":"6.1.0-0.3.2"}`；
  3. 刷新选项缓存：`php artisan options:cache`；
  4. 创建 Passport 个人访问客户端（交互式命令需 `echo yes |` 应答）：
     `echo yes | php artisan yggc:create-personal-access-client`
     把输出的 `Client ID` 写入 `.env`：`PASSPORT_PERSONAL_ACCESS_CLIENT_ID=<ID>`，然后 `php artisan config:cache`；
  5. 旧版迁移：`echo yes | php artisan yggc:fix-uuid-table`（先备份 `uuid` 表！）；
  6. 补齐 Union 选项默认值（否则 `/api/yggdrasil` 会 500）：
     `INSERT INTO options (option_name, option_value) VALUES ('union_server_list','[]'),('union_server_list_version','0'),('union_api_root',''),('union_private_key_version','0');`
  7. **重要**：该插件多处用 `env('PASSPORT_PERSONAL_ACCESS_CLIENT_ID')` 直接读环境变量，在 `config:cache` 后取不到值，会报 `Invalid Personal Access Client ID`。需把 `src/Middleware/CheckIfAuthServerDisabled.php`、`src/Controllers/ConfigController.php`、`src/Models/AccessToken.php` 中的 `env('PASSPORT_PERSONAL_ACCESS_CLIENT_ID')` 替换为 `config('passport.personal_access_client.id')`，然后重启 PHP-FPM；
  8. RSA 密钥沿用数据库选项 `ygg_private_key`（旧 yggdrasil-api 的 4096 位密钥可直接用），也可在插件配置页重新生成。

### 已知坑（2026-08-12 生产环境修复，重装必看）

- **`/union` 角色绑定页 500**：`src/Controllers/UnionProfileController.php` 中 `class_exists('Promise::Utils')` 判断永远为 `false`，而 guzzlehttp/promises 2.x 已移除 `Promise\unwrap()` 函数，导致打开页面直接 `Call to undefined function GuzzleHttp\Promise\unwrap()`。
  修复：把该判断改为 `class_exists(\GuzzleHttp\Promise\Utils::class)`（生产机已修，需同步到新装的插件包）。
- **改数据库选项后必须刷新缓存**：站点实时读取 `storage/options.php`，对 `options` 表的手工/脚本修改不会立即生效。
  修改后执行 `php artisan options:cache`，否则会出现「数据库里明明配了却不生效」。
- **`union_api_root` 必须填写 Union 地址**：`https://skin.mualliance.ltd/api/union`。为空时 MUA 每小时的入站回调
  `POST /api/union/member/{sync,updatelist,updateprivatekey}` 会 500（UnionHostVerify 用空 URL 请求），
  且玩家添加/改名/删除时的同步事件也会 500（`profile/xxx` 被解析成主机名）。
- **`union_enable_oauth2`、`union_oauth2_sig_private_key`、`union_oauth2_sig_public_key`、`union_member_key` 缺失时**：
  Union OAuth2 接口直接返回 `Union OAuth2 is not enabled`。补齐后再 `php artisan options:cache`。
  签名密钥可用插件自带函数生成：引导 Laravel 后调用 `ygg_generate_rsa_keys()` 并写入
  `union_oauth2_sig_private_key` / `union_oauth2_sig_public_key`。
- `union_member_key` 需等 MUA 确认对接后由管理员填入（后台 → Yggdrasil Connect → Union 相关配置），
  为空时所有带 `X-Union-Member-Key` 的出站请求会被 MUA 以 401 拒绝（不再 500，但同步不会完成）。
- **登录/启动器认证 500（`The requested scope is invalid, unknown, or malformed`）**：插件签发令牌用的是 Laravel Passport 的 personal_access 授权，scope（`Yggdrasil.PlayerProfiles.Select` 等）必须在 `scopes` 表和 Passport 注册。若插件不是通过后台「启用」流程安装（比如手动改 `plugins_enabled`），`PluginWasEnabled` 不会触发，`scopes` 表为空，且 BSS 用 `Cache::rememberForever('scopes')` 缓存了空数组，导致**每个账号登录都 500**。
  修复：
  1. 向 `scopes` 表插入插件所需 scope：`openid`、`profile`、`email`、`offline_access`、`Yggdrasil.PlayerProfiles.Read`、`Yggdrasil.PlayerProfiles.Select`、`Yggdrasil.Server.Join`；
  2. 清除缓存：`php artisan cache:clear`（或 `Cache::forget('scopes')`）；
  3. 重启 PHP-FPM。验证：任意账号执行登录，应能拿到 accessToken 而非 500。

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
6. 拿到 `union_member_key` 后：填入后台 → Yggdrasil Connect → Union 相关配置，执行 `php artisan options:cache`（或直接写库后刷新缓存），然后在 Union 配置页依次点「更新服务器列表 / 更新私钥 / 同步」，确认 `union_server_list`、`union_server_list_version`、`union_private_key_version` 均已更新。
7. 对接完成后，把 Minecraft 服务器的 Yggdrasil API Root 改为 Union 地址（本站当前使用「允许全部成员」）：
   - 允许全部 Union 成员（当前配置）：`https://skin.mualliance.ltd/api/union/yggdrasil`
   - 只允许本站账号：`https://skin.mualliance.ltd/api/union/yggdrasil/only/YITUEM`
   - 排除指定站点：`https://skin.mualliance.ltd/api/union/yggdrasil/excludes/{code}`

   > 说明：根地址下本站账号仍受 `student-verification` 学生身份校验约束（未验证无法进服）；外站账号的验证状态由对应对接站及 Union 规则负责。若要整台服务器只允许本站认证用户，改用 `/only/YITUEM`。

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

### 备案（腾讯云边缘拦截）

- 两个域名均已备案：
  - `skin.uemcraft.cn` → 赣ICP备2026018930号
  - `skin.yitmc.cn` → 冀ICP备2026031605号（2026-08-17 通过）
- 页脚备案号存放在数据库选项 `copyright_text`（`scripts/init-site.php` 有同款默认值），两个备案号均链接到 https://beian.miit.gov.cn/。
- 备案生效前腾讯云边缘会拦截未备案域名的 HTTP 访问（表现为部分网络打不开、302 跳转）；备案通过后边缘缓存通常 1~2 天内自动放行，若仍被拦截可到腾讯云控制台确认备案状态。

## 10. 性能优化（页面切换卡顿必做）

症状：页面切换/首次加载明显卡顿。原因通常是：前端资源未压缩（CSS 1.35MB）、无 HTTP/2（页面有 40+ 个 JS/CSS 文件）、静态资源无缓存头（每次导航都重新下载）。

一键启用（Apache）：

```bash
sudo cp deploy/apache-perf.conf.example /etc/apache2/conf-available/skin-perf.conf
sudo a2enmod http2
sudo a2enconf skin-perf
sudo apache2ctl configtest && sudo systemctl restart apache2
```

效果：gzip 压缩（总资源约 7MB → 2MB）、HTTP/2 多路复用、带哈希的构建产物浏览器缓存一年。

> 注意：改背景/logo 素材后，图片缓存最长 1 天生效；改了前端源码重新构建后文件名哈希会变，缓存自动失效。
