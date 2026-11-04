<?php

declare(strict_types=1);

namespace Laraflow\Prometheus;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\APCng;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;

/**
 * Builds the storage adapter `laraflow-prometheus.storage` names.
 *
 * - `redis` (the default): a connection from the application's own
 *   `database.redis` configuration, through the phpredis extension.
 *   Every process writes to and the scrape reads from one place.
 * - `apcu`: shared memory within one server. Correct for a single host
 *   running web and workers under one PHP-FPM pool; wrong for anything
 *   else, because each host then answers with only its own counts.
 * - `memory`: this process only. For tests; in production a scrape
 *   would see nothing a worker recorded.
 */
final class Storage
{
    public static function make(Config $config): Adapter
    {
        $storage = $config->get('laraflow-prometheus.storage', 'redis');

        return match ($storage) {
            'redis' => self::redis($config),
            'apcu' => new APCng(self::string($config->get('laraflow-prometheus.prefix'), 'laraflow_prometheus')),
            'memory' => new InMemory,
            default => throw new InvalidArgumentException('laraflow-prometheus.storage must be redis, apcu, or memory; got ['.(is_scalar($storage) ? (string) $storage : get_debug_type($storage)).'].'),
        };
    }

    private static function redis(Config $config): Redis
    {
        $name = self::string($config->get('laraflow-prometheus.redis.connection'), 'default');
        $connection = $config->get("database.redis.{$name}");

        if (! is_array($connection)) {
            throw new InvalidArgumentException("laraflow-prometheus.redis.connection names [{$name}], which database.redis does not define.");
        }

        Redis::setPrefix(self::string($config->get('laraflow-prometheus.prefix'), 'laraflow_prometheus').':');

        return new Redis(array_filter([
            'host' => $connection['host'] ?? '127.0.0.1',
            'port' => (int) ($connection['port'] ?? 6379),
            'password' => $connection['password'] ?? null,
            'user' => $connection['username'] ?? null,
            'database' => isset($connection['database']) ? (int) $connection['database'] : null,
            'timeout' => (float) $config->get('laraflow-prometheus.redis.timeout', 0.1),
            'read_timeout' => (string) $config->get('laraflow-prometheus.redis.read_timeout', 10),
            'persistent_connections' => (bool) $config->get('laraflow-prometheus.redis.persistent', false),
        ], fn (mixed $value): bool => $value !== null));
    }

    private static function string(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
