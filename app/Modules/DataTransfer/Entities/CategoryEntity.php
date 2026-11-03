<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\Category;
use App\Modules\DataTransfer\Import\GroupPlan;
use App\Modules\DataTransfer\Import\ImportContext;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Categories and subcategories (parent_id). Matched by id: names are never used to match. */
class CategoryEntity extends KeyedModelEntity
{
    public function key(): string { return 'categories'; }

    public function label(): string { return 'Categories'; }

    public function description(): string { return 'Categories and subcategories of the catalog.'; }

    public function importPermission(): ?string { return 'categories.import'; }

    public function exportPermission(): ?string { return 'categories.export'; }

    protected function model(): string { return Category::class; }

    protected function keyColumn(): string { return 'id'; }

    protected function imageColumns(): array { return ['image_url' => 'category']; }

    public function columns(): array
    {
        return [
            'id' => Column::make('Id', 'Empty for a new category; the id of the category to update.', false, 'Existing id', ''),
            'name_en' => Column::make('Name (English)', '', true, 'Text, max 255', 'Vitamins'),
            'name_ar' => Column::make('Name (Arabic)', '', true, 'Text, max 255', 'فيتامينات'),
            'parent_id' => Column::make('Parent category id', 'Empty for a top-level category.', false, 'Existing category id', ''),
            'image_url' => Column::make('Image', 'Required for a new category: http(s) URL or existing path (category/x.png).', true, 'URL or path', 'https://example.com/vitamins.png'),
            'parent_name' => Column::exportOnly('Parent name'),
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
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id'), $existing ? Rule::notIn([$existing->id]) : 'nullable'],
            'image_url' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function afterValidate(GroupPlan $plan, ImportContext $context, array $data, ?Model $existing): void
    {
        // A new category with the same name under the same parent would be a duplicate.
        if (!$existing && !empty($data['name_en']) && Category::where('name_en', $data['name_en'])->where('parent_id', $data['parent_id'] ?? null)->exists()) {
            $plan->error($plan->firstRow(), 'name_en', "A category named \"{$data['name_en']}\" already exists here; put its id to update it.", $data['name_en']);
        }
    }

    protected function attributes(array $data, ?Model $existing, GroupPlan $plan): array
    {
        return self::filled([
            'name_en' => $data['name_en'] ?? null,
            'name_ar' => $data['name_ar'] ?? null,
        ], $existing) + (array_key_exists('parent_id', $data) && ($data['parent_id'] !== null || !$existing) ? ['parent_id' => $data['parent_id']] : []);
    }

    public function exportQuery(array $filters): Builder
    {
        return Category::query()->with('parent:id,name_en')->withCount('products');
    }

    public function exportRow(Model $category): array
    {
        return [
            'id' => $category->id, 'name_en' => $category->name_en, 'name_ar' => $category->name_ar, 'parent_id' => $category->parent_id,
            'image_url' => $category->image, 'parent_name' => $category->parent?->name_en, 'products_count' => $category->products_count,
            'created_at' => $category->created_at,
        ];
    }

    public function examples(): array
    {
        return [['name_en' => 'Vitamins', 'name_ar' => 'فيتامينات', 'image_url' => 'https://example.com/vitamins.png']];
    }
}
