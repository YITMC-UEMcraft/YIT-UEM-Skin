#!/usr/bin/env bash
# YIT & UEM 联合皮肤站 — 一键部署脚本（幂等，可重复执行）
# 用法:
#   sudo bash deploy/deploy.sh
# 可选环境变量:
#   APP_DIR=/var/www/bss      站点目录（默认 /var/www/bss）
#   WEB_USER=www-data         PHP-FPM 运行用户（默认 www-data）
#   ADMIN_EMAIL=xxx@x.com     超级管理员邮箱（不存在则创建，已存在则提升权限）
#   ADMIN_PASSWORD=********   新管理员密码（仅创建新账号时需要）
#   PHP_BIN=php               PHP CLI 路径（默认 php）
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/bss}"
WEB_USER="${WEB_USER:-www-data}"
PHP_BIN="${PHP_BIN:-php}"

say()  { printf '\033[1;32m[deploy]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[deploy]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[deploy] 失败:\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "请用 root 运行（sudo bash deploy/deploy.sh）"
[ -d "$APP_DIR" ] || die "站点目录不存在: $APP_DIR（请先 git clone）"
cd "$APP_DIR"

say "站点目录: $APP_DIR"

# ---------- 0. 拉取最新代码 ----------
if [ -d .git ]; then
  say "拉取最新代码..."
  git fetch --all --prune
  if git diff --quiet HEAD && git diff --cached --quiet; then
    git pull --ff-only || warn "git pull 失败（本地有改动？），继续使用当前代码"
  else
    warn "本地有未提交改动，跳过 git pull（避免覆盖你的修改）"
  fi
else
  warn "当前目录不是 git 仓库，跳过拉取。请确认代码已是最新。"
fi

# ---------- 1. PHP 依赖 ----------
if command -v composer >/dev/null 2>&1; then
  say "安装 PHP 依赖..."
  composer install --no-dev --optimize-autoloader --no-interaction || warn "composer install 失败（多为网络问题）；若 vendor/ 已存在可继续"
else
  warn "未找到 composer，跳过依赖安装"
fi

if [ ! -f vendor/autoload.php ]; then
  die "缺少 vendor/autoload.php，依赖未就绪"
fi

# ---------- 2. .env ----------
if [ ! -f .env ]; then
  say "生成 .env ..."
  cp .env.example .env
  "$PHP_BIN" artisan key:generate --force
  warn "请检查 .env 中的 APP_URL / 数据库 / SMTP 配置后重跑本脚本"
else
  say ".env 已存在，保留现有配置"
fi

# ---------- 3. 数据库 ----------
DB_CONN=$("$PHP_BIN" -r 'require "vendor/autoload.php"; $e=parse_ini_file(".env"); echo $e["DB_CONNECTION"] ?? "sqlite";' 2>/dev/null || echo sqlite)
if [ "$DB_CONN" = "sqlite" ]; then
  DB_FILE=$("$PHP_BIN" -r '$e=parse_ini_file(".env"); echo $e["DB_DATABASE"] ?? "database/database.sqlite";' 2>/dev/null || echo database/database.sqlite)
  if [ ! -f "$DB_FILE" ]; then
    say "创建 SQLite 数据库: $DB_FILE"
    touch "$DB_FILE"
  fi
fi

say "执行数据库迁移..."
"$PHP_BIN" artisan migrate --force || die "数据库迁移失败"

# ---------- 4. 站点初始化（站点名/公告/插件/首页素材） ----------
if [ -f scripts/init-site.php ]; then
  say "运行站点初始化..."
  "$PHP_BIN" scripts/init-site.php
else
  warn "未找到 scripts/init-site.php，跳过站点初始化"
fi

# ---------- 5. 清理视图缓存（模板更新后必须） ----------
say "清理 Twig 视图缓存..."
rm -rf storage/framework/views/twig/* 2>/dev/null || true

# ---------- 6. 选项缓存 ----------
"$PHP_BIN" artisan options:cache || warn "options:cache 失败（首次运行属正常，稍后重试）"

# ---------- 7. 超级管理员 ----------
if [ -n "${ADMIN_EMAIL:-}" ]; then
  say "确保超级管理员 ${ADMIN_EMAIL} 存在..."
  if [ -n "${ADMIN_PASSWORD:-}" ]; then
    "$PHP_BIN" scripts/create-admin.php "$ADMIN_EMAIL" "$ADMIN_PASSWORD"
  else
    "$PHP_BIN" scripts/create-admin.php "$ADMIN_EMAIL"
  fi
else
  warn "未设置 ADMIN_EMAIL，跳过管理员创建。可用:"
  warn "  php scripts/create-admin.php 你的邮箱 密码"
fi

# ---------- 8. 目录权限 ----------
say "修正目录权限..."
chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache plugins 2>/dev/null || warn "chown 失败（WEB_USER=$WEB_USER），请手动确认权限"
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# ---------- 9. 配置缓存（注意：不执行 route:cache，避免插件路由丢失） ----------
"$PHP_BIN" artisan config:cache || warn "config:cache 失败"
"$PHP_BIN" artisan view:cache || warn "view:cache 失败（模板含动态内容时忽略）"

# ---------- 10. 验证 ----------
say "验证站点状态..."
BASE_URL=$("$PHP_BIN" -r '$e=parse_ini_file(".env"); echo rtrim($e["APP_URL"] ?? "http://127.0.0.1", "/");' 2>/dev/null || echo http://127.0.0.1)
for path in / /api/yggdrasil /student-verification /privacy; do
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE_URL$path" || echo '000')
  printf '  %-22s %s\n' "$path" "$code"
done

say "部署完成！"
say "下一步（如果还没做过）:"
say "  1. 后台 → 插件管理 → Yggdrasil API → 配置 → 生成密钥对"
say "  2. 若接入 MUA Union，需先申请 Union 插件（见 deploy/deploy-notes.md 第 7 节）"
say "  3. 配置真实域名 + HTTPS + SMTP（见 deploy/deploy-notes.md）"