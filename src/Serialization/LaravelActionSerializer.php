<?php

namespace RaceLab\LaravelRaceLab\Serialization;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use RaceLab\LaravelRaceLab\Contracts\ActionSerializer;
use RaceLab\LaravelRaceLab\Exceptions\RaceLabException;

final class LaravelActionSerializer implements ActionSerializer
{
    public function __construct(private bool $strictCaptures = false)
    {
    }

    public function serialize(Closure $closure): string
    {
        $closure = $this->withoutBoundObject($closure);

        if ($this->strictCaptures) {
            $this->assertSafeCaptures($closure);
        }

        try {
            return base64_encode(serialize(new SerializableClosure($closure)));
        } catch (\Throwable $e) {
            throw new RaceLabException('Unable to serialize race worker closure: '.$e->getMessage(), previous: $e);
        }
    }

    public function unserialize(string $payload): Closure
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new RaceLabException('Invalid base64 worker action payload.');
        }

        try {
            $wrapped = unserialize($decoded, ['allowed_classes' => true]);
        } catch (\Throwable $e) {
            throw new RaceLabException('Unable to unserialize race worker closure: '.$e->getMessage(), previous: $e);
        }

        if (! $wrapped instanceof SerializableClosure) {
            throw new RaceLabException('Worker action payload did not contain a Laravel SerializableClosure.');
        }

        return $wrapped->getClosure();
    }

    private function withoutBoundObject(Closure $closure): Closure
    {
        $reflection = new \ReflectionFunction($closure);
        if ($reflection->getClosureThis() === null) return $closure;

        $scope = $reflection->getClosureScopeClass()?->getName() ?? 'static';
        $unbound = @Closure::bind($closure, null, $scope);
        if (! $unbound instanceof Closure) {
            throw new RaceLabException(
                'Race worker closures cannot depend on $this. Capture scalar IDs/data and resolve application services inside the worker.'
            );
        }

        return $unbound;
    }

    private function assertSafeCaptures(Closure $closure): void
    {
        $reflection = new \ReflectionFunction($closure);
        foreach ($reflection->getStaticVariables() as $name => $value) {
            if (! $this->isSafeValue($value)) {
                $type = get_debug_type($value);
                throw new RaceLabException(
                    "Worker closure capture [{$name}] has unsafe type [{$type}]. Capture scalar IDs/DTO data and resolve services/models inside the worker instead."
                );
            }
        }
    }

    private function isSafeValue(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) return true;
        if (! is_array($value)) return false;

        foreach ($value as $key => $item) {
            if (! is_int($key) && ! is_string($key)) return false;
            if (! $this->isSafeValue($item)) return false;
        }

        return true;
    }
}
