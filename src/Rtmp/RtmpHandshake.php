<?php


namespace MediaServer\Rtmp;

class RtmpHandshake
{

    const RTMP_HANDSHAKE_UNINIT = 0;
    const RTMP_HANDSHAKE_C0 = 1;
    const RTMP_HANDSHAKE_C1 = 2;
    const RTMP_HANDSHAKE_C2 = 3;


    static function handshakeGenerateS0S1S2($c1)
    {
        $data = pack("Ca1536a1536",
            3,
            self::handshakeGenerateS1(),
            self::handshakeGenerateS2($c1)
        );
        return $data;
    }

    static function handshakeGenerateS1()
    {
        $s1 = pack('NNa1528',
            timestamp(),
            0,
            make_random_str(1528)
        );
        return $s1;
    }

    static function handshakeGenerateS2($c1)
    {
        $c1Data = unpack('Ntimestamp/Nzero/a1528random', $c1);
        $s2 = pack('NNa1528',
            $c1Data['timestamp'],
            timestamp(),
            $c1Data['random']
        );
        return $s2;
    }

}
