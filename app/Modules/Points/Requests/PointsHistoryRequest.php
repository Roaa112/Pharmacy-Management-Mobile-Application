<?php

namespace App\Modules\Points\Requests;

use App\Models\LoyaltyLedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters of a points history (customer API and dashboard): type, from / to dates, page size. */
class PointsHistoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(LoyaltyLedgerEntry::TYPES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->validated('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($this->validated('from'), fn ($q, $from) => $q->where('occurred_at', '>=', \Illuminate\Support\Carbon::parse($from)->startOfDay()))
            ->when($this->validated('to'), fn ($q, $to) => $q->where('occurred_at', '<=', \Illuminate\Support\Carbon::parse($to)->endOfDay()));
    }

    public function perPage(int $default = 20): int
    {
        return (int) ($this->validated('per_page') ?? $default);
    }
}
