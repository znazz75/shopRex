<?php

namespace ShopRex\Core;

/**
 * Minimal service container - not a full DI framework (no autowiring,
 * no reflection-based constructor resolution). Register a factory once
 * with singleton(), resolve it (and cache the instance) with make().
 * This is deliberately small: the app is a starting-point framework, not
 * a place to reproduce Symfony's DependencyInjection component.
 *
 * In plain terms: this is a single shared "box" that holds one instance of
 * each important object (the database connection, the Router, services like
 * Mailer, etc). Instead of every class creating its own copies of these
 * objects (which would be wasteful and make testing/config harder), they all
 * ask this one box for the shared instance by name.
 */
final class Container
{
    /**
     * Closures registered via singleton() - the recipe for building each
     * service, keyed by its id (usually a fully-qualified class name), not
     * yet executed.
     * @var array<string, \Closure>
     */
    private array $bindings = [];

    /**
     * Already-built objects, keyed by the same id as $bindings - once a
     * factory closure runs once, its result is cached here so later make()
     * calls reuse the same instance instead of rebuilding it.
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * Registers how to build a service, without building it yet. The factory
     * closure only runs the first time make() is called for this $id - after
     * that, the same object instance is reused (hence "singleton"). Re-registering
     * an $id clears any previously cached instance so the next make() rebuilds
     * from the new factory.
     */
    public function singleton(string $id, \Closure $factory): void
    {
        $this->bindings[$id] = $factory;
        // Drop any previously built instance for this id, otherwise make()
        // would keep returning the old object built from the old factory.
        unset($this->instances[$id]);
    }

    /** True if something (a factory or an already-built instance) is registered under this id - lets callers check availability before calling make() and risking an exception. */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    /**
     * Resolves a service by id: returns the cached instance if one already
     * exists, otherwise runs the registered factory once, caches the result,
     * and returns it. This is the only way code should obtain shared services
     * like the database connection or Router.
     */
    public function make(string $id): mixed
    {
        // array_key_exists (not isset) because a bound value could legitimately
        // be null - isset() would treat a cached null as "not cached" and
        // rebuild it every time.
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->bindings[$id])) {
            // Fail loudly rather than silently returning null - a missing
            // binding is a programming error (typo'd id, forgot to register
            // it in src/container.php) that should surface immediately.
            throw new \RuntimeException("Nothing bound in the container for \"{$id}\".");
        }
        // Run the factory (passing $this so the factory can itself pull other
        // services out of the container), then cache and return the result.
        return $this->instances[$id] = ($this->bindings[$id])($this);
    }
}
