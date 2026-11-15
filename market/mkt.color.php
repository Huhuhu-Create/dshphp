<?php
/** 市场插件：颜色转换 */
return [
    'id' => 'mkt.color', 'name' => '颜色转换',
    'description' => 'HEX / RGB / HSL 互转，计算亮度、互补色与深浅文字建议',
    'version' => '1.0.0', 'author' => 'dshphp 官方市场', 'icon' => 'spark',
    'category' => 'life', 'tags' => ['颜色', 'HEX', 'RGB'], 'risk' => 'local',
    'usage' => '/color #5b8cff 或 /color {"rgb":"91,140,255"}',
    'enabled' => true,
    'tools' => [[
        'name' => 'color_convert', 'description' => '输入 hex（#RRGGBB）或 rgb（r,g,b），返回各格式、HSL、亮度、互补色',
        'parameters' => ['type' => 'object', 'properties' => [
            'color' => ['type' => 'string', 'description' => 'HEX 或 RGB 颜色值'],
            'rgb' => ['type' => 'string', 'description' => 'RGB 值，如 91,140,255'],
        ], 'required' => []],
    ]],
    'slash' => ['color' => '颜色转换：/color #5b8cff'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $c = strtolower(trim((string) ($args['color'] ?? ($args['input'] ?? ''))));
        $rgbStr = trim((string) ($args['rgb'] ?? ''));
        $r = $g = $b = null;
        if ($rgbStr !== '') {
            $parts = array_values(array_filter(preg_split('~[,\s]+~', $rgbStr) ?: [], fn($x) => $x !== ''));
            if (count($parts) === 3) [$r, $g, $b] = array_map('intval', $parts);
        } elseif (preg_match('~^#?([0-9a-f]{3}|[0-9a-f]{6})$~', $c, $m)) {
            $h = $m[1];
            if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            $r = hexdec(substr($h, 0, 2)); $g = hexdec(substr($h, 2, 2)); $b = hexdec(substr($h, 4, 2));
        }
        if ($r === null || $r < 0 || $r > 255 || $g < 0 || $g > 255 || $b < 0 || $b > 255) {
            return ['ok' => false, 'text' => '颜色格式不对：用 #RRGGBB / #RGB / {"rgb":"91,140,255"}'];
        }
        $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
        $rn = $r / 255; $gn = $g / 255; $bn = $b / 255;
        $mx = max($rn, $gn, $bn); $mn = min($rn, $gn, $bn); $d = $mx - $mn;
        $l = ($mx + $mn) / 2;
        $s = $d == 0 ? 0 : $d / (1 - abs(2 * $l - 1));
        $hue = 0;
        if ($d != 0) {
            if ($mx == $rn) $hue = 60 * fmod((($gn - $bn) / $d), 6);
            elseif ($mx == $gn) $hue = 60 * ((($bn - $rn) / $d) + 2);
            else $hue = 60 * ((($rn - $gn) / $d) + 4);
        }
        if ($hue < 0) $hue += 360;
        $lum = 0.2126 * $rn + 0.7152 * $gn + 0.0722 * $bn;
        $comp = sprintf('#%02x%02x%02x', 255 - $r, 255 - $g, 255 - $b);
        $lines = [
            'HEX ' . $hex . ' · RGB rgb(' . $r . ', ' . $g . ', ' . $b . ')',
            'HSL hsl(' . round($hue) . ', ' . round($s * 100) . '%, ' . round($l * 100) . '%)',
            '相对亮度 ' . round($lum, 3) . ' · ' . ($lum > 0.5 ? '浅色（适合配深色文字）' : '深色（适合配浅色文字）'),
            '互补色（反色）' . $comp,
        ];
        return ['ok' => true, 'text' => implode("\n", $lines),
            'data' => ['hex' => $hex, 'rgb' => [$r, $g, $b], 'hsl' => [round($hue), round($s * 100), round($l * 100)], 'complement' => $comp]];
    },
];
