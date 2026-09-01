<?php

declare(strict_types=1);

namespace MediaServer\Utils;

use Workerman\Timer;

/**
 * 运行时定时器门面。
 *
 * 协议层代码统一调用 Timer::add / Timer::del（调用方以
 * `use MediaServer\Utils\RuntimeTimer as Timer;` 引入，签名与 Workerman 一致），
 * 底层驱动在服务启动时选择：Workerman（默认）或 Swoole。
 *
 * Workerman 间隔单位为秒；Swoole 为毫秒，此处做一次换算。
 */
final class RuntimeTimer
{
    /** @var string 'workerman'|'swoole' */
    public static string $driver = 'workerman';

    /**
     * @param float $interval 秒
     * @param callable $cb
     * @param array $args
     * @param bool $persistent true=周期执行（默认），false=只执行一次
     * @return int 定时器 id（0 表示注册未生效，如事件循环未启动；del(0) 为安全 no-op）
     */
    public static function add(float $interval, callable $cb, array $args = [], bool $persistent = true): int
    {
        if (self::$driver === 'swoole') {
            $ms = max(1, (int)round($interval * 1000));
            $wrapped = static function () use ($cb, $args) {
                try {
                    $cb(...$args);
                } catch (\Throwable $e) {
                    logger()->error('timer error: {msg}', ['msg' => $e->getMessage()]);
                }
            };
            return $persistent ? \Swoole\Timer::tick($ms, $wrapped) : \Swoole\Timer::after($ms, $wrapped);
        }
        /** @var int|null $id */
        $id = Timer::add($interval, $cb, $args, $persistent);
        return $id ?? 0;
    }

    public static function del(int $timerId): void
    {
        if ($timerId <= 0) {
            return;
        }
        if (self::$driver === 'swoole') {
            \Swoole\Timer::clear($timerId);
            return;
        }
        Timer::del($timerId);
    }
}
