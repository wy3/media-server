<?php

declare(strict_types=1);

use MediaServer\Recorder\RecordIndex;
use MediaServer\Recorder\RecorderManager;

//------------------------------
// RecordIndex（SQLite 索引层）
// 使用临时目录的独立 db，不触碰项目 record/
//------------------------------

function recordIndexTmpDir(): string
{
    static $dir = null;
    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/ms_index_test_' . getmypid();
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
        } else {
            mkdir($dir, 0777, true);
        }
    }
    return $dir;
}

/** 每个测试独享一个干净 db：重置静态 PDO 与 recordPath */
function freshIndex(): void
{
    RecorderManager::$recordPath = recordIndexTmpDir();
    $ref = new ReflectionProperty(RecordIndex::class, 'pdo');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
}

function seg(array $over = []): array
{
    return array_merge([
        'file' => 'seg_0001.mp4',
        'start' => 1000,
        'end' => 61000,
        'duration' => 60000,
        'size' => 123456,
    ], $over);
}

test('append + list 基本闭环', function () {
    freshIndex();
    check(RecordIndex::appendSegment('/live/cam1', ['video' => ['codec' => 'avc1']], seg()), '写入应成功');

    $rows = RecordIndex::listSegments('/live/cam1');
    checkCount(1, $rows, '一条分段');
    checkSame('seg_0001.mp4', $rows[0]['file'], 'file');
    checkSame(1000, $rows[0]['start'], 'start');
});

test('路径规范化：带/不带前导斜杠等价', function () {
    freshIndex();
    RecordIndex::appendSegment('live/cam2', [], seg());
    checkCount(1, RecordIndex::listSegments('/live/cam2'), '带斜杠查询');
    checkCount(1, RecordIndex::listSegments('live/cam2'), '不带斜杠查询');
    // 已知行为（锁定）：normalizePath 只处理首尾斜杠，不折叠内部连续斜杠，
    // 双斜杠查询不等价 —— 迁移时如改进此行为需同步更新本用例
    checkCount(0, RecordIndex::listSegments('/live//cam2'), '双斜杠查询不等价（当前实现）');
});

test('唯一约束幂等：重复追加同一分段不产生脏数据', function () {
    freshIndex();
    RecordIndex::appendSegment('/live/cam3', [], seg());
    check(RecordIndex::appendSegment('/live/cam3', [], seg()), '重复写入返回 true（幂等）');
    checkCount(1, RecordIndex::listSegments('/live/cam3'), '仍是一条');

    RecordIndex::appendSegment('/live/cam3', [], seg(['file' => 'seg_0002.mp4', 'start' => 61000, 'end' => 121000]));
    checkCount(2, RecordIndex::listSegments('/live/cam3'), '第二段正常入库');
});

test('listSegments 按 start 升序且支持全量查询', function () {
    freshIndex();
    RecordIndex::appendSegment('/live/cam4', [], seg(['file' => 's2.mp4', 'start' => 61000, 'end' => 121000]));
    RecordIndex::appendSegment('/live/cam4', [], seg(['file' => 's1.mp4', 'start' => 1000, 'end' => 61000]));
    RecordIndex::appendSegment('/live/other', [], seg(['file' => 'o1.mp4', 'start' => 500, 'end' => 1000]));

    $rows = RecordIndex::listSegments('/live/cam4');
    checkSame('s1.mp4', $rows[0]['file'], '升序第一');
    checkSame('s2.mp4', $rows[1]['file'], '升序第二');

    $all = RecordIndex::listSegments(null);
    check(count($all) >= 3, '全量查询应含所有流');
});

test('getStreamIndex 返回元数据与分段', function () {
    freshIndex();
    $meta = ['video' => ['codec' => 'avc1', 'width' => 320], 'audio' => ['codec' => 'mp4a']];
    RecordIndex::appendSegment('/live/cam5', $meta, seg());

    $idx = RecordIndex::getStreamIndex('/live/cam5');
    check($idx !== null, '索引应存在');
    checkSame('/live/cam5', $idx['path'], 'path');
    checkSame(320, $idx['meta']['video']['width'] ?? null, 'meta 已持久化');
    checkCount(1, $idx['segments'], 'segments');
});

test('空 meta 不覆盖已有元数据', function () {
    freshIndex();
    RecordIndex::appendSegment('/live/cam6', ['video' => ['codec' => 'avc1']], seg());
    RecordIndex::appendSegment('/live/cam6', [], seg(['file' => 's2.mp4', 'start' => 61000]));
    $idx = RecordIndex::getStreamIndex('/live/cam6');
    checkSame('avc1', $idx['meta']['video']['codec'] ?? null, '元数据应保留');
});

test('getStreamIndex 不存在的流返回 null', function () {
    freshIndex();
    checkSame(null, RecordIndex::getStreamIndex('/live/no_such'), 'null');
});
