<?php

declare(strict_types=1);

use MediaServer\Recorder\RecorderManager;

//------------------------------
// sanitizePath / recordDir
// P0(A3) 回归 + 常规路径行为锁定，Swoole 迁移时必须保持一致
//------------------------------

test('sanitizePath: 常规路径折叠为下划线', function () {
    checkSame('live_stream', RecorderManager::sanitizePath('live/stream'), 'slash');
    checkSame('live_stream', RecorderManager::sanitizePath('/live/stream/'), '首尾斜杠');
    checkSame('a_b_c', RecorderManager::sanitizePath('a//b//c'), '多斜杠');
    checkSame('a_b', RecorderManager::sanitizePath('a\\b'), '反斜杠');
    checkSame('default', RecorderManager::sanitizePath(''), '空串');
    checkSame('default', RecorderManager::sanitizePath('///'), '纯斜杠');
});

test('sanitizePath: 穿越分量全部拦截（P0 回归）', function () {
    // 纯穿越输入折叠为 default
    foreach (['..', '.', '/..', '../..', '....//....//'] as $evil) {
        $s = RecorderManager::sanitizePath($evil);
        checkSame('default', $s, "input={$evil} 应折叠为 default，实际 {$s}");
    }
    // 混合输入：'..' 分量被折叠，剩余安全字面量保留；断言结果不含任何 '.' 分量
    foreach (['x/..', 'a/../../../Windows', '..\\..\\evil', 'live/../../../etc/passwd'] as $evil) {
        $s = RecorderManager::sanitizePath($evil);
        check(!str_contains($s, '.'), "input={$evil} 结果 {$s} 不得含 '.'（穿越已消除）");
        check($s !== '', "input={$evil} 结果不得为空");
    }
});

test('sanitizePath: 白名单外的控制字符与空格', function () {
    checkSame('a_b', RecorderManager::sanitizePath("a\0b"), '空字节');
    checkSame('a_b', RecorderManager::sanitizePath('a b'), '空格');
    checkSame('a_b', RecorderManager::sanitizePath("a\nb"), '换行');
});

test('sanitizePath: 中文流名保留', function () {
    checkSame('live_摄像头1', RecorderManager::sanitizePath('/live/摄像头1'), '中文');
});

test('recordDir: 所有结果都落在 recordPath 内', function () {
    $tmp = sys_get_temp_dir() . '/ms_record_test_' . getmypid();
    if (!is_dir($tmp)) {
        mkdir($tmp, 0777, true);
    }
    $old = RecorderManager::$recordPath;
    RecorderManager::$recordPath = $tmp;
    try {
        $base = realpath($tmp);
        foreach (['live/stream', '..', '/..', 'a/../../../x', 'x', 'default', '摄像头2'] as $input) {
            $dir = RecorderManager::recordDir($input);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true); // 创建后 realpath 才有判定意义
            }
            $real = realpath($dir);
            check($real !== false, "recordDir({$input}) 目录应存在: {$dir}");
            check(str_starts_with($real, $base . DIRECTORY_SEPARATOR), "recordDir({$input}) 逃逸: {$real}");
        }
    } finally {
        RecorderManager::$recordPath = $old;
    }
});
