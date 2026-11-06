<?php
/**
 * 纯 PHP 实现的 JS 混淆器（不依赖 Node / npm）
 *
 * 输出风格与 javascript-obfuscator 对齐，做四件事：
 *   1) 字符串数组：把源码里所有字符串收进一个数组，原位置改成「索引解码函数调用」；
 *   2) 属性访问：obj.prop → obj[解码(索引)]，属性名也进字符串数组；
 *   3) 标识符：函数内的局部变量、参数改成 _0x 十六进制名（顶层变量不动，
 *      避免页面内联脚本引用到的全局名失效）；
 *   4) 字面量：整数改十六进制，true/false 改成 !![] / ![]。
 *
 * 为什么不用 Node 的 javascript-obfuscator：
 *   生成动作发生在 WordPress 后台（PHP 进程），生产机没有 Node，
 *   所以必须纯 PHP 实现。
 *
 * 安全设计（改错 JS 会让整站交互失效，所以处处留退路）：
 *   - 遇到拿不准的语法（正则/模板字符串嵌套、解构、with、eval、import/export）
 *     就退回保守策略，宁可少混淆也不改语义；
 *   - 输出后会重新词法分析一遍，token 数量与预期不符就抛异常，
 *     调用方（tpi_jsdist_build）会退回到只压缩不混淆；
 *   - 顶层作用域一律不改名。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('TPI_JS_Obfuscator')) {

    class TPI_JS_Obfuscator
    {
        /** @var string[] token 类型：id / num / str / tpl / re / p */
        private $k = [];
        /** @var string[] token 原文 */
        private $v = [];
        /** @var array<int,bool> token 前是否换行（保留 ASI 语义，不能省） */
        private $nl = [];
        /** @var bool 上一个 token 之前是否出现过换行 */
        private $sawNL = false;
        /** @var int token 数量 */
        private $n = 0;

        /** @var array<int,string> token 下标 => 替换文本 */
        private $repl = [];
        /** @var array<int,bool> token 下标 => 不输出 */
        private $drop = [];

        /** @var array<string,int> 字符串 => 数组位序 */
        private $pool = [];
        /** @var string[] 数组位序 => 字符串值 */
        private $poolList = [];

        /** @var array<int,int> 括号配对 */
        private $match = [];
        /** @var array<int,int> 每个 token 所在的最内层 { 下标 */
        private $innerBrace = [];
        /** @var array<int,string> { 下标 => obj / block / class */
        private $braceKind = [];

        /** @var array<int,array> 作用域：s / e / parent / fn / kind / names / params */
        private $scopes = [];
        /** @var array<int,int> token 下标 => 最内层作用域 */
        private $scopeOf = [];
        /** @var array<int,bool> token 下标 => 属性名（点号后） */
        private $isProp = [];
        /** @var array<int,bool> token 下标 => 对象字面量 key 位置 */
        private $objKey = [];
        /** @var array<int,bool> token 下标 => 简写属性 { a } */
        private $shorthand = [];

        /** @var array<string,bool> 已占用的标识符，避免重名 */
        private $taken = [];
        /** @var array<string,bool> 出现在模板插值里的名字，一律不改名 */
        private $protected = [];

        private $mangle = true;
        private $offset = 0x100;
        private $aliasName = '_0xa1';
        private $decName   = '_0xa2';
        private $arrName   = '_0xa3';

        /** @var array<string,bool> JS 关键字 / 值字面量，永不改名 */
        private static $reserved = [
            'true' => 1, 'false' => 1, 'null' => 1, 'this' => 1, 'super' => 1,
            'break' => 1, 'case' => 1, 'catch' => 1, 'class' => 1, 'const' => 1,
            'continue' => 1, 'debugger' => 1, 'default' => 1, 'delete' => 1,
            'do' => 1, 'else' => 1, 'export' => 1, 'extends' => 1, 'finally' => 1,
            'for' => 1, 'function' => 1, 'if' => 1, 'import' => 1, 'in' => 1,
            'instanceof' => 1, 'let' => 1, 'new' => 1, 'return' => 1, 'static' => 1,
            'switch' => 1, 'throw' => 1, 'try' => 1, 'typeof' => 1, 'var' => 1,
            'void' => 1, 'while' => 1, 'with' => 1, 'yield' => 1, 'await' => 1,
            'of' => 1, 'get' => 1, 'set' => 1, 'async' => 1, 'arguments' => 1,
            'undefined' => 1, 'NaN' => 1, 'Infinity' => 1,
        ];

        /** @var string 出错原因（供后台显示） */
        public $error = '';

        // ==================================================================
        // 对外入口
        // ==================================================================

        /**
         * @param string $code  源码
         * @param array  $args  mangle: 是否混淆标识符（默认 true）
         * @return string 混淆后的代码
         * @throws RuntimeException 遇到无法安全处理的语法
         */
        public static function run(string $code, array $args = []): string
        {
            $one = new self();
            $one->mangle = !isset($args['mangle']) || (bool) $args['mangle'];

            return $one->process($code);
        }

        private function process(string $code): string
        {
            $code = (string) preg_replace('~^\xEF\xBB\xBF~', '', $code);
            if (trim($code) === '') {
                return $code;
            }
            // 带 eval / with 的代码无法安全改名
            if (preg_match('~(?<![\w$.])eval\s*\(~', $code) || preg_match('~(?<![\w$.])with\s*\(~', $code)) {
                $this->mangle = false;
            }

            $this->tokenize($code);
            if ($this->n < 3) {
                return $code;
            }
            $this->pairBrackets();
            $this->resolveBraces();
            $this->markProps();

            $this->aliasName = $this->freshName();
            $this->decName   = $this->freshName();
            $this->arrName   = $this->freshName();

            if ($this->mangle) {
                $this->buildScopes();
                $this->collectBindings();
            }

            $this->planTokens();
            $out = $this->emit();

            // 自检：重新词法分析，token 数必须与预期一致，
            // 不一致说明正则/模板字符串被切错，宁可放弃混淆
            $expect = $this->emittedTokens;
            $probe  = new self();
            $probe->tokenize($out);
            if ($probe->n !== $expect) {
                throw new RuntimeException('token mismatch: ' . $probe->n . ' != ' . $expect);
            }

            return $out;
        }

        /** @var int 本次实际输出的 token 数（含头部） */
        private $emittedTokens = 0;

        // ==================================================================
        // 词法分析
        // ==================================================================

        private function tokenize(string $s): void
        {
            $this->k = [];
            $this->v = [];
            $this->nl = [];
            $this->n = 0;
            $this->sawNL = false;

            $len = strlen($s);
            $i   = 0;

            while ($i < $len) {
                $c = $s[$i];

                // ---- 空白 ----
                if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n" || $c === "\f" || $c === "\v") {
                    if ($c === "\n") {
                        $this->sawNL = true;
                    }
                    $i++;
                    continue;
                }
                if ($c === "\xc2" && $i + 1 < $len && $s[$i + 1] === "\xa0") {
                    $i += 2;
                    continue;
                }

                // ---- 注释 ----
                if ($c === '/' && $i + 1 < $len) {
                    $n1 = $s[$i + 1];
                    if ($n1 === '/') {
                        $e = strpos($s, "\n", $i + 2);
                        $i = $e === false ? $len : $e + 1;
                        $this->sawNL = true;
                        continue;
                    }
                    if ($n1 === '*') {
                        $e = strpos($s, '*/', $i + 2);
                        $end = $e === false ? $len : $e + 2;
                        if (strpos(substr($s, $i, $end - $i), "\n") !== false) {
                            $this->sawNL = true;
                        }
                        $i = $end;
                        continue;
                    }
                }

                // ---- 字符串 ----
                if ($c === "'" || $c === '"') {
                    $j = $i + 1;
                    while ($j < $len) {
                        $ch = $s[$j];
                        if ($ch === '\\') {
                            $j += 2;
                            continue;
                        }
                        if ($ch === $c) {
                            $j++;
                            break;
                        }
                        if ($ch === "\n") {
                            break; // 不闭合，交给后面兜底
                        }
                        $j++;
                    }
                    $this->add('str', substr($s, $i, $j - $i));
                    $i = $j;
                    continue;
                }

                // ---- 模板字符串（整体作为一个 token） ----
                if ($c === '`') {
                    $j     = $i + 1;
                    $depth = 0;
                    while ($j < $len) {
                        $ch = $s[$j];
                        if ($ch === '\\') {
                            $j += 2;
                            continue;
                        }
                        if ($depth === 0 && $ch === '`') {
                            $j++;
                            break;
                        }
                        if ($depth === 0 && $ch === '$' && $j + 1 < $len && $s[$j + 1] === '{') {
                            $depth++;
                            $j += 2;
                            continue;
                        }
                        if ($depth > 0) {
                            if ($ch === '{') {
                                $depth++;
                            } elseif ($ch === '}') {
                                $depth--;
                            } elseif ($ch === "'" || $ch === '"' || $ch === '`') {
                                $q = $ch;
                                $j++;
                                while ($j < $len) {
                                    if ($s[$j] === '\\') {
                                        $j += 2;
                                        continue;
                                    }
                                    if ($s[$j] === $q) {
                                        break;
                                    }
                                    $j++;
                                }
                            }
                        }
                        $j++;
                    }
                    $raw = substr($s, $i, $j - $i);
                    if (strpos($raw, '${') !== false) {
                        // 模板插值目前整段跳过混淆，里面的名字必须保持原样，
                        // 否则外部改了名、里面还是旧名，运行时就 undefined
                        if (preg_match_all('~[A-Za-z_$][A-Za-z0-9_$]*~', $raw, $mm)) {
                            foreach ($mm[0] as $one) {
                                $this->protected[$one] = true;
                            }
                        }
                    }
                    $this->add('tpl', $raw);
                    $i = $j;
                    continue;
                }

                // ---- 正则 ----
                if ($c === '/' && $this->regexAllowed()) {
                    $j      = $i + 1;
                    $class  = false;
                    $closed = false;
                    while ($j < $len) {
                        $ch = $s[$j];
                        if ($ch === '\\') {
                            $j += 2;
                            continue;
                        }
                        if ($ch === "\n") {
                            break;
                        }
                        if ($ch === '[') {
                            $class = true;
                        } elseif ($ch === ']') {
                            $class = false;
                        } elseif ($ch === '/' && !$class) {
                            $j++;
                            $closed = true;
                            break;
                        }
                        $j++;
                    }
                    if ($closed) {
                        while ($j < $len && preg_match('~[a-z]~i', $s[$j])) {
                            $j++;
                        }
                        $this->add('re', substr($s, $i, $j - $i));
                        $i = $j;
                        continue;
                    }
                }

                // ---- 数字 ----
                if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($s[$i + 1]))) {
                    if (preg_match('~^(?:0[xX][0-9a-fA-F_]+|0[bB][01_]+|0[oO][0-7_]+|(?:\d[\d_]*\.?[\d_]*|\.\d[\d_]*)(?:[eE][+-]?[\d_]+)?n?)~', substr($s, $i, 64), $m)) {
                        $this->add('num', $m[0]);
                        $i += strlen($m[0]);
                        continue;
                    }
                }

                // ---- 标识符 ----
                if (preg_match('~^[A-Za-z_$\x80-\xff][A-Za-z0-9_$\x80-\xff]*~', substr($s, $i, 256), $m)) {
                    $this->add('id', $m[0]);
                    $i += strlen($m[0]);
                    continue;
                }

                // ---- 运算符 / 标点 ----
                $punct = ['>>>=', '...', '===', '!==', '**=', '<<=', '>>=', '>>>', '&&=', '||=', '??=',
                    '=>', '==', '!=', '<=', '>=', '&&', '||', '??', '?.', '++', '--', '+=', '-=',
                    '*=', '/=', '%=', '&=', '|=', '^=', '<<', '>>', '**'];
                $hit = '';
                foreach ($punct as $p) {
                    if (substr($s, $i, strlen($p)) === $p) {
                        // ?. 后面跟数字是三元 + 小数，不能当可选链
                        if ($p === '?.' && $i + 2 < $len && ctype_digit($s[$i + 2])) {
                            continue;
                        }
                        $hit = $p;
                        break;
                    }
                }
                if ($hit !== '') {
                    $this->add('p', $hit);
                    $i += strlen($hit);
                    continue;
                }

                $this->add('p', $c);
                $i++;
            }
        }

        private function add(string $type, string $value): void
        {
            $this->k[]  = $type;
            $this->v[]  = $value;
            $this->nl[] = $this->sawNL;
            $this->sawNL = false;
            $this->n++;
        }

        /** 前一个 token 决定 `/` 是正则还是除号 */
        private function regexAllowed(): bool
        {
            if ($this->n === 0) {
                return true;
            }
            $i = $this->n - 1;
            $t = $this->k[$i];
            $v = $this->v[$i];
            if ($t === 'num' || $t === 'str' || $t === 'tpl' || $t === 're') {
                return false;
            }
            if ($t === 'id') {
                return isset(self::$reserved[$v]) && in_array($v, [
                    'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete',
                    'void', 'throw', 'case', 'do', 'else', 'yield', 'await',
                ], true);
            }
            if ($v === ')' || $v === ']' || $v === '}' || $v === '++' || $v === '--') {
                return false;
            }

            return true;
        }

        // ==================================================================
        // 结构分析
        // ==================================================================

        private function pairBrackets(): void
        {
            $stack = [];
            for ($i = 0; $i < $this->n; $i++) {
                $v = $this->v[$i];
                if ($v === '(' || $v === '[' || $v === '{') {
                    $stack[] = $i;
                } elseif ($v === ')' || $v === ']' || $v === '}') {
                    $open = array_pop($stack);
                    if ($open === null) {
                        throw new RuntimeException('unbalanced brackets');
                    }
                    $this->match[$open] = $i;
                    $this->match[$i]    = $open;
                }
            }
            if ($stack) {
                throw new RuntimeException('unbalanced brackets');
            }
        }

        /** @var array<int,int> token 下标 => 尚未闭合的 ( 与 [ 数量 */
        private $parenDepth = [];
        /** @var array<int,int> { 下标 => 该 { 所在位置的括号层数 */
        private $braceParen = [];

        /** 判断每个 { 是对象字面量、代码块还是类体 */
        private function resolveBraces(): void
        {
            $stack = [];
            $paren = 0;
            for ($i = 0; $i < $this->n; $i++) {
                $v = $this->v[$i];
                $this->innerBrace[$i] = $stack ? end($stack) : -1;
                $this->parenDepth[$i] = $paren;
                if ($v === '(' || $v === '[') {
                    $paren++;
                } elseif ($v === ')' || $v === ']') {
                    $paren = max(0, $paren - 1);
                } elseif ($v === '{') {
                    $this->braceKind[$i] = $this->guessBraceKind($i);
                    $this->braceParen[$i] = $paren;
                    $stack[] = $i;
                } elseif ($v === '}') {
                    array_pop($stack);
                }
            }
        }

        private function guessBraceKind(int $i): string
        {
            $p = $i - 1;
            if ($p < 0) {
                return 'block';
            }
            $pv = $this->v[$p];
            $pt = $this->k[$p];

            if ($pt === 'p') {
                if ($pv === ')' ) {
                    // function(){} / if(){} / catch(){}
                    return 'block';
                }
                if ($pv === '=>') {
                    return 'block';
                }
                if (in_array($pv, [';', '{', '}', ']'], true)) {
                    return 'block';
                }
                if (in_array($pv, ['=', '(', ',', ':', '[', '?', '&&', '||', '??', '!', '+', '-', '*', '/', '%', '...'], true)) {
                    return 'obj';
                }
                return 'block';
            }
            if ($pt === 'id') {
                if (in_array($pv, ['else', 'do', 'try', 'finally', 'return', 'typeof', 'new', 'in', 'of', 'void'], true)) {
                    return 'block';
                }
                // class X { ... }
                if ($pv === 'extends') {
                    return 'class';
                }
                // class 后面的类名在别处处理
                return 'obj';
            }
            if ($pt === 'str' || $pt === 'num' || $pt === 'tpl' || $pt === 're') {
                return 'block';
            }
            return 'block';
        }

        /** 标记点号后的属性名，以及对象字面量的 key / 简写属性 */
        private function markProps(): void
        {
            for ($i = 0; $i < $this->n; $i++) {
                $v = $this->v[$i];
                $t = $this->k[$i];

                if ($t === 'p' && ($v === '.' || $v === '?.' || $v === '#')) {
                    if ($i + 1 < $this->n && $this->k[$i + 1] === 'id') {
                        $this->isProp[$i + 1] = true;
                    }
                    continue;
                }

                if ($t === 'str') {
                    if ($this->atObjectKey($i)) {
                        $this->objKey[$i] = true;
                    }
                    continue;
                }

                if ($t !== 'id' || isset($this->isProp[$i])) {
                    continue;
                }
                if (!$this->atObjectKey($i)) {
                    continue;
                }

                $next = $i + 1 < $this->n ? $this->v[$i + 1] : '';
                if ($next === ',') {
                    $this->shorthand[$i] = true;
                } elseif ($next === '}') {
                    $this->shorthand[$i] = true;
                } else {
                    // : 键值对、( 方法简写、= 解构默认值，都按 key 处理
                    $this->objKey[$i] = true;
                }
            }
        }

        /** token 是否处在对象字面量的「属性名位置」 */
        private function atObjectKey(int $i): bool
        {
            $b = $this->innerBrace[$i] ?? -1;
            if ($b < 0 || ($this->braceKind[$b] ?? '') !== 'obj') {
                return false;
            }
            // 必须和 { 处于同一层括号内，f(a, b) 里的 b 不能当成简写属性
            if (($this->parenDepth[$i] ?? 0) !== ($this->braceParen[$b] ?? 0)) {
                return false;
            }
            $prev = $i - 1;
            if ($prev < 0) {
                return false;
            }

            return $prev === $b || ($this->k[$prev] === 'p' && $this->v[$prev] === ',');
        }

        // ==================================================================
        // 作用域与改名
        // ==================================================================

        private function buildScopes(): void
        {
            // root
            $this->scopes[0] = ['s' => 0, 'e' => $this->n - 1, 'parent' => -1, 'fn' => true, 'kind' => 'root', 'names' => []];
            $this->scopeOf    = array_fill(0, $this->n, 0);

            // 花括号作用域
            $stack = [0];
            for ($i = 0; $i < $this->n; $i++) {
                $this->scopeOf[$i] = end($stack);
                if ($this->v[$i] === '{') {
                    $kind = $this->braceKind[$i] ?? 'block';
                    $id   = count($this->scopes);
                    $this->scopes[$id] = [
                        's' => $i, 'e' => $this->match[$i] ?? $this->n - 1,
                        'parent' => end($stack), 'fn' => false, 'kind' => $kind, 'names' => [],
                        'params' => null,
                    ];
                    $stack[] = $id;
                    $this->scopeOf[$i] = $id;
                } elseif ($this->v[$i] === '}') {
                    if (count($stack) > 1) {
                        array_pop($stack);
                    }
                }
            }

            // 函数体：把对应的 { 作用域标成函数作用域，并记录参数范围
            for ($i = 0; $i < $this->n; $i++) {
                $t = $this->k[$i];
                $v = $this->v[$i];
                if ($t === 'id' && $v === 'function') {
                    $this->markFunction($i);
                }
            }
            // 箭头函数：表达式体需要造一个伪作用域
            for ($i = 0; $i < $this->n; $i++) {
                if ($this->k[$i] === 'p' && $this->v[$i] === '=>') {
                    $this->markArrow($i);
                }
            }
            // 类方法 / 对象方法简写：name(a) { ... }
            for ($i = 0; $i < $this->n; $i++) {
                if ($this->v[$i] === '(') {
                    $this->markMethod($i);
                }
            }

            // 参数 token 本身位于「外层作用域」，必须归到函数作用域，
            // 否则参数名不会被改名，而函数体里的引用会被改，直接报未定义
            foreach ($this->scopes as $sid => $sc) {
                if (empty($sc['params'])) {
                    continue;
                }
                [$from, $to] = $sc['params'];
                for ($i = $from; $i <= $to && $i < $this->n; $i++) {
                    $this->scopeOf[$i] = $sid;
                }
            }

            // 箭头表达式体作用域：范围不影响花括号栈，单独覆盖
            foreach ($this->scopes as $id => $sc) {
                if (($sc['kind'] ?? '') !== 'arrow-expr') {
                    continue;
                }
                $w = $sc['e'] - $sc['s'];
                for ($i = $sc['s']; $i <= $sc['e'] && $i < $this->n; $i++) {
                    $cur = $this->scopeOf[$i];
                    $curW = $this->scopes[$cur]['e'] - $this->scopes[$cur]['s'];
                    if ($w <= $curW) {
                        $this->scopeOf[$i] = $id;
                    }
                }
            }
        }

        private function markFunction(int $i): void
        {
            // function [name] ( params ) {
            $j = $i + 1;
            if ($j < $this->n && $this->k[$j] === 'id') {
                $j++;
            }
            if ($j >= $this->n || $this->v[$j] !== '(') {
                return;
            }
            $close = $this->match[$j] ?? null;
            if ($close === null) {
                return;
            }
            $body = $close + 1;
            if ($body >= $this->n || $this->v[$body] !== '{') {
                return;
            }
            $sid = $this->scopeOf[$body];
            if (($this->scopes[$sid]['s'] ?? -1) !== $body) {
                return;
            }
            $this->scopes[$sid]['fn']     = true;
            $this->scopes[$sid]['kind']   = 'fn';
            $this->scopes[$sid]['params'] = [$j, $close];
        }

        /**
         * 方法定义 name(params) { ... }
         *
         * 「标识符后面跟 ( ) {」这种形状就是方法定义；if / for / while 这些
         * 关键字后面也会出现同样形状，必须排除，否则会把外层变量当成参数。
         */
        private function markMethod(int $open): void
        {
            $prev = $open - 1;
            if ($prev < 0 || $this->k[$prev] !== 'id') {
                return;
            }
            $name = $this->v[$prev];
            if (in_array($name, ['if', 'for', 'while', 'switch', 'catch', 'return',
                'typeof', 'function', 'new', 'do', 'else', 'try', 'with', 'delete',
                'void', 'await', 'yield', 'in', 'of', 'instanceof'], true)) {
                return;
            }
            $close = $this->match[$open] ?? null;
            if ($close === null || $close + 1 >= $this->n || $this->v[$close + 1] !== '{') {
                return;
            }
            $body = $close + 1;
            $sid  = $this->scopeOf[$body];
            if (($this->scopes[$sid]['s'] ?? -1) !== $body) {
                return;
            }
            if (!empty($this->scopes[$sid]['fn'])) {
                return; // 已经是函数作用域（function 关键字已处理）
            }
            $this->scopes[$sid]['fn']     = true;
            $this->scopes[$sid]['kind']   = 'fn';
            $this->scopes[$sid]['params'] = [$open, $close];
        }

        private function markArrow(int $i): void
        {
            // 参数：i-1 是 ')' 或单个标识符
            $prev = $i - 1;
            if ($prev < 0) {
                return;
            }
            if ($this->v[$prev] === ')' && isset($this->match[$prev])) {
                $open  = $this->match[$prev];
                $pFrom = $open;
                $pTo   = $prev;
            } elseif ($this->k[$prev] === 'id') {
                $pFrom = $prev;
                $pTo   = $prev;
            } else {
                return;
            }

            $body = $i + 1;
            if ($body >= $this->n) {
                return;
            }

            if ($this->v[$body] === '{') {
                $sid = $this->scopeOf[$body];
                if (($this->scopes[$sid]['s'] ?? -1) === $body) {
                    $this->scopes[$sid]['fn']     = true;
                    $this->scopes[$sid]['kind']   = 'fn';
                    $this->scopes[$sid]['params'] = [$pFrom, $pTo];
                }
                return;
            }

            // 表达式体：扫到同层的结束位置
            $end   = $this->expressionEnd($body);
            $id    = count($this->scopes);
            $this->scopes[$id] = [
                's' => $pFrom, 'e' => $end, 'parent' => $this->scopeOf[$i],
                'fn' => true, 'kind' => 'arrow-expr', 'names' => [], 'params' => [$pFrom, $pTo],
            ];
            $this->scopeOf[$i] = $id;
        }

        private function expressionEnd(int $start): int
        {
            $depth = 0;
            for ($i = $start; $i < $this->n; $i++) {
                $v = $this->v[$i];
                if ($v === '(' || $v === '[' || $v === '{') {
                    $depth++;
                    continue;
                }
                if ($v === ')' || $v === ']' || $v === '}') {
                    if ($depth === 0) {
                        return $i - 1;
                    }
                    $depth--;
                    continue;
                }
                if ($depth === 0 && ($v === ',' || $v === ';')) {
                    return $i - 1;
                }
            }
            return $this->n - 1;
        }

        private function collectBindings(): void
        {
            // 1) 函数 / 箭头函数的参数
            foreach ($this->scopes as $id => $sc) {
                if (empty($sc['params'])) {
                    continue;
                }
                [$from, $to] = $sc['params'];
                if ($from === $to) {
                    if ($this->k[$from] === 'id') {
                        $this->bind($id, $this->v[$from]);
                    }
                    continue;
                }
                $this->collectParamList($id, $from + 1, $to - 1);
            }

            // 2) var / let / const
            for ($i = 0; $i < $this->n; $i++) {
                if ($this->k[$i] !== 'id') {
                    continue;
                }
                $v = $this->v[$i];
                if ($v !== 'var' && $v !== 'let' && $v !== 'const') {
                    continue;
                }
                if (isset($this->isProp[$i]) || isset($this->objKey[$i])) {
                    continue;
                }
                $this->collectDeclarators($i);
            }

            // 3) 函数声明名
            for ($i = 0; $i < $this->n; $i++) {
                if ($this->k[$i] === 'id' && $this->v[$i] === 'function'
                    && $i + 1 < $this->n && $this->k[$i + 1] === 'id'
                    && $this->v[$i + 1] !== 'function') {
                    $this->bind($this->scopeOf[$i], $this->v[$i + 1]);
                }
            }

            // 4) catch (e)
            for ($i = 0; $i < $this->n; $i++) {
                if ($this->k[$i] === 'id' && $this->v[$i] === 'catch'
                    && $i + 1 < $this->n && $this->v[$i + 1] === '('
                    && isset($this->match[$i + 1])) {
                    $close = $this->match[$i + 1];
                    if ($close === $i + 3 && $this->k[$i + 2] === 'id') {
                        $scope = $this->scopeOf[$close] ?? $this->scopeOf[$i];
                        // catch 的参数作用域是紧随的块
                        $body = $close + 1;
                        if ($body < $this->n && $this->v[$body] === '{') {
                            $scope = $this->scopeOf[$body];
                        }
                        $this->bind($scope, $this->v[$i + 2]);
                        // 参数 token 在括号里，属于外层作用域，要归到 catch 块
                        $this->scopeOf[$i + 2] = $scope;
                    }
                }
            }
        }

        private function collectParamList(int $sid, int $from, int $to): void
        {
            $i = $from;
            while ($i <= $to) {
                $t = $this->k[$i];
                $v = $this->v[$i];
                if ($t === 'id') {
                    $this->bind($sid, $v);
                    // 跳过默认值
                    $i++;
                    $depth = 0;
                    while ($i <= $to) {
                        $w = $this->v[$i];
                        if ($w === '(' || $w === '[' || $w === '{') {
                            $depth++;
                        } elseif ($w === ')' || $w === ']' || $w === '}') {
                            $depth--;
                        } elseif ($depth === 0 && $w === ',') {
                            break;
                        }
                        $i++;
                    }
                    $i++;
                    continue;
                }
                if ($v === '{' || $v === '[') {
                    $i = ($this->match[$i] ?? $to) + 1;
                    // 跳过默认值直到逗号
                    $depth = 0;
                    while ($i <= $to) {
                        $w = $this->v[$i];
                        if ($w === '(' || $w === '[' || $w === '{') {
                            $depth++;
                        } elseif ($w === ')' || $w === ']' || $w === '}') {
                            $depth--;
                        } elseif ($depth === 0 && $w === ',') {
                            break;
                        }
                        $i++;
                    }
                    $i++;
                    continue;
                }
                $i++;
            }
        }

        private function collectDeclarators(int $kw): void
        {
            $isVar = $this->v[$kw] === 'var';
            $target = $isVar ? $this->nearestFn($kw) : $this->scopeOf[$kw];

            $i = $kw + 1;
            while ($i < $this->n) {
                $t = $this->k[$i];
                $v = $this->v[$i];

                if ($t === 'id') {
                    $this->bind($target, $v);
                    $i++;
                } elseif ($v === '{' || $v === '[') {
                    // 解构声明：整段跳过，不做改名
                    $i = ($this->match[$i] ?? $i) + 1;
                } elseif ($v === ';' || $v === ')' || $v === '}' || $v === 'in' || $v === 'of') {
                    return;
                } else {
                    $i++;
                    continue;
                }

                // 跳过初始化表达式，直到同层逗号 / 语句结束
                $depth = 0;
                while ($i < $this->n) {
                    $w = $this->v[$i];
                    if ($w === '(' || $w === '[' || $w === '{') {
                        $depth++;
                    } elseif ($w === ')' || $w === ']' || $w === '}') {
                        if ($depth === 0) {
                            return;
                        }
                        $depth--;
                    } elseif ($depth === 0 && ($w === ';' || $w === 'in' || $w === 'of')) {
                        return;
                    } elseif ($depth === 0 && $w === ',') {
                        $i++;
                        break;
                    }
                    $i++;
                }
            }
        }

        private function nearestFn(int $i): int
        {
            $sid = $this->scopeOf[$i] ?? 0;
            while ($sid > 0 && empty($this->scopes[$sid]['fn'])) {
                $sid = $this->scopes[$sid]['parent'];
            }

            return $sid;
        }

        private function bind(int $sid, string $name): void
        {
            if ($sid <= 0 || isset(self::$reserved[$name]) || $name === '' || isset($this->protected[$name])) {
                return; // 顶层不改名；模板插值里出现的名字也不改
            }
            if (isset($this->scopes[$sid]['names'][$name])) {
                return;
            }
            $this->scopes[$sid]['names'][$name] = $this->freshName();
        }

        private function freshName(): string
        {
            do {
                $name = '_0x' . substr(bin2hex(random_bytes(4)), 0, 6);
            } while (isset($this->taken[$name]));
            $this->taken[$name] = true;

            return $name;
        }

        private function resolveName(int $i, string $name): ?string
        {
            $sid = $this->scopeOf[$i] ?? 0;
            while ($sid >= 0) {
                if (isset($this->scopes[$sid]['names'][$name])) {
                    return $this->scopes[$sid]['names'][$name];
                }
                $sid = $this->scopes[$sid]['parent'];
            }

            return null;
        }

        // ==================================================================
        // 规划替换
        // ==================================================================

        private function planTokens(): void
        {
            $directiveEnd = $this->directiveEnd();

            for ($i = 0; $i < $this->n; $i++) {
                $t = $this->k[$i];
                $v = $this->v[$i];

                // ---- 字符串 ----
                if ($t === 'str') {
                    if ($this->shouldSkipString($i, $directiveEnd)) {
                        continue;
                    }
                    $val = $this->decodeString($v);
                    if ($val === null) {
                        continue;
                    }
                    $this->repl[$i] = $this->strCall($val);
                    continue;
                }

                // ---- 属性访问 a.b → a[dec(0x..)] ----
                if ($t === 'p' && ($v === '.' || $v === '?.')) {
                    $nx = $i + 1;
                    if ($nx >= $this->n || $this->k[$nx] !== 'id') {
                        continue;
                    }
                    $name = $this->v[$nx];
                    if (isset(self::$reserved[$name])) {
                        continue;
                    }
                    if ($i > 0 && $this->k[$i - 1] === 'num') {
                        continue;
                    }
                    // new.target / import.meta
                    if ($i > 0 && $this->k[$i - 1] === 'id'
                        && in_array($this->v[$i - 1], ['new', 'import'], true)
                        && in_array($name, ['target', 'meta'], true)) {
                        continue;
                    }
                    $inner = $this->strCall($name);
                    $this->repl[$i]  = ($v === '?.' ? '?.[' : '[') . $inner . ']';
                    $this->drop[$nx] = true;
                    continue;
                }

                // ---- 数字：整数转十六进制 ----
                if ($t === 'num' && preg_match('~^(?:0|[1-9][0-9]*)$~', $v)) {
                    $num = (int) $v;
                    if ($num >= 0 && $num <= 0xFFFFFFFF) {
                        $this->repl[$i] = '0x' . dechex($num);
                    }
                    continue;
                }

                // ---- 布尔 ----
                if ($t === 'id' && ($v === 'true' || $v === 'false')) {
                    if (isset($this->isProp[$i]) || isset($this->objKey[$i])) {
                        continue;
                    }
                    $this->repl[$i] = $v === 'true' ? '!![]' : '![]';
                    continue;
                }

                // ---- 标识符改名 ----
                if ($t !== 'id' || !$this->mangle) {
                    continue;
                }
                if (isset($this->isProp[$i]) || isset($this->objKey[$i]) || isset(self::$reserved[$v])) {
                    continue;
                }
                $new = $this->resolveName($i, $v);
                if ($new === null) {
                    continue;
                }
                if (isset($this->shorthand[$i])) {
                    $this->repl[$i] = $v . ':' . $new;
                } else {
                    $this->repl[$i] = $new;
                }
            }
        }

        private function directiveEnd(): int
        {
            $i = 0;
            while ($i + 1 < $this->n && $this->k[$i] === 'str' && $this->v[$i + 1] === ';') {
                $i += 2;
            }

            return $i;
        }

        private function shouldSkipString(int $i, int $directiveEnd): bool
        {
            if ($i < $directiveEnd) {
                return true; // 'use strict' 之类的指令
            }
            if (isset($this->objKey[$i])) {
                return true;
            }
            // import / export ... from 'x'
            if ($i > 0 && $this->k[$i - 1] === 'id'
                && in_array($this->v[$i - 1], ['from', 'import'], true)) {
                return true;
            }

            return false;
        }

        /** 把 JS 字符串字面量解成真实值；解不了返回 null（保持原样） */
        private function decodeString(string $lit): ?string
        {
            $quote = $lit[0];
            $body  = substr($lit, 1, -1);
            if ($body === '') {
                return '';
            }
            $out = '';
            $len = strlen($body);
            for ($i = 0; $i < $len; $i++) {
                $c = $body[$i];
                if ($c !== '\\') {
                    $out .= $c;
                    continue;
                }
                $i++;
                if ($i >= $len) {
                    break;
                }
                $e = $body[$i];
                switch ($e) {
                    case 'n': $out .= "\n"; break;
                    case 't': $out .= "\t"; break;
                    case 'r': $out .= "\r"; break;
                    case 'b': $out .= "\x08"; break;
                    case 'f': $out .= "\x0c"; break;
                    case 'v': $out .= "\x0b"; break;
                    case '0': $out .= "\0"; break;
                    case 'x':
                        $hex = substr($body, $i + 1, 2);
                        if (strlen($hex) === 2 && ctype_xdigit($hex)) {
                            $out .= chr(hexdec($hex));
                            $i += 2;
                        } else {
                            $out .= 'x';
                        }
                        break;
                    case 'u':
                        if (substr($body, $i + 1, 1) === '{') {
                            $close = strpos($body, '}', $i);
                            if ($close === false) {
                                return null;
                            }
                            $cp = hexdec(substr($body, $i + 2, $close - $i - 2));
                            $out .= $this->utf8((int) $cp);
                            $i = $close;
                        } else {
                            $hex = substr($body, $i + 1, 4);
                            if (strlen($hex) === 4 && ctype_xdigit($hex)) {
                                $out .= $this->utf8(hexdec($hex));
                                $i += 4;
                            } else {
                                $out .= 'u';
                            }
                        }
                        break;
                    case "\n":
                        break; // 续行
                    case "\r":
                        if ($i + 1 < $len && $body[$i + 1] === "\n") {
                            $i++;
                        }
                        break;
                    default:
                        $out .= $e;
                }
            }

            return $out === '' ? '' : $out;
        }

        private function utf8(int $cp): string
        {
            if ($cp < 0x80) {
                return chr($cp);
            }
            if ($cp < 0x800) {
                return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
            }
            if ($cp < 0x10000) {
                return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
            }

            return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
                . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }

        /** 取字符串在数组里的位序（首次出现时分配） */
        private function poolId(string $value): int
        {
            if (isset($this->pool[$value])) {
                return $this->pool[$value];
            }
            $id = count($this->poolList);
            $this->pool[$value] = $id;
            $this->poolList[$id] = $value;

            return $id;
        }

        /**
         * 生成「解码函数调用」文本。
         * 下标先用 \x01id\x01 占位，数组打乱后在 emit 里统一换算成最终下标。
         */
        private function strCall(string $value): string
        {
            $id = $this->poolId($value);

            return $this->aliasName . "(\x01" . $id . "\x01)";
        }

        // ==================================================================
        // 输出
        // ==================================================================

        private function emit(): string
        {
            // 数组顺序打乱，让下标与源码出现顺序无关
            $list = $this->poolList;
            $ids  = array_keys($list);
            shuffle($ids);
            $pos = [];
            foreach ($ids as $newPos => $oldId) {
                $pos[$oldId] = $newPos;
            }

            // 占位下标 → 打乱后的下标
            $fixed = [];
            foreach ($this->repl as $i => $text) {
                if (strpos($text, "\x01") === false) {
                    $fixed[$i] = $text;
                    continue;
                }
                $fixed[$i] = (string) preg_replace_callback(
                    '~\x01(\d+)\x01~',
                    static function (array $m) use ($pos): string {
                        $new = $pos[(int) $m[1]] ?? (int) $m[1];

                        return '0x' . dechex(0x100 + $new);
                    },
                    $text
                );
            }
            $this->repl = $fixed;

            $lits = [];
            foreach ($ids as $oldId) {
                $lits[] = $this->jsString($list[$oldId]);
            }
            $header = $this->header(implode(',', $lits));

            // ---- 组装正文 ----
            $body      = '';
            $indent    = 0;
            $count     = 0;
            $memo      = [];
            $lineStart = true;
            $prevIdx   = -1;

            // 换行：原样保留源码里的换行（ASI 依赖它，省略会改变语义）
            $newline = function () use (&$body, &$indent, &$lineStart): void {
                if ($body === '') {
                    return;
                }
                $body = rtrim($body, " \t");
                if (substr($body, -1) !== "\n") {
                    $body .= "\n";
                }
                $body .= str_repeat('    ', $indent);
                $lineStart = true;
            $prevIdx   = -1;
            };

            for ($i = 0; $i < $this->n; $i++) {
                if (isset($this->drop[$i])) {
                    continue;
                }
                $text = $this->repl[$i] ?? $this->v[$i];
                if ($text === '') {
                    continue;
                }
                $isPure = !isset($this->repl[$i]);

                if ($isPure && $text === '}') {
                    // 先降一级缩进再重算行首，否则会留下上一行的缩进空格
                    $indent = max(0, $indent - 1);
                    $newline();
                } elseif (!empty($this->nl[$i]) && !$lineStart) {
                    $newline();
                }

                if (!$lineStart && $body !== '' && substr($body, -1) !== "\n"
                    && $this->needSpace(substr($body, -1), $text[0], $prevIdx >= 0 && $this->k[$prevIdx] === 'num')) {
                    $body .= ' ';
                }
                $body .= $text;
                $lineStart = false;
                $prevIdx   = $i;
                $count += $memo[$text] ?? ($memo[$text] = $this->countTokens($text));

                if ($isPure && ($text === '{' || $text === ';')) {
                    if ($text === '{') {
                        $indent++;
                    }
                    $newline();
                }
            }

            $out = $header . "\n" . trim($body);

            $this->emittedTokens = $count + $this->countTokens($header);

            return $out;
        }

        /** 统计一段文本会被切成几个 token（自检用，带缓存） */
        private function countTokens(string $text): int
        {
            if ($text === '') {
                return 0;
            }
            if (preg_match('~^[A-Za-z_$][\w$]*$~', $text)) {
                return 1;
            }
            if (preg_match('~^0x[0-9a-f]+$~i', $text)) {
                return 1;
            }
            $probe = new self();
            $probe->tokenize($text);

            return $probe->n;
        }

        private function header(string $lits): string
        {
            $alias = $this->aliasName;
            $dec   = $this->decName;
            $arr   = $this->arrName;
            $off   = '0x' . dechex($this->offset);

            $nl = "\n";

            return 'const ' . $alias . ' = ' . $dec . ';' . $nl .
                'function ' . $arr . '() {' . $nl .
                '    const _0xT = [' . $lits . '];' . $nl .
                '    ' . $arr . ' = function () { return _0xT; };' . $nl .
                '    return ' . $arr . '();' . $nl .
                '}' . $nl .
                'function ' . $dec . '(_0xI, _0xK) {' . $nl .
                '    _0xI = _0xI - ' . $off . ';' . $nl .
                '    const _0xT = ' . $arr . '();' . $nl .
                '    let _0xV = _0xT[_0xI];' . $nl .
                '    return _0xV;' . $nl .
                '}';
        }

        private function jsString(string $value): string
        {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                throw new RuntimeException('json_encode failed');
            }
            // 换成单引号风格，贴近 javascript-obfuscator 的输出
            $inner = substr($json, 1, -1);
            $inner = str_replace(["\\\"", "'"], ['"', "\\'"], $inner);

            return "'" . $inner . "'";
        }

        private function needSpace(string $prevChar, string $nextChar, bool $prevIsNumber = false): bool
        {
            if ($prevChar === '' || $nextChar === '') {
                return false;
            }
            $word = static function (string $ch): bool {
                return (bool) preg_match('~[A-Za-z0-9_$\x80-\xff]~', $ch);
            };
            if ($word($prevChar) && $word($nextChar)) {
                return true;
            }
            // return /re/ 之类：标识符后面紧跟 / 或 * 必须留空格，否则会被当成除号
            if ($word($prevChar) && ($nextChar === '/' || $nextChar === '*')) {
                return true;
            }
            if ($prevChar === '/' && ($nextChar === '/' || $nextChar === '*')) {
                return true;
            }
            if (($prevChar === '+' || $prevChar === '-') && $prevChar === $nextChar) {
                return true;
            }
            // 1 .toString() 不能压成 1.toString()
            if ($prevIsNumber && $nextChar === '.') {
                return true;
            }

            return false;
        }
    }
}
