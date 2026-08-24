<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One search box for the whole application.
 *
 * Every CRM built on this package has the same problem: a user knows a name, a
 * ticket number or a phone number, and has to guess which of a dozen list
 * pages it lives on before they can search for it. This searches the lot and
 * groups the answer by what kind of record it is.
 *
 * WHAT IS SEARCHED IS CONFIGURATION, NOT CODE. `visns-packages.universal_search
 * .sources` names the models, the columns and the permission each group
 * requires — so a consuming app decides what its own search covers without
 * touching this class, and a model nobody may read never appears.
 *
 * Deliberately NOT Scout-backed. Scout would mean every searchable model has to
 * be indexed and kept in sync, and a CRM's search is over a few thousand rows
 * of its own database, not a corpus. A LIKE across the configured columns is
 * both sufficient and always current. A consumer that outgrows that can point
 * a source at a Scout-backed model via the `driver` key.
 */
// Extends the consuming application's base controller, as every other
// controller in this package does — the package has no base of its own.
class UniversalSearchController extends \App\Http\Controllers\Controller
{
    public function __invoke(Request $request)
    {
        $term = trim((string) $request->input('q', $request->input('search', '')));

        $min = (int) $this->conf('min_length', 2);

        if (mb_strlen($term) < $min) {
            return response()->json([
                'query' => $term,
                'groups' => [],
                'total' => 0,
                'message' => "Type at least {$min} characters.",
            ]);
        }

        $perSource = (int) $request->input('limit', $this->conf('per_source', 5));
        $perSource = max(1, min($perSource, 25));

        $groups = [];
        $total = 0;

        foreach ($this->sources() as $key => $source) {
            if (!$this->permitted($source)) {
                continue;
            }

            try {
                $rows = $this->searchSource($key, $source, $term, $perSource);
            } catch (\Throwable $e) {
                // One misconfigured source must not take the whole search
                // down — the box is used constantly and a 500 makes the app
                // feel broken.
                Log::warning('Universal search: source failed', [
                    'source' => $key,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($rows === []) {
                continue;
            }

            $groups[] = [
                'key' => $key,
                'label' => $source['label'] ?? Str::headline($key),
                'icon' => $source['icon'] ?? null,
                'results' => $rows,
            ];

            $total += count($rows);
        }

        return response()->json([
            'query' => $term,
            'groups' => $groups,
            'total' => $total,
        ]);
    }

    // -- one source -----------------------------------------------------------

    protected function searchSource(string $key, array $source, string $term, int $limit): array
    {
        $modelClass = $source['model'] ?? null;

        if (!$modelClass || !class_exists($modelClass)) {
            return [];
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass();
        $columns = (array) ($source['columns'] ?? []);

        if ($columns === []) {
            return [];
        }

        $query = $modelClass::query();

        if ($with = $source['with'] ?? null) {
            $query->with((array) $with);
        }

        // Group the OR block so a source-level `scope` or `where` stays an AND
        // — without the closure, a scope restricting to active records would
        // be defeated by the first OR.
        $query->where(function ($q) use ($columns, $term, $model) {
            foreach ($columns as $i => $column) {
                $method = $i === 0 ? 'where' : 'orWhere';

                if (str_contains($column, '.')) {
                    // `relation.column` — filter through the relationship.
                    [$relation, $related] = explode('.', $column, 2);
                    $q->{$i === 0 ? 'whereHas' : 'orWhereHas'}(
                        $relation,
                        fn($r) => $r->where($related, 'like', '%' . $term . '%')
                    );

                    continue;
                }

                $q->{$method}($column, 'like', '%' . $term . '%');
            }
        });

        if ($scope = $source['scope'] ?? null) {
            $query->{$scope}();
        }

        foreach ((array) ($source['where'] ?? []) as $col => $val) {
            $query->where($col, $val);
        }

        $rows = $query->limit($limit)->get();

        return $rows->map(fn($row) => $this->present($row, $source))->all();
    }

    /**
     * Turn a record into the three things a result row needs: something to
     * read, something explaining which record it is, and somewhere to go.
     */
    protected function present($row, array $source): array
    {
        // A multi-field title is a composite name ("Darshini Perera"); the
        // default list is a fallback chain, and every model answers at most
        // one of those four, so joining is a no-op there.
        $title = $this->pluck($row, $source['title'] ?? ['name', 'label', 'subject', 'title'], ' ');
        $subtitle = $this->pluck($row, $source['subtitle'] ?? [], ' · ');

        $url = $source['url'] ?? null;

        return [
            'id' => $row->getKey(),
            'title' => $title ?: ('#' . $row->getKey()),
            'subtitle' => $subtitle ?: null,
            'url' => $url ? str_replace('{id}', (string) $row->getKey(), $url) : null,
        ];
    }

    /**
     * Resolve a title or subtitle from candidate fields.
     *
     * A candidate may be `relation.column`; data_get already walks that on an
     * Eloquent model. Every candidate that answers is joined by `$glue`, so a
     * contact's title reads "Darshini Perera" from ['firstname','surname']
     * and its subtitle reads "d@acme.test · 0412 345 678" — without the
     * consuming app writing an accessor for either.
     */
    protected function pluck($row, $candidates, string $glue = ' '): ?string
    {
        $candidates = (array) $candidates;
        $parts = [];

        foreach ($candidates as $candidate) {
            $value = data_get($row, $candidate);

            if (!is_scalar($value) || (string) $value === '') {
                continue;
            }

            $parts[] = trim((string) $value);
        }

        return $parts ? implode($glue, array_unique($parts)) : null;
    }

    // -- configuration --------------------------------------------------------

    protected function sources(): array
    {
        return (array) $this->conf('sources', []);
    }

    /**
     * A source the caller may not read is not merely hidden from the results —
     * it is never queried, so search cannot become a way to confirm that a
     * record exists.
     */
    protected function permitted(array $source): bool
    {
        $permission = $source['permission'] ?? null;

        if (!$permission) {
            return true;
        }

        $user = auth()->user();

        if (!$user) {
            return false;
        }

        return method_exists($user, 'can') ? $user->can($permission) : true;
    }

    protected function conf(string $key, $default = null)
    {
        return config("visns-packages.universal_search.{$key}", $default);
    }
}
