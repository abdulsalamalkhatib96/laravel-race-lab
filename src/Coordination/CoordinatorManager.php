<?php

namespace RaceLab\LaravelRaceLab\Coordination;

use Illuminate\Contracts\Container\Container;
use RaceLab\LaravelRaceLab\Contracts\Coordinator;
use RaceLab\LaravelRaceLab\Exceptions\CoordinatorException;

final class CoordinatorManager
{
    /** @var array<string,Coordinator> */
    private array $instances = [];

    public function __construct(
        private Container $container,
        private FileCoordinator $file,
    ) {
    }

    public function driver(string $name): Coordinator
    {
        if (isset($this->instances[$name])) return $this->instances[$name];

        return $this->instances[$name] = match ($name) {
            'file' => $this->file,
            'redis' => $this->makeRedis(),
            default => throw new CoordinatorException("Unsupported Race Lab coordinator [{$name}]."),
        };
    }

    private function makeRedis(): Coordinator
    {
        if (! $this->container->bound('redis')) {
            throw new CoordinatorException('Redis coordinator requested, but Laravel Redis is not bound in the container.');
        }

        $config = $this->container->make('config');
        return new RedisCoordinator(
            redisManager: $this->container->make('redis'),
            connectionName: (string) $config->get('race-lab.redis.connection', 'default'),
            prefix: (string) $config->get('race-lab.redis.prefix', 'race-lab:'),
            pollIntervalMicroseconds: (int) $config->get('race-lab.poll_interval_microseconds', 10_000),
            ttlSeconds: (int) $config->get('race-lab.redis.ttl_seconds', 600),
        );
    }
}
