<?php

declare(strict_types=1);

namespace MediaServer\Utils;


use Evenement\EventEmitterInterface;

interface WMChunkStreamInterface extends EventEmitterInterface
{

    public function write(string $data): void;

    public function end(?string $data = null): void;

    public function close(): void;

}