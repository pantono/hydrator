<?php

declare(strict_types=1);

namespace Pantono\Hydrator;

/** Bind live proxies to their loading context without serializing service dependencies. */
class ProxyHydratorRegistry
{
    /** @var \WeakMap<object, Hydrator>|null */
    private static ?\WeakMap $hydrators = null;

    public static function bind(object $proxy, Hydrator $hydrator): void
    {
        $hydrators = self::$hydrators ??= new \WeakMap();
        $hydrators[$proxy] = $hydrator;
    }

    public static function get(object $proxy): ?Hydrator
    {
        return self::$hydrators[$proxy] ?? null;
    }
}
