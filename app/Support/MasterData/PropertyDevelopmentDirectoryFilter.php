<?php

namespace App\Support\MasterData;

use Illuminate\Database\Eloquent\Builder;

/** Consistent, literal-match filters for the directory and its CSV export. */
final class PropertyDevelopmentDirectoryFilter
{
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
