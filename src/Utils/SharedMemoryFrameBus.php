<?php

declare(strict_types=1);

namespace MediaServer\Utils;

/**
 * 共享内存帧总线：一帧写一次，多进程读。
 *
 * 布局 v4（单 GOP 环，段大小 = 一个 GOP 的数据量）：
 *   [0..4095]        段头
 *   [4096...]        4 个序列头专用槽（各 64KB，常驻不覆盖）
 *   [frameBase...]   帧索引区：frameSlots × 40B（seq % frameSlots 定位）
 *   [gopBase...]     GOP 环：gopBlocks × 4KB
 *
 * 写入规则（按 GOP 组织）：I 帧把写指针归零，本 GOP 后续帧连续追加；
 * 下一个 I 帧覆盖上一个 GOP。读游标落后被覆盖时 read() 返回 null，
 * 消费者 fail-fast 打警告并重同步到下个 I 帧（设计内丢帧，不静默损坏）。
 * 新播放端从 lastKeySeq 读到 writeSeq 拿到完整 GOP。
 *
 * 段头关键字段：
 *   0   magic "MSFR"
 *   4   version (4)
 *   8   path[256]
 *   264 frameSlots     帧索引槽数
 *   268 gopBlocks      GOP 环块数（每块 4KB）
 *   272 writeSeq (8B)
 *   280 lastKeySeq (8B)
 *   288 minRecSeq (8B)
 *   296 refCount (8B)
 *   304 producerPid (8B)
 *   312 metaSeq (8B)    最近 metadata 帧 seq
 *   320 avcSeq (8B)     最近 AVC 序列头 seq
 *   328 aacSeq (8B)     最近 AAC 序列头 seq
 *   336 hevcSeq (8B)    最近 HEVC 序列头 seq
 *   344 flags (4B)      bit0=hasAudio bit1=hasVideo
 *   352 dataWrite (8B)  GOP 环写指针（字节，I 帧归零重新开始）
 */
class SharedMemoryFrameBus
{
    private const MAGIC = 'MSFR';
    private const VERSION = 4;

    private const OFF_MAGIC = 0;
    private const OFF_VERSION = 4;
    private const OFF_PATH = 8;
    private const OFF_FRAME_SLOTS = 264;
    private const OFF_GOP_BLOCKS = 268;
    private const OFF_WRITE_SEQ = 272;
    private const OFF_LAST_KEY_SEQ = 280;
    private const OFF_MIN_REC_SEQ = 288;
    private const OFF_REF_COUNT = 296;
    private const OFF_PRODUCER_PID = 304;
    private const OFF_META_SEQ = 312;
    private const OFF_AVC_SEQ = 320;
    private const OFF_AAC_SEQ = 328;
    private const OFF_HEVC_SEQ = 336;
    private const OFF_FLAGS = 344;
    private const OFF_DATA_WRITE = 352;
    private const HEADER_SIZE = 4096;

    /** 序列头区：紧跟段头，4 个固定槽（meta/avc/aac/hevc），各 64KB */
    public const SEQ_META = 0;
    public const SEQ_AVC = 1;
    public const SEQ_AAC = 2;
    public const SEQ_HEVC = 3;

    private const SEQ_SLOT_SIZE = 65536;

    /** 帧索引槽长：len(4)+seq(8)+crc(4)+flags(4)+ts(8)+dataOff(8) = 40 */
    private const FRAME_HEAD = 40;
    private const GOP_BLOCK = 4096;

    /** @var \Shmop|resource|false */
    private $shm = false;

    private int $frameSlots;
    private int $gopBlocks;
    private int $createdByMe = 0;

    /**
     * @param string $path 推流路径
     * @param int $frameSlots 帧索引槽数（0=默认 200，约 3s GOP @30fps+交错音频）
     * @param int $dataKb GOP 环容量 KB（0=默认 2048KB ≈ 4Mbps 2s GOP 的 2 倍余量）
     * @param bool $create 不存在时是否创建（生产者=true，消费者=false）
     */
    public function __construct(
        private readonly string $path,
        int $frameSlots = 0,
        int $dataKb = 0,
        bool $create = false
    ) {
        if (!extension_loaded('shmop')) {
            throw new \RuntimeException('SharedMemoryFrameBus requires ext-shmop');
        }

        // 内存预算 = 一个 GOP 的数据量：
        // 默认 2MB GOP 环 + 200×40B 索引 ≈ 2.1MB/流（最初布局 42MB/流）
        $this->frameSlots = $frameSlots > 0 ? $frameSlots : 200;
        $this->gopBlocks = $dataKb > 0 ? (int)ceil($dataKb * 1024 / self::GOP_BLOCK) : 512;

        // 布局：段头(4KB) + 4×64KB 序列头 + 帧索引(frameSlots×40) + GOP 环(gopBlocks×4KB)
        $size = self::HEADER_SIZE
            + 4 * self::SEQ_SLOT_SIZE
            + $this->frameSlots * self::FRAME_HEAD
            + $this->gopBlocks * self::GOP_BLOCK;

        $key = self::keyForPath($path);

        if ($create) {
            $this->shm = @shmop_open($key, 'n', 0644, $size);
            if ($this->shm !== false) {
                $this->createdByMe = 1;
                $this->initHeader();
            }
        }

        if ($this->shm === false) {
            $this->shm = @shmop_open($key, 'w', 0, 0);
        }

        if ($this->shm === false) {
            throw new \RuntimeException("Failed to open shm segment for path: {$path}");
        }

        // 消费者以段头记录的实际布局为准（可能与构造参数不同）
        if (!$this->createdByMe) {
            $h = unpack('NframeSlots/NgopBlocks', shmop_read($this->shm, self::OFF_FRAME_SLOTS, 8));
            $this->frameSlots = $h['frameSlots'];
            $this->gopBlocks = $h['gopBlocks'];
        }
    }

    /**
     * 确定性的 shm key（所有进程一致）。
     */
    public static function keyForPath(string $path): int
    {
        // crc32 在 32 位有符号/无符号间差异，统一成正整数
        return crc32($path) & 0x7FFFFFFF;
    }

    private function initHeader(): void
    {
        shmop_write($this->shm, self::MAGIC, self::OFF_MAGIC);
        shmop_write($this->shm, pack('N', self::VERSION), self::OFF_VERSION);
        shmop_write($this->shm, substr($this->path, 0, 255), self::OFF_PATH);
        shmop_write($this->shm, pack('N', $this->frameSlots), self::OFF_FRAME_SLOTS);
        shmop_write($this->shm, pack('N', $this->gopBlocks), self::OFF_GOP_BLOCKS);
        shmop_write($this->shm, pack('J', 0), self::OFF_WRITE_SEQ);
        shmop_write($this->shm, pack('J', 0), self::OFF_LAST_KEY_SEQ);
        shmop_write($this->shm, pack('J', 0), self::OFF_MIN_REC_SEQ);
        shmop_write($this->shm, pack('J', 0), self::OFF_REF_COUNT);
        shmop_write($this->shm, pack('J', getmypid()), self::OFF_PRODUCER_PID);
        shmop_write($this->shm, pack('J', 0), self::OFF_META_SEQ);
        shmop_write($this->shm, pack('J', 0), self::OFF_AVC_SEQ);
        shmop_write($this->shm, pack('J', 0), self::OFF_AAC_SEQ);
        shmop_write($this->shm, pack('J', 0), self::OFF_HEVC_SEQ);
        shmop_write($this->shm, pack('N', 0), self::OFF_FLAGS);
        shmop_write($this->shm, pack('J', 0), self::OFF_DATA_WRITE);
    }

    private function frameBase(): int
    {
        return self::HEADER_SIZE + 4 * self::SEQ_SLOT_SIZE;
    }

    private function gopBase(): int
    {
        return $this->frameBase() + $this->frameSlots * self::FRAME_HEAD;
    }

    private function gopSize(): int
    {
        return $this->gopBlocks * self::GOP_BLOCK;
    }

    /**
     * 校验段是否属于本路径（防止 crc32 冲突）。
     */
    public function validate(): bool
    {
        $magic = shmop_read($this->shm, self::OFF_MAGIC, 4);
        if ($magic !== self::MAGIC) return false;
        $version = unpack('N', shmop_read($this->shm, self::OFF_VERSION, 4))[1];
        if ($version !== self::VERSION) return false;
        $storedPath = rtrim(shmop_read($this->shm, self::OFF_PATH, 256), "\0");
        return $storedPath === $this->path;
    }

    public function writeSeq(): int
    {
        return (int)unpack('J', shmop_read($this->shm, self::OFF_WRITE_SEQ, 8))[1];
    }

    public function lastKeySeq(): int
    {
        return (int)unpack('J', shmop_read($this->shm, self::OFF_LAST_KEY_SEQ, 8))[1];
    }

    public function metaSeq(): int { return (int)unpack('J', shmop_read($this->shm, self::OFF_META_SEQ, 8))[1]; }
    public function avcSeq(): int  { return (int)unpack('J', shmop_read($this->shm, self::OFF_AVC_SEQ, 8))[1]; }
    public function aacSeq(): int  { return (int)unpack('J', shmop_read($this->shm, self::OFF_AAC_SEQ, 8))[1]; }
    public function hevcSeq(): int { return (int)unpack('J', shmop_read($this->shm, self::OFF_HEVC_SEQ, 8))[1]; }

    /**
     * 读序列头（专用槽常驻，不会被环形覆盖）。
     */
    public function readMeta(): ?array { return $this->readSeqSlot(self::SEQ_META); }
    public function readAvcSequence(): ?array { return $this->readSeqSlot(self::SEQ_AVC); }
    public function readHevcSequence(): ?array { return $this->readSeqSlot(self::SEQ_HEVC); }
    public function readAacSequence(): ?array { return $this->readSeqSlot(self::SEQ_AAC); }

    private function readSeqSlot(int $which): ?array
    {
        $offset = self::HEADER_SIZE + $which * self::SEQ_SLOT_SIZE;
        $raw = @shmop_read($this->shm, $offset, self::SEQ_SLOT_SIZE);
        if ($raw === false || strlen($raw) < 28) return null;

        $h = unpack('Nlen/Jseq/Ncrc/Nflags/Jtimestamp', substr($raw, 0, 28));
        if ($h['len'] <= 0) return null;
        if ($h['len'] > self::SEQ_SLOT_SIZE - 28) return null;

        $payload = substr($raw, 28, $h['len']);
        if (crc32($payload) !== $h['crc']) return null;

        return [
            'seq' => $h['seq'],
            'len' => $h['len'],
            'crc' => $h['crc'],
            'flags' => $h['flags'],
            'frameType' => ($h['flags'] >> 8) & 3,
            'special' => ($h['flags'] >> 16) & 7,
            'isKey' => ($h['flags'] & 1) !== 0,
            'timestamp' => $h['timestamp'],
            'payload' => $payload,
        ];
    }

    /**
     * 写帧。返回写入的 seq。
     *
     * GOP 段式写入：I 帧（isKey=true）把数据写指针归零重头写，
     * 后续帧连续追加；下一个 I 帧到来时整体覆盖上一个 GOP。
     *
     * @param string $payload 帧数据
     * @param bool $isKey 是否关键帧
     * @param int $frameType 帧类型（0=meta 1=video 2=audio）
     * @param int $timestamp 时间戳
     * @param int $special 特殊帧：0=普通 1=meta 2=avcseq 3=aacseq 4=hevcseq
     * @return int seq
     */
    public function write(string $payload, bool $isKey = false, int $frameType = 1, int $timestamp = 0, int $special = 0): int
    {
        $len = strlen($payload);

        // 序列头写专用槽（常驻不覆盖），不占用环形 seq
        if ($special >= 1 && $special <= 4) {
            if ($len > self::SEQ_SLOT_SIZE - 28) {
                throw new \RuntimeException("Sequence frame too large: $len");
            }
            $flags = ($isKey ? 1 : 0) | (($frameType & 3) << 8) | (($special & 7) << 16);
            $head = pack('NJNNJ', $len, 0, crc32($payload), $flags, $timestamp);
            shmop_write($this->shm, $head . $payload, self::HEADER_SIZE + ($special - 1) * self::SEQ_SLOT_SIZE);
            return 0;
        }

        $gopSize = $this->gopSize();
        if ($len > $gopSize) {
            throw new \RuntimeException("Frame too large: $len > GOP ring $gopSize");
        }

        $seq = $this->writeSeq() + 1;

        // I 帧：写指针归零（新 GOP 覆盖旧 GOP）
        $dataWrite = $isKey
            ? 0
            : (int)unpack('J', shmop_read($this->shm, self::OFF_DATA_WRITE, 8))[1];

        if ($dataWrite + $len > $gopSize) {
            // GOP 超过环容量：丢弃本 GOP 已写部分，从环首重来（避免越界写）
            $dataWrite = 0;
        }

        $dataOff = $dataWrite;

        // 帧索引槽：seq % frameSlots 定位，dataOff 为 GOP 环内偏移
        $flags = ($isKey ? 1 : 0) | (($frameType & 3) << 8);
        $head = pack('NJNNJ', $len, $seq, crc32($payload), $flags, $timestamp)
            . pack('J', $dataOff);
        shmop_write($this->shm, $head, $this->frameBase() + ($seq % $this->frameSlots) * self::FRAME_HEAD);
        shmop_write($this->shm, $payload, $this->gopBase() + $dataOff);

        // 发布顺序：先推进数据指针，再推进 writeSeq（消费者以 writeSeq 为准）
        shmop_write($this->shm, pack('J', $dataWrite + $len), self::OFF_DATA_WRITE);
        if ($isKey) {
            shmop_write($this->shm, pack('J', $seq), self::OFF_LAST_KEY_SEQ);
        }
        shmop_write($this->shm, pack('J', $seq), self::OFF_WRITE_SEQ);

        return $seq;
    }

    /**
     * 读指定 seq 的帧。
     *
     * @return array{seq:int,len:int,crc:int,flags:int,payload:string}|null
     */
    public function read(int $seq): ?array
    {
        if ($seq <= 0 || $seq > $this->writeSeq()) return null;

        // 索引槽尚未写入该 seq（槽位被更新的 seq 覆盖）时槽内 seq 不匹配 → null
        $foff = $this->frameBase() + ($seq % $this->frameSlots) * self::FRAME_HEAD;
        $raw = @shmop_read($this->shm, $foff, self::FRAME_HEAD);
        if ($raw === false || strlen($raw) < self::FRAME_HEAD) return null;

        $h = unpack('Nlen/Jseq/Ncrc/Nflags/Jtimestamp/JdataOff', $raw);
        if ($h['seq'] !== $seq || $h['len'] <= 0) return null;
        if ($h['len'] > $this->gopSize()) return null;

        $segSize = @shmop_size($this->shm);
        $poff = $this->gopBase() + $h['dataOff'];
        if ($segSize === false || $poff + $h['len'] > $segSize) return null;

        $payload = @shmop_read($this->shm, $poff, $h['len']);
        if ($payload === false || strlen($payload) < $h['len']) return null;
        if (crc32($payload) !== $h['crc']) return null;

        return [
            'seq' => $h['seq'],
            'len' => $h['len'],
            'crc' => $h['crc'],
            'flags' => $h['flags'],
            'frameType' => ($h['flags'] >> 8) & 3,
            'special' => ($h['flags'] >> 16) & 7,
            'isKey' => ($h['flags'] & 1) !== 0,
            'timestamp' => $h['timestamp'],
            'payload' => $payload,
        ];
    }

    /**
     * 读取最新帧（播放端允许丢帧时用）。
     */
    public function readLatest(): ?array
    {
        return $this->read($this->writeSeq());
    }

    public function frameSlots(): int { return $this->frameSlots; }
    public function gopBlocks(): int { return $this->gopBlocks; }
    public function createdByMe(): bool { return (bool)$this->createdByMe; }

    /** 兼容旧接口：publisher 用 frameSlots 做 GOP 上限告警 */
    public function slotCount(): int { return $this->frameSlots; }

    /**
     * 删除段（仅创建者调用）。
     */
    public function destroy(): void
    {
        if ($this->shm !== false) {
            @shmop_delete($this->shm);
            shmop_close($this->shm);
            $this->shm = false;
        }
    }
}
