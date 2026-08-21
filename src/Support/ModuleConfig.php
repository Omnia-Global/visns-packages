<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * The handful of config reads every module in this package repeats.
 *
 * Two rules are enforced here rather than in each caller:
 *
 *  - nothing reads env() at runtime. env() returns null once an application has
 *    run `config:cache`, which is exactly the state production runs in, so a
 *    value read that way works in development and silently disappears on
 *    deploy. Every env() in this package now lives in the config file, read
 *    once, at config load.
 *
 *  - a "pluggable class" is always allowed to be a class name, an already-built
 *    object, or a plain closure, and is always built through the container so
 *    it can have its own dependencies injected.
 */
class ModuleConfig
{
    /**
     * Read a key from the package config.
     */
    public static function get(string $key, $default = null)
    {
        return config('visns-packages.' . $key, $default);
    }

    /**
     * The user model class for a module, falling back to the package-wide
     * setting. Nothing in this package may hardcode App\Models\User.
     */
    public static function userModel(?string $moduleKey = null): string
    {
        $model = $moduleKey === null
            ? null
            : self::get($moduleKey . '.user_model');

        if (! is_string($model) || $model === '') {
            $model = self::get('user_model', 'App\\Models\\User');
        }

        return $model;
    }

    /**
     * A new query builder on a module's user model.
     */
    public static function userQuery(?string $moduleKey = null)
    {
        $model = self::userModel($moduleKey);

        return $model::query();
    }

    /**
     * Resolve a configured hook into something callable.
     *
     * Accepts a class name (built via the container), an object, or a closure.
     * Returns null for anything empty, so callers can write:
     *
     *     if ($hook = ModuleConfig::callable('auth.user_resolver')) { ... }
     *
     * @param  string|object|callable|null  $value  A literal value, when the
     *                                              caller already has it.
     */
    public static function callable(?string $key, $value = null): ?callable
    {
        $target = $key === null ? $value : (self::get($key) ?? $value);

        if ($target === null || $target === '' || $target === false) {
            return null;
        }

        if (is_string($target)) {
            if (! class_exists($target)) {
                return null;
            }

            $target = app($target);
        }

        return is_callable($target) ? $target : null;
    }

    /**
     * Resolve a list of configured hooks (gates, post-login hooks) into
     * callables, silently dropping anything that cannot be built - a typo in a
     * hook list must not take the login page down.
     *
     * @return array<int, callable>
     */
    public static function callables(string $key): array
    {
        $resolved = [];

        foreach ((array) self::get($key, []) as $entry) {
            $callable = self::callable(null, $entry);

            if ($callable !== null) {
                $resolved[] = $callable;
            }
        }

        return $resolved;
    }

    /**
     * Resolve an interface-backed collaborator: the class named in config, else
     * whatever the container has bound to the interface, else the package's own
     * fallback.
     *
     * @template T
     * @param  class-string<T>  $interface
     * @param  class-string<T>  $fallback
     * @return T
     */
    public static function service(string $configKey, string $interface, string $fallback)
    {
        $configured = self::get($configKey);

        if (is_string($configured) && $configured !== '' && class_exists($configured)) {
            return app($configured);
        }

        if (is_object($configured)) {
            return $configured;
        }

        if (app()->bound($interface)) {
            return app($interface);
        }

        return app($fallback);
    }

    /**
     * A configured message, falling back to the package default shipped in the
     * config file (so an application overriding one key of a `messages` map
     * does not have to restate the rest).
     */
    public static function message(string $moduleKey, string $name, string $default = ''): string
    {
        $value = self::get($moduleKey . '.messages.' . $name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
