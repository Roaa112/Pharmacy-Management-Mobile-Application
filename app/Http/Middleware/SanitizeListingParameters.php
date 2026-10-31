<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Listing endpoints read limit/offset/sort/search/filters straight from the query string.
 * Values that would make the query fail (negative or non-numeric paging, an unknown sort
 * direction, arrays where text is expected) are dropped here, so each endpoint falls back
 * to its own default instead of answering 500. Valid values pass through unchanged.
 */
class SanitizeListingParameters
{
    public function handle(Request $request, Closure $next): Response
    {
        $query = $request->query;

        if ($query->has('limit') && !$this->isWholeNumber($query->all()['limit'] ?? null, 1)) {
            $query->remove('limit');
        }

        if ($query->has('offset') && !$this->isWholeNumber($query->all()['offset'] ?? null, 0)) {
            $query->remove('offset');
        }

        if ($query->has('sort')) {
            $sort = $query->all()['sort'];
            if (!is_string($sort) || !in_array(strtolower($sort), ['asc', 'desc'], true)) {
                $query->remove('sort');
            }
        }

        foreach (['search', 'sortBy'] as $key) {
            if ($query->has($key) && !is_string($query->all()[$key])) {
                $query->remove($key);
            }
        }

        if ($query->has('filters') && !is_array($query->all()['filters'])) {
            $query->remove('filters');
        }

        return $next($request);
    }

    private function isWholeNumber(mixed $value, int $min): bool
    {
        return is_string($value) && ctype_digit($value) && (int) $value >= $min;
    }
}
