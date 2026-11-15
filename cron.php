<?php
/**
 * dshphp 2.3.1 —— 外部心跳入口已停用（占位页）
 *
 * 从 2.3.1 起自动化任务改为「访问驱动」：只有有请求访问 index.php 时才推进调度，
 * 不再提供任何保活 / 外部 cron / 常驻进程能力。
 *
 * 这个文件本身只是一个 410 停用提示，不加载引擎、不鉴权、不执行任何任务。
 * 保留它的唯一目的：如果线上是 2.3.0、并且已经把这个地址交给过外部定时服务，
 * 覆盖上传本文件后，那条旧的保活链路会立刻失效，不会留下后门。
 *
 * 全新的 2.3.1 站点可以直接删除本文件，部署包里带上它只是为了覆盖旧版。
 */
declare(strict_types=1);

http_response_code(410);   // Gone
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo json_encode([
    'ok' => false,
    'error' => '外部心跳已停用（2.3.1 起为访问驱动）',
    'hint' => '自动化任务只在 index.php 被访问时推进；请取消所有指向本地址的外部定时任务，直接访问 index.php 即可',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
