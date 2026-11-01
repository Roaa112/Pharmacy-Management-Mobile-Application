<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Human-readable document numbers (PO-000042) derived from the row id, so they are unique without
 * a counter table: the row is created with a temporary reference, then renamed from its id.
 */
final class DocumentNumber
{
    /**
     * @template T of Model
     * @param  class-string<T>  $model
     * @return T
     */
    public static function create(string $model, string $prefix, array $attributes): Model
    {
        $row = $model::create($attributes + ['reference' => 'TMP-'.Str::random(20)]);
        $row->forceFill(['reference' => sprintf('%s-%06d', $prefix, $row->id)])->save();

        return $row;
    }
}
