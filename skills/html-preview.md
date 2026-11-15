---
name: 生成可实时预览的 HTML
description: 让 AI 写网页、小游戏、可视化页面或 demo，输出可直接被内嵌浏览器渲染的 HTML
when: html，网页，页面，做个页面，写个页面，前端，小游戏，可视化，图表，demo，特效，预览，canvas，svg 动画，landing page
default: auto
---

## 硬性输出格式

- **有且只有一个 ```html 代码块**，里面是完整文档：`<!doctype html>` → `<html>` → `<head>`（含 `<meta charset="utf-8">` 与 `<meta name="viewport" ...>`）→ `<body>`。
- 即使用户只要一个 div / 组件，也补成白底完整文档，方便直接预览。
- 所有 CSS 与 JS **内联**；不引用外部 CDN、字体、图片或本地文件。图标用 emoji 或内联 SVG，图片用渐变 / CSS 绘制。
- 中文内容必须声明 `charset=utf-8`；代码块之后只用一句话说明改动点或操作方式，不要长篇解释。

## 运行环境约束（页面在沙箱 iframe 里跑）

- 不要访问 `window.parent` / `top` / 父页面 DOM；不能依赖 Cookie 与 `localStorage`（可能不可用），状态放 JS 局部变量，刷新即重置属预期行为。
- 不能跳转顶部页面、不能触发下载；需要独立查看时用「下载源码」而不是 `window.open`。
- 尽量不用 `alert/confirm`；错误与提示渲染到页面内。
- 脚本可用，适合小游戏、动画、交互图表；但所有资源必须自包含，断网也要能跑。

## 质量底线

- 页面在 1280px 与 375px 两种宽度下都不横向溢出。
- 交互元素有可见反馈（hover / active / 状态文字），不要只有静态外观。
- 不要生成会无限循环或每帧重建整棵 DOM 的代码。
