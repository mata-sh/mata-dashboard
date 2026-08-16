<?php

declare(strict_types=1);

namespace Mata\Http\Security\RateLimiting;

use Mata\Config\Dto\RateLimitingConfig;
use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\Storage\ApcuStorage;
use MataSh\RateLimiter\Storage\FileStorage;
use MataSh\RateLimiter\Storage\RedisStorage;
use MataSh\RateLimiter\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use Redis;
use RuntimeException;
use Throwable;

final class RequestRateLimiterFactory
{
    public static function create(
        RateLimitingConfig $config,
        LoggerInterface $logger,
    ): RequestRateLimiter {
        if (!$config->enabled) {
            $logger->info('Rate limiting disabled');

            return new RequestRateLimiter(null, $config, $logger);
        }

        try {
            $storage = self::createStorage($config, $logger);
            $rateLimiter = new RateLimiter($storage, $logger);
            $logger->info('Rate limiting initialized', ['storage' => $storage->getName()]);

            return new RequestRateLimiter($rateLimiter, $config, $logger);
        } catch (Throwable $e) {
            $logger->critical('Failed to initialize rate limiter; all protected requests will be denied', [
                'storage' => $config->storageType,
                'exception' => $e,
            ]);

            return new RequestRateLimiter(null, $config, $logger);
        }
    }

    private static function createStorage(
        RateLimitingConfig $config,
        LoggerInterface $logger,
    ): StorageInterface {
        return match ($config->storageType) {
            'file' => new FileStorage($config->filePath),
            'apcu' => new ApcuStorage(),
            'redis' => new RedisStorage(self::connectRedis($config, $logger)),
            default => throw new RuntimeException('Unsupported rate-limit storage: ' . $config->storageType),
        };
    }

    private static function connectRedis(RateLimitingConfig $config, LoggerInterface $logger): Redis
    {
        $redis = new Redis();
        if (!$redis->connect($config->redisHost ?? '127.0.0.1', $config->redisPort, 2.5)) {
            throw new RuntimeException('Failed to connect to Redis');
        }
        if ($config->redisPassword !== null && $config->redisPassword !== '' && !$redis->auth($config->redisPassword)) {
            throw new RuntimeException('Failed to authenticate to Redis');
        }
        if (!$redis->select($config->redisDatabase)) {
            throw new RuntimeException('Failed to select Redis database');
        }
        $logger->info('Rate limiter connected to Redis', [
            'host' => $config->redisHost,
            'port' => $config->redisPort,
        ]);

        return $redis;
    }
}
