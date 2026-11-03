<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\City;
use App\Models\Country;
use App\Modules\DataTransfer\Import\GroupPlan;
use App\Modules\DataTransfer\Import\ImportContext;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Cities of the store's country. Delivery zones and rates stay on the Delivery screens. */
class CityEntity extends KeyedModelEntity
{
    public function key(): string { return 'cities'; }

    public function label(): string { return 'Cities'; }

    public function description(): string { return 'Cities customers can deliver to (zones and rates are managed on the Delivery screens).'; }

    public function importPermission(): ?string { return 'locations.import'; }

    public function exportPermission(): ?string { return 'locations.export'; }

    protected function model(): string { return City::class; }

    protected function keyColumn(): string { return 'id'; }

    public function columns(): array
    {
        return [
            'id' => Column::make('Id', 'Empty for a new city.', false, 'Existing id', ''),
            'name' => Column::make('Name', 'The name addresses are matched by.', true, 'Text, max 255', 'Giza'),
            'name_ar' => Column::make('Name (Arabic)', '', false, 'Text', 'الجيزة'),
            'name_en' => Column::make('Name (English)', '', false, 'Text', 'Giza'),
            'is_active' => Column::make('Active', '', false, '1 / 0', '1'),
            'areas_count' => Column::exportOnly('Areas'),
        ];
    }

    protected function rules(?Model $existing): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'name' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'in:0,1,true,false,yes,no'],
        ];
    }

    protected function afterValidate(GroupPlan $plan, ImportContext $context, array $data, ?Model $existing): void
    {
        $name = $data['name'] ?? null;
        if ($name !== null && City::where('name', $name)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
            $plan->error($plan->firstRow(), 'name', "Another city is already named \"{$name}\".", $name);
        }
        if ($other = $context->claim('city-name', $name, $plan->firstRow())) {
            $plan->error($plan->firstRow(), 'name', "Duplicate city name in the file (also on row {$other}).", (string) $name);
        }
    }

    protected function attributes(array $data, ?Model $existing, GroupPlan $plan): array
    {
        $attributes = self::filled(['name' => $data['name'] ?? null, 'name_ar' => $data['name_ar'] ?? null, 'name_en' => $data['name_en'] ?? null], $existing);
        if (($data['is_active'] ?? null) !== null || !$existing) {
            $attributes['is_active'] = self::bool($data['is_active'] ?? null);
        }
        if (!$existing) {
            $attributes['country_id'] = Country::default()?->id;
        }

        return $attributes;
    }

    public function exportQuery(array $filters): Builder
    {
        return City::query()->withCount('areas');
    }

    public function exportRow(Model $city): array
    {
        return ['id' => $city->id, 'name' => $city->name, 'name_ar' => $city->name_ar, 'name_en' => $city->name_en,
            'is_active' => $city->is_active ? 1 : 0, 'areas_count' => $city->areas_count];
    }

    public function examples(): array
    {
        return [['name' => 'Giza', 'name_ar' => 'الجيزة', 'name_en' => 'Giza', 'is_active' => '1']];
    }
}
