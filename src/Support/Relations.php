<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * Filters a configured relation list down to the relations the model actually
 * has.
 *
 * The shipped defaults name relations that only exist in the applications this
 * package grew out of ('company_contact', 'roles.permissions'). Eager-loading a
 * relation a model does not define throws a BadMethodCallException, and both
 * callers wrap their work in a catch-all that turns any exception into a
 * generic "invalid token" / "an error occurred" - so a mismatched relation name
 * would surface as a security-shaped error message with nothing in it to point
 * at the real cause. Skipping the unknown relation instead keeps the failure
 * where it belongs: a payload missing a block, not a login that mysteriously
 * refuses to work.
 *
 * Only the first segment of a nested path is checked; the rest is the related
 * model's business.
 */
class Relations
{
    /**
     * @param  array<int, string>  $relations
     * @return array<int, string>
     */
    public static function supported(object $model, array $relations): array
    {
        return array_values(
            array_filter($relations, function ($relation) use ($model) {
                if (! is_string($relation) || $relation === '') {
                    return false;
                }

                $root = explode('.', $relation)[0];

                return method_exists($model, $root)
                    || method_exists($model, \Illuminate\Support\Str::camel($root));
            })
        );
    }

    /**
     * Eager-load whatever of the list the model understands.
     */
    public static function load(object $model, array $relations): object
    {
        $supported = self::supported($model, $relations);

        if ($supported !== [] && method_exists($model, 'load')) {
            $model->load($supported);
        }

        return $model;
    }
}
