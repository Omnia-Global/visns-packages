<?php

namespace Visnsstudio\VisnsPackages\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Decides whether a string that arrived in a request names a real Eloquent
 * relation on a model, WITHOUT calling the method it names.
 *
 * Eloquent resolves a relation name (`whereHas('x')`, `with('x')`,
 * `load('x')`, `$model->x()`) by calling the method of that name. A request
 * that can choose the name can therefore call any public, zero-argument method
 * a model has - or anything its `__call` forwards to the query builder. Every
 * place the package turns request text into a relation or method name asks this
 * class first.
 *
 * A name is a relation when:
 *   1. the model lists it in one of its own allowlists - `loadableRelations()`,
 *      `getSortableRelationshipFields()`, or an optional `$filterableRelations`
 *      property - and the method exists; or
 *   2. by reflection it is a public, non-static method with no required
 *      parameters, declared in user-land code (not Laravel's, and not a method
 *      the base Eloquent model has), AND either its declared return type is a
 *      Relation, or (with no declared return type) its source body returns the
 *      result of one of Eloquent's relation builders.
 *
 * A method with a declared return type that is NOT a Relation is never a
 * relation, whatever its body says. Answers are cached per class and name.
 */
final class RelationGuard
{
    /** The Eloquent relation builders a relation method's body calls. */
    public const BUILDERS = [
        'belongsTo', 'hasOne', 'hasMany', 'belongsToMany',
        'morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany',
        'hasOneThrough', 'hasManyThrough',
    ];

    /** Path segment pattern shared by relation names and JSON path segments. */
    public const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array<string, bool> */
    private static array $cache = [];

    /** @var array<string, array<int, string>> */
    private static array $allowlists = [];

    public static function isRelation(Model|string $model, mixed $name): bool
    {
        if (!is_string($name) || !preg_match(self::IDENTIFIER, $name)) {
            return false;
        }

        $class = is_string($model) ? $model : get_class($model);
        $key = $class . '::' . $name;

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $instance = is_string($model) ? (class_exists($model) ? new $model() : null) : $model;
        if (!$instance instanceof Model) {
            return self::$cache[$key] = false;
        }

        return self::$cache[$key] = self::decide($instance, $class, $name);
    }

    /**
     * A dotted path (`customer.sites`): every segment must be a relation of
     * the model the previous segment leads to. The next model is read from a
     * relation instance only AFTER its name has passed the check above.
     */
    public static function isRelationPath(Model $model, mixed $path): bool
    {
        return self::relatedModelForPath($model, $path) !== null;
    }

    /** The model at the end of a relation path, or null when it is not one. */
    public static function relatedModelForPath(Model $model, mixed $path): ?Model
    {
        if (!is_string($path) || $path === '') {
            return null;
        }

        $current = $model;
        foreach (explode('.', $path) as $segment) {
            if (!self::isRelation($current, $segment)) {
                return null;
            }

            try {
                $relation = $current->{$segment}();
            } catch (\Throwable $e) {
                return null;
            }

            if (!$relation instanceof Relation) {
                return null;
            }

            $current = $relation->getRelated();
        }

        return $current;
    }

    /** Forget every cached answer (tests, long-running workers after a deploy). */
    public static function flush(): void
    {
        self::$cache = [];
        self::$allowlists = [];
    }

    private static function decide(Model $model, string $class, string $name): bool
    {
        // The base model's own methods are never relations: delete, save,
        // replicate, push, touch, refresh, newQuery ...
        if (method_exists(Model::class, $name)) {
            return false;
        }

        if (!method_exists($model, $name)) {
            // A relation registered with resolveRelationUsing() has no method.
            try {
                return $model->relationResolver($class, $name) !== null;
            } catch (\Throwable $e) {
                return false;
            }
        }

        if (in_array($name, self::allowlist($model, $class), true)) {
            return true;
        }

        try {
            $method = new \ReflectionMethod($model, $name);
        } catch (\ReflectionException $e) {
            return false;
        }

        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        if (self::isFrameworkCode($method)) {
            return false;
        }

        $type = $method->getReturnType();
        if ($type !== null) {
            return self::typeIsRelation($type);
        }

        return self::bodyBuildsRelation($method);
    }

    /** @return array<int, string> */
    private static function allowlist(Model $model, string $class): array
    {
        if (isset(self::$allowlists[$class])) {
            return self::$allowlists[$class];
        }

        $names = [];

        foreach (['loadableRelations', 'getSortableRelationshipFields'] as $source) {
            if (!method_exists($model, $source)) {
                continue;
            }
            try {
                $listed = $model->{$source}();
            } catch (\Throwable $e) {
                continue;
            }
            foreach ((array) $listed as $key => $entry) {
                // `with()` style lists may carry closures keyed by name.
                $entry = is_string($key) ? $key : $entry;
                if (is_string($entry)) {
                    $names[] = self::firstSegment($entry);
                }
            }
        }

        try {
            $property = new \ReflectionProperty($model, 'filterableRelations');
            foreach ((array) $property->getValue($model) as $entry) {
                if (is_string($entry)) {
                    $names[] = self::firstSegment($entry);
                }
            }
        } catch (\ReflectionException $e) {
            // Optional property.
        }

        return self::$allowlists[$class] = array_values(array_unique($names));
    }

    private static function firstSegment(string $entry): string
    {
        $entry = explode(':', $entry, 2)[0];

        return explode('.', $entry, 2)[0];
    }

    private static function isFrameworkCode(\ReflectionMethod $method): bool
    {
        if (str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
            return true;
        }

        // A method from a trait reports the USING class as its declaring
        // class, so a framework trait (SoftDeletes, HasFactory, Notifiable...)
        // is recognised by where its code lives.
        $file = str_replace('\\', '/', (string) $method->getFileName());

        return str_contains($file, '/Illuminate/') || str_contains($file, '/laravel/framework/');
    }

    private static function typeIsRelation(\ReflectionType $type): bool
    {
        if ($type instanceof \ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return false;
            }
            $name = $type->getName();

            return class_exists($name) && is_a($name, Relation::class, true);
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof \ReflectionNamedType && $member->getName() === 'null') {
                    continue;
                }
                if (!self::typeIsRelation($member)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private static function bodyBuildsRelation(\ReflectionMethod $method): bool
    {
        $file = $method->getFileName();
        if (!$file || !is_readable($file)) {
            return false;
        }

        $lines = @file($file);
        if ($lines === false) {
            return false;
        }

        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        // Comments must not count as code.
        $body = preg_replace('~/\*.*?\*/~s', '', $body);
        $body = preg_replace('~(^|[^:])//[^\n]*~', '$1', $body);
        $body = preg_replace('~#[^\n\[]*~', '', $body);

        $builders = implode('|', self::BUILDERS);

        return (bool) preg_match('/\$this\s*->\s*(?:' . $builders . ')\s*\(/', $body)
            && (bool) preg_match('/\breturn\b/', $body);
    }
}
