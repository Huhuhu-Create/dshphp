---
name: PHP 子目录部署排障
description: 自建 Nginx + PHP-FPM、FTP 上传、子目录部署时的 404 / 白屏 / 源码被下载等问题
when: php，nginx，php-fpm，404，白屏，500，部署，上传，ftp，服务器，源码被下载，变成下载，伪静态，重写，子目录
default: auto
---

## 排查顺序（不要跳步）

1. **文件是否真的在 Web 可访问目录**：先访问一个只输出 `PING-OK` 的 `ping.php`。FTP 客户端显示的当前目录常是相对其自身根，**不等于站点根**。
2. **看 HTTP 状态而非猜配置**：静态资源 200 + 入口 `.php` 404 = 该文件根本没传到位（不是权限、不是重写）。
3. `.php` 返回源码或触发下载 = Nginx 缺 `location ~ \.php$` 解析块 / PHP-FPM 没跑 / 误传了本机专用 `php.ini` 导致 PHP 引擎起不来。
4. 最后才看业务代码与日志。

## 该形态的硬约束

- `.html` 里内嵌 PHP 通常**不会**被解析，页面一律用 `.php` 后缀。
- 没有 `try_files` / 重写 / 默认文档时：入口显式写 `index.php`，接口用 `index.php?r=xxx` 查询参数路由；`/api/xxx` 这类漂亮路径会 404，目录 URL 也不会自动找 `index.php`。
- 子目录部署：静态资源一律**相对路径**，不要以 `/` 开头的绝对基址。
- PHP 8.x 用显式可空参数 `?string $x = null`，否则 deprecated 警告会打印到页面；入口关掉 `display_errors` 防绝对路径泄露。
- 数据文件放 `data/` 且需 PHP 运行用户可写；随机化文件名时注意 `.php` 会被执行而非读取，公开页面绝不打印真实文件名。

## 给建议的方式

- 只给 **FTP 能操作**的动作（传/删/改名文件、确认目录结构），不要把需要 root 改 Nginx、重启服务的命令当成首选。
- 交付前排除：本机专用 `php.ini`、`run-local.bat`、开发期 `.htaccess`、`config.php`、`storage.php`、`data/`、`tools/`；升级时绝不覆盖线上配置与数据。
