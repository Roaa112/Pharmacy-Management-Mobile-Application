<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\Area;
use App\Modules\DataTransfer\Import\GroupPlan;
use App\Modules\DataTransfer\Import\ImportContext;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Areas inside cities (city by id). */
class AreaEntity extends KeyedModelEntity
{
    public function key(): string { return 'areas'; }

    public function label(): string { return 'Areas'; }

    public function description(): string { return 'Areas (districts) of each city.'; }

    public function importPermission(): ?string { return 'locations.import'; }

    public function exportPermission(): ?string { return 'locations.export'; }

    protected function model(): string { return Area::class; }

    protected function keyColumn(): string { return 'id'; }

    public function columns(): array
    {
        return [
            'id' => Column::make('Id', 'Empty for a new area.', false, 'Existing id', ''),
            'city_id' => Column::make('City id', 'See the cities export.', true, 'Existing city id', '1'),
            'name' => Column::make('Name', '', true, 'Text, max 255', 'Dokki'),
            'name_ar' => Column::make('Name (Arabic)', '', false, 'Text', 'الدقي'),
            'name_en' => Column::make('Name (English)', '', false, 'Text', 'Dokki'),
            'is_active' => Column::make('Active', '', false, '1 / 0', '1'),
            'city_name' => Column::exportOnly('City'),
        ];
    }

    protected function rules(?Model $existing): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'city_id' => [$existing ? 'nullable' : 'required', 'integer', Rule::exists('cities', 'id')],
            'name' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'in:0,1,true,false,yes,no'],
        ];
    }

    protected function afterValidate(GroupPlan $plan, ImportContext $context, array $data, ?Model $existing): void
    {
        $cityId = $data['city_id'] ?? $existing?->city_id;
        $name = $data['name'] ?? null;
        if ($name !== null && Area::where('city_id', $cityId)->where('name', $name)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
            $plan->error($plan->firstRow(), 'name', "The city already has an area named \"{$name}\".", $name);
        }
    }

    protected function attributes(array $data, ?Model $existing, GroupPlan $plan): array
    {
        $attributes = self::filled(['city_id' => $data['city_id'] ?? null, 'name' => $data['name'] ?? null, 'name_ar' => $data['name_ar'] ?? null, 'name_en' => $data['name_en'] ?? null], $existing);
        if (($data['is_active'] ?? null) !== null || !$existing) {
            $attributes['is_active'] = self::bool($data['is_active'] ?? null);
        }

        return $attributes;
    }

    public function filters(): array
    {
        return ['city_id' => ['City', 'select', \App\Models\City::orderBy('name')->pluck('name', 'id')->all()]];
    }

    public function exportQuery(array $filters): Builder
    {
        return Area::query()->with('city:id,name')->when($filters['city_id'] ?? null, fn ($q, $id) => $q->where('city_id', $id));
    }

    public function exportRow(Model $area): array
    {
        return ['id' => $area->id, 'city_id' => $area->city_id, 'name' => $area->name, 'name_ar' => $area->name_ar, 'name_en' => $area->name_en,
            'is_active' => $area->is_active ? 1 : 0, 'city_name' => $area->city?->name];
    }

    public function examples(): array
    {
        return [['city_id' => '1', 'name' => 'Dokki', 'name_ar' => 'الدقي', 'name_en' => 'Dokki', 'is_active' => '1']];
    }
}
