---
name: dshphp 插件与技能开发
description: 为 dshphp 写插件、市场条目、ui 清单或自定义技能时使用
when: 插件，技能，plugin，market，skill，dshphp，工具函数，slash 命令，ui 清单，window.DSH
default: auto
---

## 插件契约

- 单文件 `market/*.php` 或 `plugins/*.php`，**只 `return` 一个数组**，不要定义顶层 class / function——同一次请求可能重复 `require` 同一文件，重复声明会 fatal。
- 字段：`id/name/description/version/author/icon/enabled/tools/slash/run/category/tags/risk/usage`。
- `run` 形如 `function(string $tool, array $args, array $ctx): array`，返回 `['ok'=>bool,'text'=>string,'data'=>mixed]`。
- `$ctx['store']` 提供 `kvGet/kvSet`；不要直接读写任意文件、不要发起未声明的外部请求、不要打印服务器绝对路径。
- 分类键：`text/dev/calc/time/net/life/fun/manage/beautify/utility`（后两类是界面增强）。

## 可选 ui 清单（GUI API）

`ui` 支持 `apiVersion/scope/css/js/files/actions`；`scope` 取 `app` / `login` / `modal`。
- 外部资源文件必须白名单内、与插件同目录，禁止 `..`；CSS/JS 由服务端聚合端点输出，只有「已安装 + 已启用 + 未损坏」的插件才会被包含。
- 前端用 `window.DSH` 的 `on/off/emit/state/core/api/storage/ui.mount/ui.panel/ui.css/ui.toast`；`DSH.ready(fn)` 拿到的是该插件专属 API。
- 禁用插件时其资源与脚本都不会下发；登录页插件拿不到 `DSH.api()`。

## 技能契约

`skills/*.md`，frontmatter `name/description`，可选 `when`（触发词）、`default`（on/auto/off）。
正文会注入系统提示词，单文件建议 < 3000 字；带 `when` 的技能默认按需，命中触发词才生效。

## 部署

只上传 `market/ plugins/ skills/ app/ assets/` 等运行文件；`config.php`、`storage.php`、`data/`、`tools/`、本机专用 ini 与启动脚本绝不上传或覆盖。
