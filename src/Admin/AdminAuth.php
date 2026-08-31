<?php

declare(strict_types=1);

namespace MediaServer\Admin;

/**
 * 管理后台登录鉴权（进程内 token）。
 *
 *  - 账号密码由 start.php 注入静态属性配置
 *  - token 保存在进程内存中，服务重启后失效
 *  - 默认有效期 24 小时
 */
class AdminAuth
{
    /** 管理账号 */
    public static string $username = 'admin';

    /** 管理密码 */
    public static string $password = 'admin123';

    /** 服务启动时间戳（毫秒），用于统计运行时长 */
    public static int $startTime = 0;

    /** token 有效期（秒） */
    public static int $tokenTtl = 86400;

    /** @var array<string, int> token => 过期时间戳（秒） */
    protected static array $tokens = [];

    /**
     * 登录校验，成功返回 token 信息，失败返回 null。
     *
     * @return array{token:string,expires:int}|null
     */
    public static function login(string $username, string $password): ?array
    {
        if (!hash_equals(self::$username, $username) || !hash_equals(self::$password, $password)) {
            return null;
        }

        $expires = time() + self::$tokenTtl;
        $token = bin2hex(random_bytes(32));
        self::$tokens[$token] = $expires;

        //清理过期 token，避免无限增长
        foreach (self::$tokens as $t => $exp) {
            if ($exp < time()) {
                unset(self::$tokens[$t]);
            }
        }

        return [
            'token' => $token,
            'expires' => $expires,
        ];
    }

    /**
     * 登出，使 token 立即失效。
     */
    public static function logout(string $token): bool
    {
        if ($token === '' || !isset(self::$tokens[$token])) {
            return false;
        }
        unset(self::$tokens[$token]);
        return true;
    }

    /**
     * 校验 token 是否有效。
     */
    public static function check(string $token): bool
    {
        if ($token === '' || !isset(self::$tokens[$token])) {
            return false;
        }
        if (self::$tokens[$token] < time()) {
            unset(self::$tokens[$token]);
            return false;
        }
        return true;
    }
}
