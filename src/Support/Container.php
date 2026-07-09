<?php

declare(strict_types=1);

namespace SupportAI\Support;

use Closure;
use RuntimeException;

/**
 * Tiny PSR-11-flavoured service container.
 *
 * Deliberately minimal: string-keyed factories with lazy singletons. Enough to
 * keep wiring in one place (bootstrap) and out of the business logic, without
 * pulling in a full DI framework.
 */
final class Container
{
    /** @var array<string,Closure> */
    private array $factories = [];

    /** @var array<string,mixed> */
    private array $instances = [];

    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new RuntimeException("Service not registered: {$id}");
        }
        return $this->instances[$id] = ($this->factories[$id])($this);
    }
}
