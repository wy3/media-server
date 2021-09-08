<?php
/**
 * Created by PhpStorm.
 * User: what_
 * Date: 2021/9/9
 * Time: 2:07
 */

namespace MediaServer\Channel;


trait FrameProtocol
{
    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @return int
     */
    public static function input($buffer)
    {
        if (\strlen($buffer) < 4) {
            return 0;
        }
        $unpack_data = \unpack('Ntotal_length', $buffer);
        return $unpack_data['total_length'];
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        return \substr($buffer, 4);
    }

    /**
     * Encode.
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        $total_length = 4 + \strlen($buffer);
        return \pack('N', $total_length) . $buffer;
    }
}