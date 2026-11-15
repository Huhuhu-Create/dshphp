<?php
/**
 * 内置插件：安全表达式计算器（不使用 eval）
 * 支持 + - * / % ^ ( ) 与 sqrt/abs/min/max/round/floor/ceil/pow/pi
 * 注意：类定义必须放在 return 之前，否则文件执行到 return 就结束了。
 */
if (!class_exists('CalcParser')) {
    class CalcParser {
        private array $tok = [];
        private int $i = 0;
        public function __construct(string $expr) {
            $s = strtolower($expr);
            preg_match_all('/(\d+(?:\.\d+)?|\.\d+|[a-z]+|.)/', $s, $m);
            foreach ($m[0] as $t) {
                if (trim($t) === '') continue;
                $this->tok[] = $t;
            }
        }
        public function parse(): float {
            $v = $this->add();
            if ($this->i < count($this->tok)) throw new RuntimeException('多余的字符：' . $this->tok[$this->i]);
            return $v;
        }
        private function peek(): string { return $this->tok[$this->i] ?? ''; }
        private function eat(string $s): bool {
            if ($this->peek() === $s) { $this->i++; return true; }
            return false;
        }
        private function add(): float {
            $v = $this->mul();
            while (true) {
                if ($this->eat('+')) $v += $this->mul();
                elseif ($this->eat('-')) $v -= $this->mul();
                else return $v;
            }
        }
        private function mul(): float {
            $v = $this->pow();
            while (true) {
                if ($this->eat('*')) $v *= $this->pow();
                elseif ($this->eat('/')) { $d = $this->pow(); if ($d == 0) throw new RuntimeException('除以 0'); $v /= $d; }
                elseif ($this->eat('%')) { $d = $this->pow(); if ($d == 0) throw new RuntimeException('模 0'); $v = fmod($v, $d); }
                else return $v;
            }
        }
        private function pow(): float {
            $v = $this->unary();
            if ($this->eat('^')) $v = $v ** $this->pow();
            return $v;
        }
        private function unary(): float {
            if ($this->eat('-')) return -$this->unary();
            if ($this->eat('+')) return $this->unary();
            return $this->atom();
        }
        private function atom(): float {
            $t = $this->peek();
            if ($t === '') throw new RuntimeException('表达式不完整');
            if (is_numeric($t)) { $this->i++; return (float) $t; }
            if ($t === '(') {
                $this->i++;
                $v = $this->add();
                if (!$this->eat(')')) throw new RuntimeException('缺少右括号');
                return $v;
            }
            if (preg_match('/^[a-z]+$/', $t)) {
                $this->i++;
                if (!$this->eat('(')) {
                    if ($t === 'pi') return M_PI;
                    throw new RuntimeException('未知常量：' . $t);
                }
                $args = [];
                if (!$this->eat(')')) {
                    do { $args[] = $this->add(); } while ($this->eat(','));
                    if (!$this->eat(')')) throw new RuntimeException('函数缺少右括号：' . $t);
                }
                switch ($t) {
                    case 'sqrt': return sqrt($args[0] ?? 0);
                    case 'abs': return abs($args[0] ?? 0);
                    case 'floor': return floor($args[0] ?? 0);
                    case 'ceil': return ceil($args[0] ?? 0);
                    case 'round': return round($args[0] ?? 0, (int) ($args[1] ?? 0));
                    case 'pow': return ($args[0] ?? 0) ** ($args[1] ?? 1);
                    case 'min': return $args ? min($args) : 0;
                    case 'max': return $args ? max($args) : 0;
                    case 'log': return log($args[0] ?? 1, $args[1] ?? M_E);
                    case 'exp': return exp($args[0] ?? 0);
                    case 'sin': return sin($args[0] ?? 0);
                    case 'cos': return cos($args[0] ?? 0);
                    default: throw new RuntimeException('未知函数：' . $t);
                }
            }
            throw new RuntimeException('无法解析：' . $t);
        }
    }
}

return [
    'id' => 'core.calc',
    'name' => '计算器',
    'description' => '计算数学表达式，支持加减乘除、幂、括号与常用函数；不使用 eval',
    'version' => '1.0.0', 'author' => 'dshphp', 'icon' => 'calc', 'enabled' => true,
    'tools' => [[
        'name' => 'calculate',
        'description' => '计算一个数学表达式并返回结果，例如 (12+3)*4 或 sqrt(2)^2',
        'parameters' => [
            'type' => 'object',
            'properties' => ['expression' => ['type' => 'string', 'description' => '要计算的数学表达式']],
            'required' => ['expression'],
        ],
    ]],
    'slash' => ['calc' => '计算表达式，如 /calc 1+2*3'],
    'run' => function (string $tool, array $args, array $ctx): array {
        $expr = trim((string) ($args['expression'] ?? ($args['input'] ?? '')));
        if ($expr === '') return ['ok' => false, 'text' => '缺少 expression 参数'];
        if (strlen($expr) > 300) return ['ok' => false, 'text' => '表达式过长'];
        if (!preg_match('/^[0-9\s\+\-\*\/\%\^\(\)\.,a-zA-Z]+$/', $expr)) {
            return ['ok' => false, 'text' => '表达式含非法字符，只允许数字、运算符、括号与函数名'];
        }
        try {
            $v = (new CalcParser($expr))->parse();
            if (!is_finite($v)) return ['ok' => false, 'text' => '结果不是有限数（可能除以 0）'];
            $shown = (abs($v - round($v)) < 1e-12) ? (string) round($v) : rtrim(rtrim(number_format($v, 12, '.', ''), '0'), '.');
            return ['ok' => true, 'text' => $expr . ' = ' . $shown, 'data' => ['value' => $v]];
        } catch (Throwable $e) {
            return ['ok' => false, 'text' => '计算失败：' . $e->getMessage()];
        }
    },
];
