<?php

declare(strict_types=1);


use Apix\Log\Logger\Stream;

if (!function_exists('logger')) {
    /**
     * @return Stream
     */
    function logger(): Stream
    {
        static $logger;
        if (is_null($logger)) $logger = new Apix\Log\Logger\Stream();
        return $logger;
    }
}

if (!function_exists('echo_now_init')) {
    /**
     * @return void
     */
    function echo_now_init(): void
    {
        global $beginTime;
        $beginTime = timestamp();
    }
}

if (!function_exists('echo_now')) {
    /**
     * @return void
     */
    function echo_now(): void
    {
        global $beginTime;
        logger()->info("[echo now] " . (timestamp() - $beginTime));
    }
}

if (!function_exists('make_random_str')) {
    function make_random_str(int $length = 32): string|false
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
    function generateNewSessionID(int $length = 8): string|false
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
    /**
     * 返回毫秒级时间戳（整数，供强类型属性直接赋值）
     * @return int
     */
    function timestamp(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}

if (!function_exists('is_assoc')) {
    function is_assoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
