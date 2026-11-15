# dshphp 2.4.3 — 独立运行的 DSH 网页服务端

**纯 PHP 实现，上传即用。功能四块：对话 + 插件 + 自动化任务 + 插件自定义界面（GUI API）。**

不依赖 Node、Electron、QQ 机器人、SnowLuma、控制中心，也不需要常驻进程。
自动化任务是**纯访问驱动**：只有 `index.php` 被访问时才推进调度，没有保活进程、
没有前端定时心跳、没有外部 cron（边界见第九节）。
一台能跑 PHP 7.4+ 的空间（含共享主机）就是完整的 DSH：网页聊天、多会话持久化、
模型接入、**内置 48 个插件的一键市场**、插件自动调用、**16 个内置技能（按触发词按需注入）**、
**10 个定时自动化任务**、**插件 GUI API（第十二节）**、对外 API。

---

## 一、说清边界（重要）

你本机的 **DSH 本体是 `D:\dsh\DeepSeek Harness.exe`（246MB 的 Electron 应用）**，
插件是 Node 包（cordis），需要 Node ≥22、有 shell 的服务器和能连 QQ 的桌面环境。
**纯 PHP-FPM + FTP 的主机搬不动它**，所以这一版是用 PHP 重写了它的核心能力：

| 能力 | 桌面版 DSH | dshphp 2.4.3 |
|---|---|---|
| 对话 / 多会话 / 历史 | ✅ | ✅ 存自己的服务器 |
| 模型接入 | 内置 pi-ai 路由 | ✅ OpenAI 兼容端点，含你配置过的免 Key 路由 |
| 插件 | Node 包 + cordis.patch.yml | ✅ `plugins/*.php`，声明 tools + run + 可选 `ui` |
| 插件市场 | 在线仓库 | ✅ 本地内置 48 个（10 类），网页一键安装/卸载 |
| 插件自定义界面 | Node 包自带前端 | ✅ `ui` 清单 + `window.DSH` 运行时 + 正式生命周期钩子（第十二节） |
| 技能 | `SKILL.md` | ✅ 同样格式，16 个内置技能；2.4.1 起支持 `when` 触发词，命中才注入系统提示词 |
| 工具自动调用 | function calling | ✅ 原生 tools + 纯文本 TOOL_CALL 降级 |
| 定时自动化任务 | 常驻进程 / 系统 cron | ✅ JSON 配置 + **仅访问时执行**，无 shell、无保活（第九节） |
| 桌面 UI / 本地文件 / 终端 | ✅ | ❌ 不需要，也不提供 |
| 运行环境 | Node ≥22 + 桌面 | PHP 7.4+，无扩展硬依赖 |

如果你的服务器**有 SSH 和 Node**，想跑原版 headless，那不是这个项目；
本项目解决的是「只有 PHP + FTP，也要能独立跑一个 DSH」。

---

## 二、部署（适配 l2.ink 那台 Nginx + PHP-FPM 子目录主机）

1. **FTP 上传整个 `dshphp/` 目录**到站点子目录（例如 `/dshphp/`）。
   只传这些：`index.php` `install.php` `ping.php` `app/` `market/` `plugins/` `skills/` `assets/`（`tools/` 是开发期自检脚本，可不传）。
   `data/` 留空即可，安装器会自动建；**不要上传任何本机专用 `php.ini` / `php-local.ini` / `.htaccess`**
   （历史上误传本机 `php.ini` 曾导致全站 PHP 变下载；`php-local.ini` / `run-local.bat` 只在本机用，
   **2.4.0 部署包已把 `run-local.bat` 剔除，别从源码目录手动补传**）。
   **`market/` 必须传**——48 个市场插件都在这个目录里（含 6 个界面增强插件）。
   **2.3.1 起不需要 `cron.php`**；如果线上留有 2.3.0 的旧文件，请覆盖成部署包里的停用占位页或直接删掉。
2. 访问 **`/dshphp/ping.php`** → 看到 `PING-OK` 说明文件在 Web 能访问的位置、PHP 正常执行。
   带 `?detail=1` 可看脚本绝对路径；**模型连不上时带 `?net=1`** 会做一次服务器侧出站自检（见第十节）。
3. 访问 **`/dshphp/install.php`** → 填管理密码、API Key、模型路由 → 记下屏幕上显示的凭据（只显示一次）。
4. 访问 **`/dshphp/index.php`** 登录。**必须写 `index.php`**——这台机没有默认文档，`/dshphp/` 不会自动跳首页。
5. 收尾：**删除 `install.php` 和 `ping.php`**，`config.php` 设为只读；**确认没有指向 `cron.php` 的外部定时任务**。

### 从 2.4.0 升级到 2.4.1（技能库扩充 + 按需注入）

FTP 覆盖 `app/bootstrap.php`、`app/plugins.php`、`app/llm.php`、`index.php`、`assets/app.js`、`assets/style.css`，
并把整个 `skills/` 目录传上去（**新增 14 个 `.md`**，`README.txt` 同步）。
`config.php` / `app/storage.php` / `data/` **一律不动**，`skill.state` 里你手动设过的开关会原样保留。

- **老技能行为完全不变**：没写 `when` 的技能仍是「常驻」，每轮都注入；旧调用 `skill_prompt($store)` 也照常可用。
- 新增 14 个技能全部是「按需」：只有本轮消息命中触发词才进系统提示词，**单轮最多 3 个**，
  所以默认提示词体积与 2.4.0 基本相同，免费模型不会因为技能变多而变慢。
- 侧栏「技能」变三态：常驻（绿）/ 按需（琥珀）/ 关闭（灰），点击循环切换；只有写了触发词的技能才有「按需」态。
- 升级后**刷新一次页面**。

### 从 2.4.1 升级到 2.4.2（系统提示词预设 + 会话级角色）

只改了 4 个核心文件，无新文件、无数据库迁移。FTP 覆盖 `app/bootstrap.php`、`index.php`、`app/view.php`、`assets/app.js` 即可，
`config.php` / `app/storage.php` / `data/` **一律不动**，刷新一次页面生效。

- 内置 7 个系统提示词预设（通用助手 / 编程助手 / 中文文案写手 / 中英翻译专家 / 严谨分析顾问 / 耐心讲解老师 / 创意脑暴伙伴），
  写在 `app/bootstrap.php` 的 `system_presets()`，无法被用户修改覆盖（用户只写 `chat.system_prompt`，与之解耦）。
- 设置页「系统提示词」上方新增预设下拉，顶栏新增「角色」下拉（与模型并排）：切换角色会**立即应用到当前对话**并作为新建默认，
  会话级 `system` 已能在 SQLite / JSON 双后端持久化，选「默认（站点设置）」即回退到站点统一设置。
- 这是纯增强：不预设任何角色时，行为与 2.4.1 完全一致。

### 从 2.4.2 升级到 2.4.3（设置页多模型填入口 + 一键获取可用模型）

只改了核心文件，无新文件、无数据库迁移。FTP 覆盖 `app/bootstrap.php`、`app/llm.php`、`index.php`、
`app/view.php`、`assets/app.js`、`assets/style.css` 即可，`config.php` / `app/storage.php` / `data/` **一律不动**，刷新一次页面生效。

- 设置页「模型」tab 的「可选模型」从**单个逗号分隔文本框**升级为**多行独立输入框**：每条一个模型 ID，
  可「＋ 添加模型」自由增删，保存后顶栏「模型」下拉就会出现这些备选（留空则顶栏只用上方「模型 ID」）。
- 新增「**获取可用模型**」按钮：填好 API Base URL 与鉴权方式后点它，后端（新增 `api/settings/models`）
  会调用上游 OpenAI 兼容的 `/models` 端点，自动把返回的全部模型 ID 填进上面的多行列表；
  若上方「模型 ID」为空还会把第一个设为默认。免 Key 端点（OVH / Pollinations）通常不支持列模型，会提示失败，属正常。
- 后端复用既有 `llm_headers()` 构造鉴权头、`http_request()` 发请求，兼容 `{data:[{id}]}` / `{models:[...]}` / 纯数组三种返回；
  与 chat 端点约定一致——`base_url` 已是含 `/v1` 的那一层，直接拼 `/models`。
- 纯增强：不点「获取可用模型」、不填可选模型时，行为与 2.4.2 完全一致（可选模型为空仍只用主模型）。

### 从 2.3.2 升级到 2.4.0（插件 GUI API）

FTP 覆盖 `index.php`、`app/bootstrap.php`、`app/plugins.php`、`app/market.php`、`app/view.php`、`assets/app.js`、`assets/style.css`，
并**新增上传** `app/plugin-ui.php`、`assets/dsh-ui.js` 和 `market/mkt.sidebar.php`、`mkt.context.php`、
`mkt.vtree.php`、`mkt.glass.php`、`mkt.passwords.php`、`mkt.browser.php`（内嵌浏览器，见第十二章）。
`config.php` / `app/storage.php` / `data/` **一律不动**（老配置缺新键会自动补默认值）。

- 老插件清单**完全兼容**：`ui` 是可选字段，42 个旧插件不改一个字，扫描/加载/调用行为不变。
- 升级后**先刷新一次页面**（资源 URL 带版本串，浏览器不会拿旧缓存）。
- 5 个界面插件默认**未安装**，到「设置 → 插件市场」的 **美化 / 实用** 分类里安装，装完再刷新即可生效。
- 一键停用整套界面扩展点：把 `config.php` 里的 `ui` 改成
  `['enabled' => false, 'allow_login_scope' => true]`；工具插件不受影响。
- 多凭据登录为**可选**能力：不添加任何凭据时，`admin_password_hash` 仍是唯一密码，行为与 2.3.2 一致。

### 从 2.3.1 升级到 2.3.2（模型线路诊断与 TLS 修复）

FTP 覆盖 `app/bootstrap.php`、`app/llm.php`、`ping.php` 三个文件即可；
`config.php` / `app/storage.php` / `data/` 不动。`run-local.bat` 与 `php-local.ini` 只在本机用。

- **修掉「连不上」黑箱**：`llm_chat()` 原先会用汇总文案覆盖真实错误，导致兜底提示
  永远只显示「连不上」，查不到 DNS / TLS / PHP 扩展缺失。现在每条线路都带具体中文原因，
  并按失败类型（连接层 / 限流 / 鉴权 / 上游 5xx）给不同建议。
- **修掉共享主机 TLS 失败**：没配 `curl.cainfo` 时所有 https 请求会报
  `SSL certificate problem`，现在自动让 libcurl 使用系统证书存储，或回退常见 CA bundle 路径。
- Pollinations 免费额度返回的 **402** 现在也算「可切换」错误，不会卡住后续线路。
- 新增 `ping.php?net=1`：服务器侧出站自检（第十节）。
- 本机 `run-local.bat` 改为 `php -c php-local.ini` 启动，修正本机 PHP 扩展目录；
  **`php-local.ini` 绝不上传服务器**。

### 从 2.3.0 升级到 2.3.1（改为纯访问驱动）

FTP 覆盖这些文件（**整个包一起传最省事**）：
`index.php` `cron.php` `ping.php` `app/bootstrap.php` `app/automation.php`
`app/automation.actions.php` `app/view.php` `assets/automation.js` `assets/style.css`。

- **`cron.php` 必须覆盖**：新版是 410 停用占位页，覆盖后旧的外部心跳链路立即失效（也可以直接删掉这个文件）。
- **到 cron-job.org / UptimeRobot / 面板计划任务里，把指向 `cron.php` 的定时任务删掉**；
  2.3.1 不需要任何外部定时。
- 面板里的「无人值守心跳 URL」已移除；JSON 里旧的心跳密钥会在读取时自动清空，导出的 JSON 不再含密钥。
- 任务表、运行日志、开关状态全部保留，`config.php` / `app/storage.php` / `data/` 不动。

### 从 2.2 直接升级到 2.3.1（不必重装）

FTP 覆盖/新增这些文件（**整个包一起传最省事**）：

- 覆盖：`index.php` `ping.php` `app/bootstrap.php` `app/view.php` `assets/style.css`
- **新增**：`app/automation.php`、`app/automation.actions.php`、`assets/automation.js`
- 可选：`cron.php`（仅用于覆盖 2.3.0 的旧文件；全新站点不必传）、`tools/auto-ui-check.mjs`

**`config.php`、`app/storage.php`、`data/` 一律不要动。** 2.3.1 不新增任何配置项，
密码、API Key、会话历史、线路设置、已装插件全部保留。自动化配置会在**首次访问时自动创建**
（随机文件名的 JSON），不需要跑安装器、不需要建表、不需要给 `app/` 写权限。
升级后打开「设置 → 自动化」就能看到 10 个内置任务，第一次访问会各执行一次作为上线自检。

### 从 2.1 升级到 2.2（不必重装）

FTP 覆盖/新增这些文件：

- 覆盖：`index.php` `ping.php` `app/bootstrap.php` `app/plugins.php` `app/view.php` `assets/app.js` `assets/style.css`
- **新增**：`app/market.php`、**整个 `market/` 目录（42 个 `.php`）**
- 可选：`tools/check-plugins.php` `tools/ui-check.mjs`（开发期自检，不影响运行）

**`config.php`、`app/storage.php`、`data/` 一律不要动。** 2.2 不新增任何配置项，
旧的 2.0 / 2.1 配置直接兼容；密码、API Key、会话历史、线路设置全部保留。
已安装插件列表存在数据库里，升级后市场插件默认「未安装」，点一下「安装推荐套装」即可。

### 从 2.0 升级到 2.1 的说明（历史）

旧配置里没有 `routing` 段，程序会自动补上默认值（自动故障转移开启、冷却 120 秒），
对话数据、密码、API Key 全部保留。已实测：旧配置会自动得到 4 条线路并能正常对话。

### 插件市场显示「没有插件」怎么办（已修复，附自检）

**根因**：旧版用 `glob()` 扫描 `market/` 目录，而部分 PHP 主机（开了 `open_basedir`、
或 `glob` 被禁用、或某些网络/Windows 文件系统）会让 `glob()` 返回 `false`，于是市场直接变空。
**新版已改为用 `scandir()` 兜底扫描，完全不再依赖 `glob()`**，这类主机也能正常列出全部 48 个插件。

如果升级后还是空的，按下面三步定位（都不用改代码）：

1. 先看版本号：访问 **`ping.php`**，看 `dshphp=` 一行。
   - 应该是 **`dshphp=2.2.2`**。如果不是，说明服务器上跑的还是旧核心文件
     （旧版 `app/bootstrap.php` 用 `glob()` 扫描，在部分主机上会返回空）——**这是「重传 market/ 后仍为空」的最常见原因**。
   - 解决：**重新上传整个包**（至少覆盖 `app/bootstrap.php`、`app/market.php`、`app/plugins.php`、`index.php`），不要只传 `market/`。
2. 看插件计数：同一页的 `market_plugins=` 一行。
   - `market_plugins=42` 且 `market_readable=yes` → 文件已就位，刷新市场页即可；
   - `market_plugins=0` 或 `market_readable=no` → **`market/` 目录没上传到位，或不在 PHP 能读的路径**。
3. 打开插件市场页，若仍空，页面会直接显示自动诊断（market 目录路径、`dir_exists`、
   PHP 版本），并提示核对核心文件是否为最新。

> 升级务必把整个包一起传上去（尤其 `app/` 下的核心文件），只传 `market/` 修不了旧版的扫描 bug。

> 排障口诀：静态资源 200、入口 `.php` 404 = 文件没传到位；
> 看到 PHP 源码或触发下载 = Nginx 缺 `location ~ \.php$` 解析块。
> 全部链接是相对路径 + `index.php?r=` 查询参数路由，不需要任何重写规则。

### 环境要求

PHP 7.4+（8.4 实测），**无扩展硬依赖**：

| 缺失的扩展 | 自动降级 |
|---|---|
| `pdo_sqlite` | JSON 文件存储 |
| `curl` | PHP stream wrapper |
| `mbstring` | 内置 UTF-8 逐字符处理 |
| `openssl` | ⚠️ 无降级：https 上游会连不上，只能用 http:// 的本机/内网模型端点 |

`data/` 需要 PHP 运行用户可写；`config.php` 保存设置时也需要可写（或临时开可写）。

---

## 三、模型路由

安装器预置 7 条（来自你 `~/.dsh/FREE-API-README.md` 里实测过的配置）：

| 路由 | 端点 | 模型 | Key |
|---|---|---|---|
| `ovh-free` ⭐ | `https://oai.endpoints.kepler.ai.cloud.ovh.net/v1` | `gpt-oss-20b` | 免注册免 Key |
| `pollinations-free` ⭐ | `https://text.pollinations.ai/openai` | `openai-fast` | 免注册免 Key |
| `pollinations-openai` ⭐ | `https://text.pollinations.ai/openai` | `openai` | 免注册免 Key |
| `deepseek` | `https://api.deepseek.com/v1` | `deepseek-chat` | 需申请 |
| `openrouter` | `https://openrouter.ai/api/v1` | `:free` 模型 | 需申请 |
| `ollama` | `http://127.0.0.1:11434/v1` | 本机模型 | 占位值 |
| `custom` | 任意 OpenAI 兼容 `/chat/completions` | 自填 | 自填 |

**OVH 的坑**：它只要收到 `Authorization: Bearer <非空值>` 就当鉴权尝试返回 403，
必须只发 `Bearer`（无 token）。安装器里选 `bearer-empty` 就是干这个的，别改成带 Key。

设置页有「测试连接」，只发一句「只回复 OK」验证连通性，不写盘、不消耗历史。
上游返回 400 且提示不支持 `tools` / `temperature` 时，系统会自动去掉这些参数重试，
并切换到纯文本 `TOOL_CALL` 工具协议（免 Key 端点常见）。

**「可选模型」与一键获取（2.4.3 新增）**：设置页「模型」tab 里，「模型 ID」是主模型（顶栏默认用它）；
「可选模型」是可增删的多行输入框（每条一个模型 ID），保存后顶栏「模型」下拉会列出这些备选，方便单对话临时切模型。
填好 API Base URL 与鉴权方式后，点「**获取可用模型**」会从上游 OpenAI 兼容的 `/models` 端点拉取全部模型 ID 并自动填入，
省去手动抄写；若该端点不支持列模型（如免 Key 的 OVH / Pollinations）会提示失败，换带 Key 的提供方即可。
主模型始终以「模型 ID」框为准，获取按钮不会覆盖它（仅在它为空时把首个获取到的模型设为默认）。

### HTTP 429 限流：自动故障转移（2.1 新增）

**症状**：连着几条消息都回 `模型没有返回内容：上游 HTTP 429：{"message":"API rate limit exceeded"...}`。
原因不是代码错，是**免费端点按服务器出口 IP 限流**（同一端点从本机测可能 200、从服务器测 429）。

现在的行为：

1. **自动换线路**：主路由返回 429 / 5xx / 连接失败时，自动尝试下一条免 Key 线路
   （默认顺序：主路由 → OVH → Pollinations openai-fast → Pollinations openai），
   单条消息最多额外切 3 次。400 / 401 / 403 / 404 属于配置问题，不盲目乱切。
2. **故障线路冷却**：失败线路默认冷却 120 秒，冷却期内直接跳过，不会每条消息都重撞 429；
   全部线路都在冷却时，只挑最早恢复的那条试一次。健康状态存在本站数据库里，跨请求共享。
3. **友好报错**：不再把上游 JSON 原样贴给用户，改成中文说明 + 已尝试线路 + 排障建议。
4. **本地兜底**：所有线路都挂时，算式（`1+1=？` → `1+1 = 2`）、时间/日期类问题由内置插件
   直接回答，并标注「离线兜底」；其它问题给中文排障提示，不会假装是模型答的。
5. **网页管理**：「设置 → 线路」可开关自动转移、改冷却秒数、停用某条候选、调整顺序、
   一键「测试全部线路」、清除冷却。对话状态栏会显示本次实际使用的线路。
6. **对外 API 同样受益**：`v1/chat/completions` 也走同一套网关，无需调用方改动。

> 最稳的做法仍然是**填自己的 API Key**（DeepSeek / OpenRouter / 硅基流动等），
> 免费线路只保证「能聊」，不保证「随时能聊」。如果服务器出海受限导致 Pollinations 也连不上，
> 只有本地兜底能回答确定性问题，其余会显示排障提示。

---

## 四、对话

- 多会话：新建 / 切换 / 重命名 / 清空 / 删除；首条消息自动生成标题。
- 消息持久化在 `data/`，刷新页面不丢。
- 模型下拉、工具开关、流式开关、系统提示词、温度、最大 Token、历史条数、工具轮数都可在设置页调。
- **系统提示词预设（2.4.2 新增）**：设置页「系统提示词」上方有一个预设下拉，内置 7 个角色模板
  （通用助手 / 编程助手 / 中文文案写手 / 中英翻译专家 / 严谨分析顾问 / 耐心讲解老师 / 创意脑暴伙伴），
  选中即把模板填入文本框（仍可继续编辑），保存后作为默认系统提示词。
- **会话级角色**：顶栏新增「角色」下拉（与模型并排）。切换时立即应用到当前对话，并作为新建对话的默认角色；
  不同对话可以用不同系统提示词，互不干扰。会话级角色存在 `sessions` 表的 `system` 列（SQLite / JSON 双后端都持久化），
  选「默认（站点设置）」即回退到上面设置的站点默认。
- **流式输出**：SSE 阶段事件（思考 → 工具 → 分段文本 → 完成）。部分 PHP 主机会缓冲 SSE，
  前端收不到第一个事件时会自动降级成普通请求；默认关闭，可在设置页开启。
- 「停止」只断开前端；服务端可能仍会完成本次生成并保存结果（诚实说明，不做假中断）。

---

## 五、插件

两种方式：**网页「设置 → 插件市场」一键安装内置的 48 个**（推荐，见下节），
或自己写文件。**加插件 = 往 `plugins/` 丢一个 `.php` 文件，刷新页面即可，无需重启、无需改代码。**

```php
<?php
return [
    'id'          => 'demo.hello',
    'name'        => '示例插件',
    'description' => '打个招呼',
    'version'     => '1.0.0',
    'author'      => 'me',
    'icon'        => 'smile',
    'enabled'     => true,                    // 默认是否启用
    'tools'       => [[                       // 模型可自动调用的工具（OpenAI JSON Schema）
        'name'        => 'say_hello',
        'description' => '向指定的人打招呼',
        'parameters'  => [
            'type' => 'object',
            'properties' => ['who' => ['type' => 'string', 'description' => '名字']],
            'required' => ['who'],
        ],
    ]],
    'slash' => ['hello' => '/hello 直接调用'],  // 可选：网页输入 /hello 不耗模型
    'run' => function (string $tool, array $args, array $ctx): array {
        // $ctx: store / config / plugin / session_id
        return ['ok' => true, 'text' => '你好，' . ($args['who'] ?? '世界') . '！'];
    },
];
```

工具在模型侧的实际名字会加前缀（如 `demo_hello__say_hello`），避免冲突。
插件可在网页左侧一键开关；关闭后不再进入工具表。

### 随站点启用的 7 个基础插件（不含市场插件）

| 插件 | 工具 | 说明 |
|---|---|---|
| `core.time` | `get_time` | 时间、日期、时间戳、`/time` |
| `core.calc` | `calculate` | 安全表达式求值（递归下降解析，**不用 eval**）、`/calc` |
| `core.http` | `http_get` | 读公开网页，带 SSRF 防护（禁内网/本机/私网 IP），默认关闭 |
| `core.note` | `note` | 会话笔记，存服务器，`/note` |
| `core.system` | `system_status` | 自检，不泄露路径/密钥，`/ping` |
| `core.text` | `text_tool` | 字数/大小写/Base64/哈希/JSON 美化 |
| `core.chain` | `run_chain` | 串行编排多个工具，默认关闭 |

### 插件市场（2.2 新增 · 2.4 起内置 48 个）

在 **「设置 → 插件市场」** 或左侧「＋ 市场」打开。支持搜索、按 10 类筛选、
单个安装/卸载/启停、**安装推荐套装（8 个）**、全部安装、全部卸载、按分类批量装。
2.4 新增「美化」「实用」两类共 6 个界面增强插件，它们用第十二节的 GUI API，**装完需刷新一次页面**。

**为什么不需要写文件权限**：市场插件源码常驻 `market/*.php`，「安装」只是往数据库
`kv` 写一条 id；`plugin_scan()` 会把已安装的 market 文件一起纳入，跟 `plugins/*.php`
走完全相同的加载路径。所以 FTP 上传后即使 `plugins/` 对 PHP 不可写，也能一键装插件；
卸载只删数据库记录，你自己上传到 `plugins/` 的文件永远不受影响。

**装完立刻生效**：安装时自动启用，模型能把插件工具当作 function calling 调用，
也能在对话框用 `/命令` 直调（不消耗模型额度）。`api/market/run` 可直接试运行。

> ⚠️ **工具太多会拖慢免费模型**：全部安装 = 48 个工具 schema，每次请求都要带上，
> 免 Key 端点更容易返回 400 或选错工具。日常建议只留「推荐套装」，按需再装几个。
> 设置页「对话」里可整体关闭工具，单个插件在左侧列表点一下也能停用。

**推荐套装**：`mkt.wordcount` `mkt.json` `mkt.hash` `mkt.unit` `mkt.timestamp` `mkt.ip` `mkt.password` `mkt.todo`

| 分类 | 插件（id · 名称 · /命令） |
|---|---|
| 文本处理 7 | `mkt.wordcount` 字数统计 `/wordcount` · `mkt.encode` 编码解码 `/encode` · `mkt.diff` 文本比对 `/diff` · `mkt.template` 模板渲染 `/tpl` · `mkt.lorem` 中文占位文本 `/lorem` · `mkt.extract` 内容提取 `/extract` · `mkt.slug` 命名与别名 `/slug` |
| 开发编码 8 | `mkt.hash` 哈希校验 `/digest` · `mkt.base` 进制转换 `/base` · `mkt.json` JSON 工具 `/json` · `mkt.regex` 正则测试 `/regex` · `mkt.jwt` JWT 解析 `/jwt` · `mkt.url` URL 解析 `/url` · `mkt.semver` 版本比较 `/semver` · `mkt.cron` Cron 表达式 `/cron` |
| 计算数据 7 | `mkt.unit` 单位换算 `/unit` · `mkt.percent` 百分比计算 `/pct` · `mkt.finance` 财务计算 `/finance` · `mkt.stats` 统计描述 `/stats` · `mkt.random` 随机与抽签 `/draw` · `mkt.csv` CSV 工具 `/csv` · `mkt.bignum` 大数运算 `/bignum` |
| 时间日期 4 | `mkt.timestamp` 时间戳转换 `/ts` · `mkt.datediff` 日期差计算 `/datecalc` · `mkt.countdown` 倒计时 `/countdown` · `mkt.dayinfo` 日期速查 `/dayinfo` |
| 网络系统 5 | `mkt.ip` IP 与网段 `/ip` · `mkt.httpcode` HTTP 状态码 `/httpcode` · `mkt.mime` MIME 速查 `/mime` · `mkt.env` 环境自检 `/env` · `mkt.ua` UA 解析 `/ua` |
| 生活校验 4 | `mkt.idcard` 身份证校验 `/idcard` · `mkt.phone` 手机号校验 `/phone` · `mkt.color` 颜色转换 `/color` · `mkt.password` 密码工具 `/pw` |
| 趣味创作 5 | `mkt.morse` 摩斯电码 `/morse` · `mkt.caesar` 凯撒密码 `/caesar` · `mkt.decision` 决策助手 `/decide` · `mkt.fortune` 今日运势 `/fortune` · `mkt.emoji` Emoji 速查 `/emoji` |
| 效率管理 2 | `mkt.todo` 待办清单 `/todo` · `mkt.memory` 键值记忆 `/mem`（这两个会把数据存到服务器数据库） |
| **美化 2（2.4）** | `mkt.sidebar` 更好的侧边栏（搜索/置顶/日期分组）· `mkt.glass` 透明玻璃背景（背景图默认**完全清晰**，背景模糊与面板毛玻璃分开调；亮度、**主区浓度**：侧栏/顶栏/输入区/中间聊天区全部透出背景） |
| **实用 4（2.4）** | `mkt.context` 上下文用量显示 · `mkt.vtree` 记忆虚拟目录树 `/vtree` · `mkt.passwords` 更多登录密码 + 3×3 手势密码 · **`mkt.browser` 内嵌浏览器 · HTML 实时预览** |

旧 42 个插件**全部纯本地计算、零扩展依赖**，不联网、不读密钥、不打印真实路径；
新增的 6 个界面插件只做界面增强：`mkt.vtree` 往服务器 `kv` 写一棵上限 40KB 的树，
`mkt.passwords` 往 `config.php` 写 bcrypt 凭据，`mkt.browser` 全程只在浏览器内存里渲染 HTML（不写服务器、不新增后端接口），
其余偏好只存浏览器 `localStorage`。
身份证/手机号类校验只做格式与校验码运算，输出自动脱敏。
正则插件禁用递归引用与 `e` 修饰符；网页读取类插件延续 `core.http` 的 SSRF 防护思路。

**往市场里加自己的插件**：把一个符合上面格式的 `.php` 放进 `market/`，刷新市场页就会出现。
额外可写 `category`（上面 10 个 key 之一）、`tags`（数组）、`risk`（`local`/`read`/`store`）、
`usage`（示例用法），以及可选的 `ui`（自定义界面清单，见第十二节）。
⚠️ 文件里**只 `return` 一个数组，不要定义顶层 class / function**——
`plugin_scan()` 在一次请求里可能多次 `require` 同一文件，重复声明会 fatal。

### 技能

`skills/*.md`，格式与桌面版 `SKILL.md` 一致。**2.4.1 内置 16 个技能**（2 个常驻 + 14 个按需）：

```markdown
---
name: 正则表达式
description: 写或解释正则、提取字符串、校验格式时使用
when: 正则，regex，匹配，提取，校验格式，preg_match
default: auto
---
## 输出顺序
1. 规则 → 2. 正则 → 3. 逐段解释 → 4. 正反例各 3 个
```

| frontmatter | 说明 |
|---|---|
| `name` / `description` | 名称与用途；列表里显示 |
| `when` | 可选。触发词，逗号 / 中文逗号 / 顿号 / 竖线分隔，最多 24 个 |
| `default` | 可选。`on` 常驻 / `auto` 按需 / `off` 默认关闭。写了 `when` 未写 `default` 视为 `auto`；都没写视为 `on` |

**三种模式**：`常驻` 每轮注入；`按需` 只有本轮用户消息（连同最近几条用户消息）命中 `when` 才注入，
按命中词数排序、**单轮最多 3 个**；`关闭` 不注入。触发词用 `stripos` 做字节匹配，中英文都支持，
不依赖 mbstring。这样技能库扩到 16 个也不会把系统提示词撑爆（老技能仍是常驻，零回归）。

网页左侧「技能」面板点击循环切换 `关闭 → 常驻 → 按需 → 关闭`；`api/skill/toggle` 传 `mode=auto`
即清除显式开关回到默认。技能正文最多取 6000 字，单文件建议 < 3000 字；`skill_list()` 不返回正文。

⚠️ 解析 frontmatter 时用显式 `\r?\n` 而非 `\R`——字节模式下 `\R` 会把 UTF-8 续字节
`0x85`（如「清」）当换行，切出半个汉字导致整段 JSON 编码失败。

**内置 16 个技能一览**（`skills/README.txt` 有字段说明）：

| 常驻（2） | `assistant-basics` 回答风格与工具规范 · `code-review` 代码审查清单 |
|---|---|
| 按需 · 写作（3） | `writing-polish` 中文写作润色 · `summarize` 长文总结 · `translation` 中英互译 |
| 按需 · 网页（2） | `html-preview` 生成可实时预览的完整 HTML（配合内嵌浏览器插件）· `web-design` 视觉与交互设计 |
| 按需 · 工程（4） | `php-deploy` PHP 子目录部署排障 · `debug-method` 报错排障 · `sql-helper` SQL · `regex-helper` 正则 |
| 按需 · 通用（3） | `data-analysis` 数据分析 · `prompt-craft` 提示词设计 · `learning-tutor` 概念讲解 |
| 按需 · 本站（2） | `plugin-dev` dshphp 插件/技能开发 · `automation-cron` 定时任务与 cron |

自己加技能：往 `skills/` 放一个 `.md`，刷新页面即出现在列表；不带 `when` 就是常驻，带了就是按需。

---

## 六、对外 API（给别的程序用）

统一入口 `index.php?r=`，鉴权用 `X-API-Key: <你的API Key>` 或 `Authorization: Bearer <API Key>`。

**OpenAI 兼容（推荐，可直接把 dshphp 当模型网关）：**

```bash
curl -X POST 'https://你的域名/dshphp/index.php?r=v1/chat/completions' \
  -H 'X-API-Key: 你的API Key' -H 'Content-Type: application/json' \
  -d '{"model":"gpt-oss-20b","messages":[{"role":"user","content":"你好"}]}'
# GET index.php?r=v1/models  列出可用模型
# stream:true 返回 SSE chunk + [DONE]
```

**原生接口：**

| 路由 | 说明 |
|---|---|
| `api/status` | 版本、存储后端、插件/工具数、当前模型 |
| `api/session/list` `create` `get` `rename` `delete` `clear` | 会话 CRUD |
| `api/chat/send` | `{"sid":"...","text":"...","tools":true}` 完整一轮对话 |
| `api/chat/stream` | 同上，返回 SSE 阶段事件（含 `switch` 换线路阶段） |
| `api/plugin/list` `toggle` `run` | 插件列表 / 开关 / 直调（含已安装市场插件） |
| `api/market/list` | 48 个市场插件元数据、10 个分类、安装状态、工具总数 |
| `api/market/install` `uninstall` | `{"id":"mkt.hash"}` 安装（自动启用）/ 卸载 |
| `api/market/batch` | `{"preset":"recommended"\|"all"\|"none"}`、`{"category":"fun"}`、`{"ids":[...],"mode":"merge"\|"remove"}` |
| `api/market/run` | `{"id":"mkt.hash","args":{...}}` 试运行已安装插件 |
| `api/skill/list` `toggle` | 技能列表 / 开关 |
| `api/settings/get` `save` `test` | 设置读写与连通性测试（save 支持 `routing` 段） |
| `api/routes/list` | 候选线路清单、冷却剩余时间、故障次数（不返回 Key） |
| `api/routes/test` | 逐条测试所有启用线路，返回每条 HTTP 状态与回复 |
| `api/routes/reset` | 清除所有线路冷却 |
| `api/automation/*` | 自动化任务：任务表、启停、立即运行、增删改、日志、JSON 导入导出（详见第九节） |

2.3.1 起 **没有独立的心跳端点**：调度只在 `index.php` 被访问时推进，
`cron.php` 若存在只是一个返回 410 的停用占位页，不执行任何任务。

`api/chat/send` 的响应新增三个字段：`route_used`（本次实际线路）、`tried`（尝试过的线路与状态）、
`offline`（是否为本地兜底）。流式端点在换线路时会推 `event: stage` 且 `stage=switch`。

浏览器端用会话登录 + CSRF（`X-CSRF-Token`），脚本用 API Key，两条路径互不干扰。

---

## 七、安全

- `install.php` 生成随机数据文件名写进 `app/storage.php`（`.php` 会被执行而非下载），
  **页面、ping、插件自检都不打印真实文件名**；这是 security by obscurity，不是访问控制。
  有服务器配置权限的话，额外加一条 `location ~ ^/dshphp/data/ { deny all; }` 更稳。
  自动化任务的 JSON 文件名由这个随机名二次派生，同样不在页面显示。
  2.3.1 起不再生成任何心跳密钥：读到旧 JSON 里的 key 会自动清空，导出的 JSON 不含密钥；
  外部心跳入口 `cron.php` 已停用（返回 410，不鉴权、不执行任务）。
- 登录有失败锁定（8 次锁 5 分钟）、CSRF、API Key、`hash_equals` 定时安全比较。
- `core.http` 插件会拒绝 `127.*` / `10.*` / `192.168.*` / `169.254.169.254` 等地址，
  并校验 DNS 解析结果，限制端口、超时与响应大小。
- 计算器不用 `eval`；所有模型/用户输入在前端渲染前做 HTML 转义。
- `config.php` / `plugins/*.php` / `market/*.php` / `app/market.php` 直接访问只会执行 `return`
  或定义函数，输出为空，不会泄露内容（已实测 HTTP 200 / 0 字节）。
  市场接口只接受 `[A-Za-z0-9_.-]` 的白名单 id，`../` 之类路径遍历会被拒绝。
- `tools/check-plugins.php` 仅允许 CLI；其它 `tools/*.mjs` 是开发期脚本，可不上传。
- 可选加固：`skills/*.md` 是纯文本，若不想公开可自行加 nginx deny 规则。

---

## 八、自检复现

本机验证过 **PHP 8.4.25，全部 60+ 个 PHP 文件语法 0 错误**；
用 `php -n -S`（**不加载任何 php.ini，无 curl、无 mbstring、无 pdo_sqlite**）配 mock 上游：

| 自检 | 结果 | 覆盖 |
|---|---|---|
| `tools/selfcheck.mjs` | **133 通过 / 0 失败** | 安装 → 登录/CSRF/401/403 → 会话 CRUD → 工具循环 → 400 降级 → 文本工具协议 → /命令 → 插件/技能开关 → 设置/SSE/OpenAI 兼容接口 → **429 换线路/冷却/兜底** → **插件市场 35 项** |
| `tools/check-plugins.php` | **48 个插件 / 48 个成功** | 逐个实跑典型用法（2.4 起纯界面插件只校验 ui 清单），验证加载、返回值、元数据一致性 |
| `tools/check-skills.php`（2.4.1） | **16 通过 / 0 失败** | 16 个技能 frontmatter 解析、2 常驻 + 14 按需、触发词命中与未命中隔离、单轮上限 3、旧调用签名兼容、三态开关与 `mode=auto` 复位 |
| `tools/ui-check.mjs` | **18 通过 / 0 失败** | 真实 Edge 浏览器：市场弹窗、48 张卡片、搜索、分类筛选、安装/卸载、侧边栏同步 |
| `tools/auto-ui-check.mjs` | **18 通过 / 0 失败** | 真实 Edge 浏览器：自动化页签、10 张任务卡片、访问驱动说明、无心跳 URL/密钥、统计/日志、编辑表单、立即推进、**静置 4 秒零后台请求**、零 JS 异常 |
| 2.3.2 线路实测 | **通过** | 修复后主路由 + Pollinations 免费均 200 且真实对话成功；无扩展环境兜底精确指出「PHP 未启用 openssl」；`ping.php?net=1` 在正常/故障两种环境结论均正确 |
| `tools/check-ui.php`（2.4） | **通过** | 6 个 ui 插件聚合、login scope 只暴露 2 个、48 个市场插件扫描、上下文估算、`files` 白名单拒绝 `../` 越界读取 |
| `tools/http-check.php`（2.4） | **通过** | 登录页资源注入、匿名 login bundle 200、app bundle 未登录 401 / 带 API Key 200 且含 6 插件、CSS 聚合、老接口 `api/status` 与 `api/market/list` 回归（含新增 2 个分类） |
| `tools/runtime-ui-check.mjs`（2.4） | **12 通过 / 0 失败** | 真实 Edge：登录页 DSH 运行时、2 个 login 插件初始化、9 点手势点阵、手势轨迹写入 `name=password`、玻璃样式与动态 CSS、零 JS 异常 |
| `tools/app-ui-check.mjs`（2.4） | **15 通过 / 0 失败** | 真实 Edge 主界面：6 个插件初始化、`DSH.core` 能力挂载、`app:ready` 生命周期、侧边栏渲染器接管、上下文仪表真实接口、记忆树浮层面板、零 JS 异常 |
| `tools/glass-ui-check.mjs`（2.4） | **26 通过 / 0 失败** | 真实 Edge：`.main` 半透明、背景默认 0px 全清晰、面板毛玻璃 4px、两种模糊互不干扰、清晰时不再放大裁边、主区浓度滑杆 0% 全透、旧配置缺 `main` 自动补 38%、旧全局设置与旧版 12px 模糊自动迁移、零 JS 异常 |
| `tools/browser-ui-check.mjs`（2.4） | **27 通过 / 0 失败** | 真实 Edge：右侧分栏不遮挡聊天区、流式 delta 500ms 实时渲染、iframe 脚本可执行但拿不到 `parent.document`（沙箱隔离）、父页面读不到 opaque iframe、历史 ◀▶、源码视图、本地 .html、关闭还原布局、零 JS 异常 |
| `tools/skills-ui-check.mjs`（2.4.1） | **16 通过 / 0 失败** | 真实 Edge：16 张技能卡片、2 常驻 + 14 按需、触发词展示、三态循环（按需→关闭→常驻→按需）与服务端 `mode` 同步、老技能仍两态、零 JS 异常 |
| `tools/presets-ui-check.mjs`（2.4.2） | **13 通过 / 0 失败** | 真实 Edge：boot 注入 7 个预设、设置页下拉含「自定义 + 7 角色」、选预设填入文本框、顶栏「角色」下拉存在、切换角色写入当前会话 `system`、新建对话按所选角色带上 `system`、选「默认」回退、零 JS 异常 |
| `tools/check-models.php`（2.4.3） | **通过** | 新增 `api/settings/models` 路由、`llm_parse_models()` 解析 `{data:[{id}]}` / `{models:[...]}` / 纯数组三种返回、去重去空、空 Base URL 友好报错、`index.php` 已注册路由 |

```bash
cd dshphp
node tools/mock-llm.mjs &                       # mock 上游 127.0.0.1:18765
php -n -S 127.0.0.1:8766 -t . &                # PHP 内置服务器
node tools/selfcheck.mjs                        # 133 项断言
php -n tools/check-plugins.php                  # 48 个插件逐个实跑
php -n tools/check-skills.php                   # 16 项技能解析与按需注入断言
# 浏览器自检另需：Edge --headless=new --remote-debugging-port=9222 --remote-allow-origins=*
node tools/ui-check.mjs                         # 18 项 UI 断言 + tools/market-ui.png
node tools/browser-ui-check.mjs                 # 27 项内嵌浏览器断言（含沙箱隔离验证）
node tools/skills-ui-check.mjs                  # 16 项技能三态面板断言（需 _uitest-session.php）
```

mock 上游的 `/limited/*` 路径恒定返回 429，用来复现限流。
自检会生成 `config.php` / `app/storage.php` / `data/s_*`，跑完删掉即可恢复未安装状态。

---

## 九、自动化任务（2.3 新增，2.3.1 改为纯访问驱动）

「设置 → 自动化」里上线了 **10 个开箱即用的任务**。任务表、下次执行时间、运行结果全部存成
服务器上的一个 **JSON 文件**；不新增配置项、不改数据库、不需要 SSH。

### 运行方式：只在 index.php 被访问时执行

**2.3.1 起唯一的驱动方式就是「有请求访问 `index.php`」。** 请求进来时推进一次调度，
按每个任务的 `next_run` 判断，只有到期的任务才执行；没有访问就完全不执行。

明确地**没有**：

- ❌ 前端定时心跳（页面开着但不操作，不会自动打点）
- ❌ 外部 cron / `cron.php` 保活（该文件已停用，只返回 410）
- ❌ 常驻进程、系统计划任务、WP-Cron 式的自我唤醒
- ❌ 后台守护、队列、任何定时器

这是纯 PHP-FPM 主机的真实边界：PHP 只在被请求时运行，**零请求时不会自己醒来**。
2.3.0 曾用三层心跳抹平这个边界，2.3.1 按需求全部移除，行为变得很直白：

> **有访问 → 检查并执行到期任务；没访问 → 什么都不做。**

| 项 | 行为 |
|---|---|
| 触发 | 任意 `index.php` 请求（页面、API、`?r=...` 都算），在路由分发前自动推进一次 |
| 合并窗口 | 10 秒内的高频访问合并为一次推进，避免反复写盘；**这不是保活间隔** |
| 到期判断 | 每个任务各自的 `next_run`，未到期直接跳过 |
| 并发 | `flock` 独占锁，多个请求同时到达也只跑一次 |
| 预算 | 单次最多执行 12 个任务，超出的顺延 60 秒，不会拖死一条请求 |
| 补跑 | 不做补跑风暴：停了三天后第一次访问，只会把过期任务各跑一次，再按新周期继续 |
| 手动 | 面板「立即推进」按钮也是一次普通访问，等价于刷新一次页面 |

所以「每天 09:00 的任务」只有在 09:00 之后**有访问**时才真正执行；整站一整天没人访问，
它就整天不执行，也不会在夜里自己补上。需要严格准点，只能由你自己安排外部定时去访问
`index.php`（那是外部访问，不是本站的保活系统）——本站内部不再提供任何这类能力。

### 10 个内置任务

| ID | 调度 | 动作 |
|---|---|---|
| `auto.heartbeat` | 每 5 分钟 | 写一条运行打点，用来确认最近有访问推进过调度（ID 沿用旧版，不是心跳保活） |
| `auto.watchdog` | 每 1 分钟 | 调度器自检（任务数、存储、PHP） |
| `auto.storage` | 每 10 分钟 | 存储健康检查（目录可写、会话/消息量） |
| `auto.hourly-time` | 每小时整点 | 调 `core.time` 记录服务器时间 |
| `auto.morning` | 每天 09:00 | 调 `core.system` 系统自检 |
| `auto.daily-digest` | 每天 23:55 | 当日成功 / 失败汇总 |
| `auto.log-prune` | 每天 00:05 | 输出日志裁剪结果（实际裁剪在每次推进末尾） |
| `auto.weekly` | 每周一 08:30 | 最近 7 天统计 |
| `auto.snapshot` | 每天 02:00 | 把任务表结构写进日志，便于回溯改动 |
| `auto.midnight` | 每天 00:00 | 跨日标记 |

全部是本地动作、零外部依赖、不联网、不打印路径或密钥。升级后首次访问会各跑一次
（相当于上线自检），之后按各自周期执行。

### 自定义任务

面板「＋ 新增 / 编辑任务」里填：ID、名称、动作类型、调度、参数 JSON、备注。
调度支持 **固定间隔**（秒，最小 30）与 **5 段 Cron 表达式**（`分 时 日 月 周`，支持 `* , - /`，
如 `*/5 * * * *`、`30 8 * * 1`）。每个任务可单独启停、立即运行、编辑、删除。

| 类型 | 参数示例 | 说明 |
|---|---|---|
| `log` | `{"message":"打点 {time}"}` | 占位符 `{time} {date} {ts} {week}` |
| `plugin` | `{"plugin":"core.time","tool":"get_time","args":{}}` | 调任意已启用插件，不耗模型额度 |
| `health` | `{"detail":true}` | 存储、目录权限、任务数自检 |
| `digest` | `{"scope":"today"}` 或 `{"scope":"week"}` | 运行日志统计 |
| `prune` / `snapshot` | `{}` | 日志裁剪 / 配置快照 |
| `http` | `{"url":"https://example.com/","method":"GET"}` | webhook / 自定义回调；带 SSRF 防护，禁内网与本机地址 |

**JSON 存在哪**：`data/<随机目录>/auto_<随机>.json`。文件名由 `app/storage.php` 里的随机数据名
派生，外部猜不到；`.php` 配置文件会被执行而非下载。想直接改文件也行：面板「导出 JSON」下载 →
编辑 → 「导入 JSON」覆盖，不必去 FTP 里找那个随机名。运行日志默认最多留 200 条，可改。
**导出的 JSON 不含任何密钥**（旧版心跳 key 会在读取时自动清空）。

### 对外接口

| 路由 | 说明 |
|---|---|
| `index.php`（任意请求） | **唯一的调度驱动**：访问即推进一次，到期任务才执行 |
| `api/automation/list` | 任务表 + 最近 60 条日志 + 统计信息（含 `driver=access-only`、`keepalive=false`） |
| `api/automation/tick` | 手动推进一次调度（跳过 10 秒合并窗口，仍只跑到期的任务） |
| `api/automation/toggle` / `save` | 单个任务启停 / 总开关与日志上限 |
| `api/automation/run` | 立即执行指定任务（无视启停与下次时间） |
| `api/automation/upsert` / `delete` | 新增或编辑 / 删除任务（删空会自动恢复 10 个默认） |
| `api/automation/logs` / `export` / `import` | 运行日志 / 导出原始 JSON / 覆盖任务表 |

`api/*` 走原有鉴权（会话 + CSRF 或 `X-API-Key`）。**没有独立的心跳端点**：
`cron.php` 只是返回 `410 Gone` 的停用占位页，不鉴权、不加载引擎、不执行任务。
`api/status` 含 `automation` 段，`ping.php` 含 `automation_driver=access-only keepalive=no cron=disabled`
以及 `automation_tasks` / `automation_ticks` / `automation_file` 等自检行。

> **诚实说明**：站点完全没人访问时，任务就停在那里，不会自己醒来，也不会补跑；
> 下一次访问到来后按 `next_run` 各执行一次、再按新周期继续（不做补跑风暴），
> 避免几十个过期任务同时挤进一条请求把 PHP-FPM 打满。

---

## 十、模型线路全部「连不上」怎么排查

先分清两种情况：**HTTP 429 / 402 是限流**，等几分钟就好；
**「连不上」（status=0）是请求根本没发出去**，跟免费额度无关，几乎都是服务器环境问题。

### 一句话定位

访问 `ping.php?net=1`（排障完照例删掉 `ping.php`）：

```
[网络自检 ?net=1]
transport=curl
curl=yes openssl=yes allow_url_fopen=yes
ca_bundle=auto(系统证书存储) native_ca=yes
route=主路由 host=oai.endpoints.kepler.ai.cloud.ovh.net dns=ok http=404 ms=1505 connect=ok
route=Pollinations 免费（免 Key） host=text.pollinations.ai dns=ok http=200 ms=1083 connect=ok
verdict=网络与 TLS 可达；若聊天仍失败，去「设置 → 线路」点「测试全部线路」看鉴权/上游错误
```

只看两点：`curl` / `openssl` 是否为 `yes`，以及每行的 `connect` 与 `reason`。
`verdict=` 会直接给结论。它只探测 `config.php` 里配置的固定地址，不接收任意 URL、不打印密钥。

### 根因对照表

| 自检输出 | 原因 | 怎么办 |
|---|---|---|
| `curl=no openssl=no` | PHP 精简版没装扩展，或 `php.ini` 的 `extension_dir` 指错目录 | 启用 `curl` / `openssl`；本机用 `php-local.ini`（见下） |
| `reason=TLS 证书校验失败` | 服务器没配 CA 根证书 | 2.3.2 已自动改用系统证书存储；仍失败再配 `curl.cainfo` / `openssl.cafile` |
| `reason=DNS 解析失败` | 服务器 DNS 不通 | 检查 DNS / hosts，或换可直连端点 |
| `reason=连接超时 / 网络不可达` | 出境被阻断（境内主机访问境外免费 API 常见） | **填自己的国内可直连 Key**（DeepSeek / 硅基流动等）最稳 |
| `connect=ok` 但聊天仍失败 | 网络没问题，是鉴权 / 上游返回 | 「设置 → 线路」点「测试全部线路」，看每条的具体报错 |

### 本机 Windows 专用配置（绝不上传）

本机 `D:\php\php.ini` 的 `extension_dir` 曾指向一个不存在的目录，导致 curl、openssl、
pdo_sqlite、mbstring 全部加载失败，表现就是**三条线路全「连不上」**——其实端点都活着。
`php-local.ini` 把路径改回 `D:/php/ext` 并显式启用扩展，`run-local.bat` 用 `php -c` 指定它，
只影响本机启动的服务器，不修改全局 `php.ini`、不影响其它项目。

> ★ **绝不把 `php-local.ini`（或任何本机专用 `php.ini`）上传到站点根目录**：
> 服务器 PHP 读到写死的本机路径会起不来，全部 `.php` 页面会变成下载。部署包已排除它。
> 换机器时只需改 `php-local.ini` 里的 `extension_dir` 一行。

### 修复前后实测（2026-11-13）

- 修复前：三条线路全部 `status=0`，兜底只说「连不上」，技术细节里也是同一句汇总。
- 修复后：主路由 `ovh-free` 与 `Pollinations 免费` 均 200 且能真实对话；
  第三条是 Pollinations 的 402 免费额度限制（每 IP 45 秒一次），属正常限流。
- 故意用无扩展 PHP 复现故障：兜底提示会明确写出
  「PHP 未启用 openssl 扩展，无法建立 https 连接」并引导到 `ping.php?net=1`；
  时间 / 算式类问题仍由本地插件直接回答，不受影响。

---

## 十一、文件清单

```
index.php          唯一入口（页面 + 全部 API + SSE + 访问时自动推进调度）
cron.php           2.3.1 停用占位页（410，仅用于覆盖线上 2.3.0 旧文件；新站点可不传）
install.php        首次安装向导（装完删除）
ping.php           环境自检（?net=1 出站网络自检；排障后删除）
run-local.bat      本机一键启动（用 php -c php-local.ini 修正扩展目录）
php-local.ini      ★ 本机专用 PHP 配置，绝不上传服务器（部署包已排除）
config.php         运行时生成，含密码哈希与 API Key
app/
  bootstrap.php    配置、鉴权、CSRF、HTTP 双通道（含系统 CA 自动兜底）、UTF-8 工具
  store.php        SQLite / JSON 双后端，会话与消息持久化
  llm.php          OpenAI 兼容网关 + 多线路故障转移 + 具体错误分类 + 工具循环编排 + 本地兜底（含技能触发上下文）
  plugins.php      插件/技能引擎（合并已安装的市场插件；透传可选 ui 清单；技能按需注入与三态开关）
  market.php       插件市场引擎（扫描/安装/卸载/批量/10 个分类）
  plugin-ui.php    ★ 2.4 插件 GUI API 后端：ui 清单校验、CSS/JS 聚合、资源白名单、用量记录
  view.php         登录页 + 工作台 HTML（含自动化面板、8 个 GUI 挂载容器）
  automation.php        自动化任务引擎（JSON 配置、10 个默认任务、cron/interval 调度、锁、日志）
  automation.actions.php 任务动作执行器（log/plugin/health/digest/prune/snapshot/http）
  storage.php      运行时生成，随机数据文件名
market/*.php       48 个内置市场插件（必须上传；其中 6 个带 ui 清单）
plugins/*.php      7 个随站点启用的内置插件
skills/*.md        16 个内置技能（2 常驻 + 14 按需）+ README.txt 字段说明（必须上传）
assets/
  style.css        基础样式（含 GUI 挂载点、浮层面板、toast）
  app.js           主前端（2.4 起埋正式生命周期事件 + 渲染器扩展点）
  dsh-ui.js        ★ 2.4 window.DSH 插件运行时（事件/状态/挂载/存储/API/隔离执行）
  automation.js    自动化面板（独立 IIFE）
data/              运行时数据（自动创建；含自动化任务的随机名 JSON）
tools/             mock 上游、端到端自检、插件实跑、浏览器 UI 自检（可不上传）
```

---

## 十二、插件 GUI API（2.4 新增 · 有侵入式扩展点）

2.3 里的插件只能返回纯文本，改不了界面。2.4 直接改核心，给插件一条**正式**的界面扩展通道：
插件清单里多写一个可选的 `ui` 段，服务端把**已安装 + 已启用**插件的 CSS/JS 聚合成两个资源端点，
前端运行时 `window.DSH` 提供稳定的 API，`app.js` 在关键位置主动发事件、暴露渲染器扩展点。
**不是** monkey-patch `fetch`、也不是靠 `MutationObserver` 猜 DOM。

### 清单契约（`ui` 段，全部可选）

```php
return [
    'id' => 'my.uiplugin', 'name' => '我的界面插件',
    'category' => 'beauty',          // 新增：beauty 美化 / utility 实用
    'tools' => [], 'run' => null,     // 老字段照旧，ui 插件可以完全没有工具
    'ui' => [
        'apiVersion' => 1,                     // 目前只接受 1，其它值会被忽略
        'scope'       => ['app'],              // app（主界面）/ login（登录页），可两个都写
        'css'         => '.my-cls{color:var(--accent)}',   // 内联 CSS，≤64KB
        'js'          => 'DSH.ready(function(D){ ... });', // 内联 JS，≤128KB
        'files'       => ['css' => ['extra.css'], 'js' => ['extra.js']], // 可选外部资源
        'mounts'      => ['sidebar-top', 'status'],  // 声明用，供 UI 展示
        'permissions'=> ['storage', 'api', 'theme'],// 声明用
        'actions'     => ['ui.xxx'],                 // 声明用
    ],
];
```

- `files` 只接受**纯文件名**（`^[A-Za-z0-9_.-]+\.(css|js)$`），必须与插件 `.php` 同目录，
  `realpath` 校验禁止 `..` 与子目录穿越，单文件 ≤128KB。端点**不接受任何用户传入的路径**。
- 老插件没有 `ui` 时行为与 2.3.2 完全一致；`plugin_scan()` 只多带一个私有 `path` 字段（不下发前端）。
- 禁用 / 卸载 / 损坏的插件**不会**出现在聚合结果里；资源带 `ETag` 与内容版本串 `v=`，刷新即换。

### 资源端点

| 路由 | 鉴权 | 内容 |
|---|---|---|
| `index.php?r=plugin/ui.css&s=app&v=…` | 登录态或 API Key | 已启用 app 插件的聚合 CSS |
| `index.php?r=plugin/ui.js&s=app&v=…` | 登录态或 API Key | 已启用 app 插件的聚合 JS bundle |
| `index.php?r=plugin/ui.css\|ui.js&s=login&v=…` | **允许匿名** | 只输出声明了 `login` scope 的插件（手势密码等） |

`app` scope 走 `api_guard(false)`（GET 不需要 CSRF）；`login` scope 允许匿名，但运行时会禁用 `DSH.api()`，
登录页插件拿不到任何受保护数据。响应统一 `nosniff` + `private, max-age=0, must-revalidate`。

### 前端运行时 `window.DSH`

| API | 说明 |
|---|---|
| `DSH.ready(fn)` / `on(name, fn)` / `off(handle)` / `emit(name, data)` | 生命周期；`on` 支持 `{replay:true}` 重放历史事件 |
| `DSH.getState()` / `DSH.state` | 核心状态快照（sid / sessions / messages / model / settings / busy） |
| `DSH.api(route, data, opt)` | 带 CSRF 的 `index.php?r=…` 请求封装 |
| `DSH.core.*` | `app.js` 注入的能力：`api`、`renderSessions`、`refreshSessions`、`selectSession`、`newSession`、`renderMessages`、`setStatus`、`openModal`、`loadMarket`、`fillModels`、`send`、`esc`、`md` |
| `DSH.storage` | 按插件 id 隔离的 localStorage（`get/set/del/keys`，单键 ≤64KB）。`DSH.ready(fn)` 回调收到的是**该插件专属 API**；2.4.0 早期曾误给全局 `DSH`，旧数据落在 `dsh.ui._global.*`，首次读取会自动迁移到插件命名空间，设置不会丢 |
| `DSH.ui.mount(slot, node, opts)` | 挂到 8 个固定容器；`{id, mode:'append'|'prepend'|'replace'}` |
| `DSH.ui.panel({id,title,slot})` | 通用浮层面板，返回 `{root, body, close, toggle}` |
| `DSH.ui.css(text, id)` / `toast(msg, type)` / `btn(label, fn, cls)` | 动态样式、提示条、按钮 |
| `DSH.ui.registerRenderer('sessions', fn)` | **接管**核心会话列表渲染（失败自动回退原生渲染） |
| `DSH.esc(s)` / `DSH.el(tag, attrs, children)` | 安全转义与 DOM 构造 |

**挂载容器**（写死在 `app/view.php`，重渲染不会被冲掉）：
`sidebar-top` / `sidebar-foot` / `status` / `topbar` / `composer` / `overlay` / `login` / `toasts`。

**生命周期事件**（由 `app.js` 主动发出，不再是外部猜 DOM）：
`runtime:boot` `app:boot` `core:attach` `bind:done` `app:ready` `plugins:ready`
`sessions:render` `render:slot` `messages:render` `session:change` `session:create`
`chat:send` `stream:event` `chat:done` `chat:error` `busy:change` `status:set` `status:ready`
`model:change` `settings:save` `market:render` `modal:open` `modal:close` `plugin:toggle`
`state:change` `theme:change`（插件广播）`ui:error`。

每个插件的 JS 在独立函数作用域里执行（`new Function` 隔离 + try/catch），
一个插件报错不会拖垮其它插件，错误记在 `DSH.plugins[i].error` 与控制台。
插件 JS 视同服务端可信代码（能放插件的人本来就能改 `market/*.php`）；
**如果将来给页面加 CSP，需要放行 `'unsafe-eval'` 或改为逐插件静态文件加载**。

### 5 个示范插件

| 插件 | 分类 | 用到的扩展点 |
|---|---|---|
| `mkt.sidebar` 更好的侧边栏 | 美化 | `registerRenderer('sessions')` 接管渲染 + `mount('sidebar-top')` + storage 偏好 |
| `mkt.glass` 透明玻璃背景 | 美化 | `scope=['app','login']` + 覆盖 `.main` 实心底色 + CSS 变量 + `theme:change` + 本地背景图（压缩 ≤64KB，**不上传服务器**）。1.2.0 起背景模糊（默认 **0px**，整张图清晰）与面板毛玻璃（默认 4px，只作用侧栏/顶栏/输入区）**分开独立可调**，旧版 12px+14px 双重模糊刷新后自动迁移；「主区浓度」0% 全透 / 38% 默认 / 80% 最暗 |
| `mkt.context` 上下文用量显示 | 实用 | 新增 `api/ui/context` + `chat:done` / `session:change` 事件；服务端累计真实 `usage`，本地确定性估算占比 |
| `mkt.vtree` 记忆虚拟目录树 | 实用 | `$ctx['store']` kv + `api/plugin/run` + `ui.panel`；整棵树 ≤40KB，不调模型、不碰 sessions 表 |
| `mkt.passwords` 多密码 / 手势密码 | 实用 | 登录页匿名 bundle + 手势轨迹写入 `name=password` + `api/ui/credentials` |
| `mkt.browser` 内嵌浏览器 | 实用 | `mount('topbar')` + `mount('overlay')` + `stream:event` 实时 delta + 沙箱 iframe `srcdoc`；零后端接口 |

安装：进入「设置 → 插件市场」，在 **美化 / 实用** 分类里装上想要的插件，**刷新页面**即生效。
内嵌浏览器默认未安装，装上后顶栏会出现「▣ 预览」。

### 多凭据登录（随 `mkt.passwords` 一起改的核心）

- `config.php` 新增可选 `credentials`（`label => ['type','hash','created']`，最多 8 条），
  `admin_password_hash` **仍是主密码，永远有效**；`credentials` 为空时 `auth_verify()` 与 2.3.2 完全一致。
- 手势密码在前端编码成 `g:0-1-2-5-8` 字符串，与普通密码一样用 `password_hash()` / `password_verify()` 校验。
- `api/ui/credentials`：GET 只返回标签与类型（**绝不下发 hash**），POST 增删走登录态 + CSRF。
- 增删凭据**只改 config.php，不销毁任何会话**，不会把其它已登录设备踢下线；失败锁定（8 次 / 5 分钟）仍是全局的。
- 忘记密码的兜底不变：FTP 删 `config.php` 重新 `install.php`（会清掉所有附加凭据）。

### 一键停用

把 `config.php` 的 `ui` 改成 `['enabled' => false, 'allow_login_scope' => true]`：
页面不再输出聚合资源标签与挂载容器内容，`window.DSH` 仍加载但不注入任何插件，
所有工具插件、对话、自动化任务不受影响。`allow_login_scope=false` 可单独关掉登录页插件。

### 内嵌浏览器 · HTML 实时预览（`mkt.browser`）

「实用」分类下的界面插件，**不改任何核心文件、不新增后端接口、不写服务器数据**，
全靠第十二章的生命周期事件（`chat:send` / `stream:event` / `chat:done` / `session:change` / `messages:render`）+ `topbar` / `overlay` 挂载点实现。

- **打开**：顶栏「▣ 预览」或 `Alt+P`。右侧停靠一条分栏（默认 480px，左边缘可拖拽 320–900px），
  `#app` 自动让出宽度，聊天区不被遮挡；窄屏（<900px）自动变全屏抽屉。关闭后布局原样还原。
- **实时渲染**：AI 回复里出现 ```` ```html ```` 代码块时，流式 `delta` 每 500ms 节流刷新一次 iframe，
  生成完成后做最终渲染。代码块**没闭合也能渲染**（边生成边看）。也识别裸 `<!doctype html>` / `<html>…</html>`，
  以及 HTML 片段（自动补一层白底中文字体的完整文档）。取最后一个 HTML 块，忽略说明文字与其它语言代码块。
- **浏览器式工具栏**：◀▶ 切换最近 12 个版本、↻ 刷新、⟳ 跟随最新、`</>` 源码视图、⧉ 复制源码、
  ⤓ 下载 `.html`、▤ 打开本机 `.html`（`FileReader` 纯前端读取，≤1MB，**不上传服务器**）、✕ 关闭。
  底部可切「允许脚本」与「自动跟随」，宽度与开关存浏览器 `localStorage`。
- **切换会话**会回放历史事件，自动恢复该会话最后一段 HTML；首屏加载也不会漏（`on(..., {replay:true})`）。

> ⚠️ **安全边界**：AI 生成的 HTML 属于不可信内容，因此 iframe 用 `srcdoc` + `sandbox="allow-scripts allow-forms allow-modals"`，
> **绝不含** `allow-same-origin` / `allow-top-navigation` / `allow-popups` / `allow-downloads`。
> iframe 是 opaque origin：脚本能跑（小游戏、图表需要），但读不到本站 Cookie、`localStorage` 和 `parent.document`；
> 另设 `referrerpolicy=no-referrer`，外部 CDN 不会带走本站地址。`srcdoc` 走属性赋值、源码视图走 `textarea.value`，都不经过 `innerHTML`。
> **故意不做「新窗口打开」**：Blob URL 与父页面同源，会让脚本逃出沙箱；需要独立查看请用「⤓ 下载」后自行打开。

### 最小 UI 插件样例

```php
<?php
// market/my.hello.php —— 放进 market/ 刷新市场页即可安装
return [
    'id' => 'my.hello', 'name' => '顶栏问候', 'category' => 'beauty',
    'description' => '在顶栏挂一个按钮', 'version' => '1.0.0',
    'author' => 'me', 'icon' => 'star', 'enabled' => true,
    'tools' => [], 'slash' => [],
    'ui' => [
        'apiVersion' => 1, 'scope' => ['app'], 'mounts' => ['topbar'],
        'permissions' => ['ui'],
        'css' => '.my-hello{color:var(--accent2);font-size:12px}',
        'js' => 'DSH.ready(function(D){ D.ui.mount("topbar",
            D.el("span", {class:"my-hello", text:"你好 · " + D.boot.version}),
            {id:"my.hello"}); });',
    ],
];
```
