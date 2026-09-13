# Minecraft 服务器接入 MUA Union 联合认证

目标：接入 Union 联合认证，本站当前使用「允许全部 Union 皮肤站账号登录」的模式。
Yggdrasil API Root：`https://skin.mualliance.ltd/api/union/yggdrasil`

> 说明：本站账号仍会经过 `student-verification` 的学生身份校验，未完成验证无法登录或进服；外站账号的验证状态由对应对接站及 Union 规则负责。若以后要求整台服务器只允许本站认证用户，把地址换成 `https://skin.mualliance.ltd/api/union/yggdrasil/only/YITUEM` 即可。

## 1. 下载 authlib-injector

把 `authlib-injector.jar` 放到服务端根目录（和服务器 jar 同级）：

- 官方下载页：https://authlib-injector.yushi.moe/artifact/latest.json （JSON 里的 `download.url` 即最新版）
- 或 GitHub Releases：https://github.com/yushijinhun/authlib-injector/releases

## 2. 修改启动命令（关键一步）

在 `java` 后面、`-jar` 前面加一段参数：

```
-javaagent:authlib-injector.jar=https://skin.mualliance.ltd/api/union/yggdrasil
```

Windows `start.bat` 示例：

```
@echo off
java -javaagent:authlib-injector.jar=https://skin.mualliance.ltd/api/union/yggdrasil -Xmx4G -jar paper-1.21.1.jar nogui
pause
```

Linux `start.sh` 示例：

```
#!/bin/sh
java -javaagent:authlib-injector.jar=https://skin.mualliance.ltd/api/union/yggdrasil -Xmx4G -jar paper-1.21.1.jar nogui
```

Fabric / Forge 服务端同理：参数加在 `java` 之后、`-jar` 之前（`-Xmx` 等可自定义）。

## 3. server.properties

保持 `online-mode=true`（默认值，不要改成 false）。

authlib-injector 会在启动时接管正版验证、把验证服务器替换为 Union 地址；改成离线模式反而无法使用外置登录。

## 4. 验证是否生效

1. 启动日志中出现 authlib-injector 版本号及 `authlib-injector is enabled` 之类的字样；
2. 用 HMCL / PCL 添加认证服务器：`https://skin.mualliance.ltd/api/union/yggdrasil`，用任意 Union 皮肤站账号登录；
3. 本站已完成学生身份验证的账号可以进服，未完成验证的账号会被拒绝（提示 `Please complete student verification before joining the game` / `请先完成学生身份验证`）。

## 5. 常见问题

- 报错 `Could not open authlib-injector.jar`：jar 没放在服务端根目录，或路径写错。
- 多服务器（如群组服子服）需要**每台**都加同一段 `-javaagent` 参数。
- 只想让部分 Union 站点登录（{code} 为组织缩写，本站为 `YITUEM`）：
  - 白名单：`https://skin.mualliance.ltd/api/union/yggdrasil/only/{code}`
  - 黑名单：`https://skin.mualliance.ltd/api/union/yggdrasil/excludes/{code}`
- 想同时允许正版或其他非 Union 皮肤站登录，需要配合 MultiLogin 使用。
- 1.16.1 及以下版本无需额外参数，authlib-injector 自动兼容。
