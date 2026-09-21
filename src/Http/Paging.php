<?php

namespace Dashed\DashedEcommerceReseller\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cursor op id: stabiel, ook als er tijdens het bladeren producten
 * bijkomen, en zonder OFFSET op grote tabellen.
 */
final class Paging
{
    public static function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . self::max()],
            'cursor' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public static function perPage(Request $request): int
    {
        return (int) $request->query('per_page', (string) config('dashed-ecommerce-reseller.api.per_page_default', 100));
    }

    /**
     * @return array{0: Collection, 1: ?int}
     */
    public static function page(Builder $query, Request $request, string $column): array
    {
        $perPage = self::perPage($request);

        $rows = $query
            ->where($column, '>', (int) $request->query('cursor', '0'))
            ->reorder()
            ->orderBy($column)
            ->limit($perPage + 1)
            ->get();

        $next = $rows->count() > $perPage ? (int) $rows[$perPage - 1]->getKey() : null;

        return [$rows->take($perPage)->values(), $next];
    }

    public static function meta(Request $request, ?int $nextCursor): array
    {
        return [
            'next_cursor' => $nextCursor,
            'per_page' => self::perPage($request),
            'server_time' => now()->toIso8601String(),
        ];
    }

    private static function max(): int
    {
        return (int) config('dashed-ecommerce-reseller.api.per_page_max', 250);
    }
}
