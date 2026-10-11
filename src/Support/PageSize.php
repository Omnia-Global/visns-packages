<?php

namespace Visnsstudio\VisnsPackages\Support;

use Illuminate\Http\Request;

/**
 * The one reading of a list endpoint's page size.
 *
 * Every grid in a host application posts `take` (some screens `per_page`), and
 * until 4.18.0 the controllers handed that straight to `paginate()` - so
 * `take=100000` was the whole table in one response. That is a denial of
 * service against the database and a bulk export of anything the caller can
 * list, through an endpoint that was only ever meant to draw a page.
 *
 * The rules:
 *  - absent (or blank, or not a positive whole number) => the caller's default,
 *    exactly what it was before this class existed;
 *  - larger than the ceiling => the ceiling. Capped, never refused: a grid that
 *    asks for 500 still draws, it just draws 200.
 *
 * The ceiling is `visns-packages.max_page_size` (200).
 */
class PageSize
{
    public const DEFAULT = 10;

    public const CEILING = 200;

    /**
     * The page size off the request.
     *
     * @param  string|string[]  $keys  the first of these the request carries wins
     */
    public static function from(Request $request, int $default = self::DEFAULT, string|array $keys = 'take'): int
    {
        foreach ((array) $keys as $key) {
            if ($request->filled($key)) {
                return self::clamp($request->input($key), $default);
            }
        }

        return self::clamp(null, $default);
    }

    /**
     * Clamp a raw value: not a positive whole number => default; over the
     * ceiling => the ceiling. The default itself is capped too, so a caller
     * cannot get round the ceiling by passing a large default.
     */
    public static function clamp($value, int $default = self::DEFAULT): int
    {
        $ceiling = self::ceiling();
        $size = filter_var($value, FILTER_VALIDATE_INT);

        if ($size === false || $size < 1) {
            $size = $default;
        }

        return max(1, min($size, $ceiling));
    }

    /** The configured ceiling, never below 1. */
    public static function ceiling(): int
    {
        $ceiling = (int) config('visns-packages.max_page_size', self::CEILING);

        return $ceiling >= 1 ? $ceiling : self::CEILING;
    }
}
