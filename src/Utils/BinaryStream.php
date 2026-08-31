<?php

declare(strict_types=1);

namespace MediaServer\Utils;

class BinaryStream
{
    protected int $_index = 0;
    protected string $_data = '';

    public function __construct(string $data = "")
    {
        $this->_data = $data;
    }

    public function reset(): void
    {
        $this->_index = 0;
    }

    public function skip(int $length): void
    {
        $this->_index += $length;
    }

    public function flush(int $length = -1): string
    {
        if ($length == -1) {
            $d = $this->_data;
            $this->_data = "";
        } else {
            $d = substr($this->_data, 0, $length);
            $this->_data = substr($this->_data, $length);
        }
        $this->_index = 0;
        return $d;
    }


    public function dump(): string
    {
        return $this->_data;
    }

    public function has(int $len): bool
    {
        $pos = $len - 1;
        return isset($this->_data[$this->_index + $pos]);
    }

    public function clear(): void
    {
        $this->_data = substr($this->_data, $this->_index);
        $this->_index = 0;
    }

    public function begin(): self
    {
        $this->_index = 0;
        return $this;
    }

    public function move(int $pos): self
    {
        $this->_index = max(array(0, min(array($pos, strlen($this->_data)))));
        return $this;
    }

    public function end(): self
    {
        $this->_index = strlen($this->_data);
        return $this;
    }

    public function push(string $data): self
    {
        $this->_data .= $data;
        return $this;
    }

    //--------------------------------
    //		Writer
    //--------------------------------

    public function writeByte(int|string $value): void
    {
        $this->_data .= is_int($value) ? chr($value) : $value;
        $this->_index++;
    }

    public function writeInt16(int $value): void
    {
        $this->_data .= pack("s", $value);
        $this->_index += 2;
    }

    public function writeInt24(int $value): void
    {
        $this->_data .= substr(pack("N", $value), 1);
        $this->_index += 3;
    }

    public function writeInt32(int $value): void
    {
        $this->_data .= pack("N", $value);
        $this->_index += 4;
    }

    public function writeInt32LE(int $value): void
    {
        $this->_data .= pack("V", $value);
        $this->_index += 4;
    }

    public function write(string $value): void
    {
        $this->_data .= $value;
        $this->_index += strlen($value);
    }

    //-------------------------------
    //		Reader
    //-------------------------------

    public function readByte(): string
    {
        return ($this->_data[$this->_index++]);
    }

    public function readTinyInt(): int
    {
        return ord($this->readByte());
    }

    public function readInt16(): int
    {
        return ($this->readTinyInt() << 8) + $this->readTinyInt();
    }

    public function readInt16LE(): int
    {
        return $this->readTinyInt() + ($this->readTinyInt() << 8);
    }

    public function readInt24(): int
    {
        $m = unpack("N", "\x00" . substr($this->_data, $this->_index, 3));
        $this->_index += 3;
        return $m[1];
    }

    public function readInt32(): int
    {
        return $this->read("N", 4);
    }

    public function readInt32LE(): int
    {
        return $this->read("V", 4);
    }

    public function readRaw(int $length = 0): string
    {
        if ($length == 0)
            $length = strlen($this->_data) - $this->_index;
        $datas = substr($this->_data, $this->_index, $length);
        $this->_index += $length;
        return $datas;
    }

    /**
     * 非破坏性读取剩余字节（不推进指针）。
     * 用于解析 SPS/AudioSpecificConfig 等需要保留原始字节供后续读取的场景。
     */
    public function readRawRemaining(): string
    {
        return substr($this->_data, $this->_index);
    }

    private function read(string $type, int $size): int
    {
        $m = unpack("$type", substr($this->_data, $this->_index, $size));
        $this->_index += $size;
        return $m[1];
    }

    //-------------------------------
    //		Tag & rollback
    //-------------------------------

    protected int $tagPos = 0;

    public function tag(): void
    {
        $this->tagPos = $this->_index;
    }

    public function rollBack(): void
    {
        $this->_index = $this->tagPos;
    }


}