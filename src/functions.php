<?php


use Apix\Log\Logger\Stream;

if (!function_exists('logger')) {
    /**
     * @return Stream
     */
    function logger()
    {
        static $logger;
        if (is_null($logger)) $logger = new Apix\Log\Logger\Stream();
        return $logger;
    }
}

if (!function_exists('echo_now_init')) {
    /**
     * @return mixed
     */
    function echo_now_init()
    {
        global $beginTime;
        $beginTime = timestamp();
    }
}

if (!function_exists('echo_now')) {
    /**
     * @return mixed
     */
    function echo_now()
    {
        global $beginTime;
        logger()->info("[echo now] " . (timestamp() - $beginTime));
    }
}

if (!function_exists('make_random_str')) {
    function make_random_str($length = 32)
    {
        static $char = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if (!is_int($length) || $length < 0) {
            return false;
        }
        $string = pack("@$length");
        for ($i = 0, $clen = strlen($char); $i < $length; $i++) {
            $string[$i] = $char[mt_rand(0, $clen - 1)];
        }
        return $string;
    }
}


if (!function_exists('generateNewSessionID')) {
    function generateNewSessionID($length = 8)
    {
        static $char = 'ABCDEFGHIJKLMNOPQRSTUVWKYZ0123456789';
        if (!is_int($length) || $length < 0) {
            return false;
        }
        $string = pack("@$length");
        for ($i = 0, $clen = strlen($char); $i < $length; $i++) {
            $string[$i] = $char[mt_rand(0, $clen - 1)];
        }
        return $string;
    }
}


if (!function_exists('timestamp')) {
    function timestamp()
    {
        return floor(microtime(true) * 1000);
    }
}

if (!function_exists('is_assoc')) {
    function is_assoc($arr)
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
