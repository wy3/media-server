<?php

declare(strict_types=1);

namespace MediaServer\Rtmp;


trait RtmpAuthorizeTrait
{

    public function verifyAuth(): bool
    {
        return true;
    }

}