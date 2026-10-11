<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * Fills a configured URL pattern for one record.
 *
 * Two tokens (4.18.0):
 *  - `{id}`   - the record's key, exactly as before;
 *  - `{slug}` - the record's `slug` attribute, for a host application whose
 *               record pages are addressed by slug (`/customers/{slug}`).
 *
 * `{slug}` FALLS BACK TO THE ID when the record has no slug - an empty value,
 * or a model with no such attribute at all. An id URL always resolves in a host
 * that accepts slugs (they read an all-digit key as an id); a URL with a blank
 * segment never resolves anywhere. So a pattern naming `{slug}` is safe to set
 * on a model that only some rows carry a slug for.
 */
class RecordUrl
{
    public static function fill(?string $pattern, $key, ?string $slug = null): ?string
    {
        if ($pattern === null || $pattern === '' || $key === null || $key === '') {
            return null;
        }

        $slug = is_string($slug) ? trim($slug) : '';

        return strtr($pattern, [
            '{id}' => (string) $key,
            '{slug}' => $slug !== '' ? $slug : (string) $key,
        ]);
    }

    /** Fill a pattern from a loaded record. */
    public static function forRecord(?string $pattern, $record): ?string
    {
        if ($record === null) {
            return null;
        }

        return self::fill($pattern, $record->getKey(), self::slugOf($record));
    }

    /** True when the pattern asks for a slug, i.e. a lookup is worth doing. */
    public static function wantsSlug(?string $pattern): bool
    {
        return is_string($pattern) && str_contains($pattern, '{slug}');
    }

    /** The record's slug, or null when it has none (or no such attribute). */
    public static function slugOf($record): ?string
    {
        if (!is_object($record) || !method_exists($record, 'getAttribute')) {
            return null;
        }

        try {
            $slug = $record->getAttribute('slug');
        } catch (\Throwable $e) {
            // A model running in strict mode (preventAccessingMissingAttributes)
            // throws for an attribute it does not have; that is "no slug".
            return null;
        }

        return is_string($slug) && trim($slug) !== '' ? trim($slug) : null;
    }
}
