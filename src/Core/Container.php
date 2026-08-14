<?php

namespace ShopRex\Core;

/**
 * Minimal service container - not a full DI framework (no autowiring,
 * no reflection-based constructor resolution). Register a factory once
 * with singleton(), resolve it (and cache the instance) with make().
 * This is deliberately small: the app is a starting-point framework, not
 * a place to reproduce Symfony's DependencyInjection component.
 */
final class Container
{
    /** @var array<string, \Closure> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function singleton(string $id, \Closure $factory): void
    {
        $this->bindings[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    public function make(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->bindings[$id])) {
            throw new \RuntimeException("Nothing bound in the container for \"{$id}\".");
        }
        return $this->instances[$id] = ($this->bindings[$id])($this);
    }
}
