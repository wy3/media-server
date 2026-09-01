<?php

declare(strict_types=1);

/**
 * 极简测试 harness —— 零依赖（不引入 PHPUnit，避免动 composer）。
 *
 * 用法见 tests/run_all.php。断言失败抛 RuntimeException，由 runner 捕获记 FAIL。
 */

$GLOBALS['__TEST_BATCH'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__TEST_BATCH'][$name] = $fn;
}

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function checkSame(mixed $expected, mixed $actual, string $msg): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s — expected %s, got %s',
            $msg,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function checkCount(int $expected, array $actual, string $msg): void
{
    checkSame($expected, count($actual), $msg . ' (count)');
}

function checkStrContains(string $needle, string $haystack, string $msg): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(sprintf('%s — 字节流中未找到 %s', $msg, bin2hex($needle) . " ({$needle})"));
    }
}

/**
 * 执行当前批次已注册的测试，返回失败数。
 */
function run_batch(string $suiteTitle): int
{
    $failures = 0;
    $ran = 0;
    echo "==== [{$suiteTitle}] ====\n";
    foreach ($GLOBALS['__TEST_BATCH'] as $name => $fn) {
        $ran++;
        try {
            $fn();
            echo "  PASS  {$name}\n";
        } catch (Throwable $e) {
            $failures++;
            $file = basename((string)($e->getFile() ?? ''));
            echo "  FAIL  {$name} : {$e->getMessage()} @ {$file}:{$e->getLine()}\n";
        }
    }
    $GLOBALS['__TEST_BATCH'] = [];
    if ($ran === 0) {
        echo "  (no tests)\n";
    }
    return $failures;
}
