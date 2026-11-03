<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\Brand;
use App\Modules\DataTransfer\Import\GroupPlan;
use App\Modules\DataTransfer\Import\ImportContext;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BrandEntity extends KeyedModelEntity
{
    public function key(): string { return 'brands'; }

    public function label(): string { return 'Brands'; }

    public function description(): string { return 'Product brands.'; }

    public function importPermission(): ?string { return 'brands.import'; }

    public function exportPermission(): ?string { return 'brands.export'; }

    protected function model(): string { return Brand::class; }

    protected function keyColumn(): string { return 'id'; }

    protected function imageColumns(): array { return ['image_url' => 'brands']; }

    public function columns(): array
    {
        return [
            'id' => Column::make('Id', 'Empty for a new brand; the id of the brand to update.', false, 'Existing id', ''),
            'name_en' => Column::make('Name (English)', '', true, 'Text, max 255', 'Pharma Co'),
            'name_ar' => Column::make('Name (Arabic)', '', true, 'Text, max 255', 'فارما'),
            'image_url' => Column::make('Logo', 'Required for a new brand: http(s) URL or existing path (brands/x.png).', true, 'URL or path', 'https://example.com/logo.png'),
            'products_count' => Column::exportOnly('Products'),
            'created_at' => Column::exportOnly('Created at'),
        ];
    }

    protected function rules(?Model $existing): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'name_en' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'name_ar' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function afterValidate(GroupPlan $plan, ImportContext $context, array $data, ?Model $existing): void
    {
        if (!$existing && !empty($data['name_en']) && Brand::where('name_en', $data['name_en'])->exists()) {
            $plan->error($plan->firstRow(), 'name_en', "A brand named \"{$data['name_en']}\" already exists; put its id to update it.", $data['name_en']);
        }
    }

    protected function attributes(array $data, ?Model $existing, GroupPlan $plan): array
    {
        return self::filled(['name_en' => $data['name_en'] ?? null, 'name_ar' => $data['name_ar'] ?? null], $existing);
    }

    public function exportQuery(array $filters): Builder
    {
        return Brand::query()->withCount('products');
    }

    public function exportRow(Model $brand): array
    {
        return ['id' => $brand->id, 'name_en' => $brand->name_en, 'name_ar' => $brand->name_ar, 'image_url' => $brand->image,
            'products_count' => $brand->products_count, 'created_at' => $brand->created_at];
    }

    public function examples(): array
    {
        return [['name_en' => 'Pharma Co', 'name_ar' => 'فارما', 'image_url' => 'https://example.com/logo.png']];
    }
}
