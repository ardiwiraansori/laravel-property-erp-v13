<?php

namespace App\Support\MasterData;

use Illuminate\Database\Eloquent\Builder;

/** Consistent, literal-match filters for the directory and its CSV export. */
final class PropertyDevelopmentDirectoryFilter
{
    // Public input is restricted to fixed keys; user data never forms SQL identifiers.
    public const SORTS = [
        'name_asc', 'name_desc', 'code_asc', 'code_desc',
        'created_desc', 'created_asc',
    ];

    /** Stable ordering for both paginated results and full filtered CSV. */
    public static function order(Builder $query, string $sort): Builder
    {
        [$column, $direction] = match ($sort) {
            'name_desc' => ['name', 'desc'],
            'code_asc' => ['code', 'asc'],
            'code_desc' => ['code', 'desc'],
            'created_desc' => ['created_at', 'desc'],
            'created_asc' => ['created_at', 'asc'],
            default => ['name', 'asc'],
        };

        // A deterministic tie breaker is essential across page boundaries.
        return $query->orderBy($column, $direction)->orderBy('id');
    }

    public static function apply(Builder $query, array $params): Builder
    {
        $search = trim((string) ($params['search'] ?? ''));
        if ($search !== '') {
            // Literal SQL wildcard matching for both SQLite tests and MariaDB.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
            $pattern = '%'.$escaped.'%';
            $query->where(static function (Builder $nested) use ($pattern): void {
                $nested->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        if (($params['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $params['status'] === 'active');
        }

        return $query;
    }
}
