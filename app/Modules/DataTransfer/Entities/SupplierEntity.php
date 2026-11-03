<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\Supplier;
use App\Modules\DataTransfer\Import\GroupPlan;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Suppliers, matched by their code. Invoices, payments and balances are never imported. */
class SupplierEntity extends KeyedModelEntity
{
    private const FIELDS = ['name', 'contact_name', 'phone', 'email', 'address', 'tax_number', 'currency_code', 'payment_terms_days', 'notes'];

    public function key(): string { return 'suppliers'; }

    public function label(): string { return 'Suppliers'; }

    public function description(): string { return 'Supplier contact details and payment terms (not their invoices or balances).'; }

    public function importPermission(): ?string { return 'suppliers.import'; }

    public function exportPermission(): ?string { return 'suppliers.export'; }

    protected function model(): string { return Supplier::class; }

    protected function keyColumn(): string { return 'code'; }

    public function columns(): array
    {
        return [
            'code' => Column::make('Code', 'Unique supplier code.', true, 'Max 30', 'SUP-001'),
            'name' => Column::make('Name', '', true, 'Text, max 255', 'Cairo Pharma Distribution'),
            'contact_name' => Column::make('Contact', '', false, 'Text', 'Ahmed'),
            'phone' => Column::make('Phone', '', false, 'Max 30', '01000000000'),
            'email' => Column::make('Email', '', false, 'Email', 'orders@supplier.example'),
            'address' => Column::make('Address', '', false, 'Text', ''),
            'tax_number' => Column::make('Tax number', '', false, 'Max 50', ''),
            'currency_code' => Column::make('Currency', '', false, '3 letters', 'EGP'),
            'payment_terms_days' => Column::make('Payment terms (days)', '', false, '0–365', '30'),
            'notes' => Column::make('Notes', '', false, 'Text', ''),
            'is_active' => Column::make('Active', '', false, '1 / 0', '1'),
            'created_at' => Column::exportOnly('Created at'),
        ];
    }

    protected function rules(?Model $existing): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'name' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'in:0,1,true,false,yes,no'],
        ];
    }

    protected function attributes(array $data, ?Model $existing, GroupPlan $plan): array
    {
        $attributes = self::filled(array_intersect_key($data, array_flip(self::FIELDS)), $existing) + ['code' => $data['code']];
        if (($data['is_active'] ?? null) !== null || !$existing) {
            $attributes['is_active'] = self::bool($data['is_active'] ?? null);
        }
        if (isset($attributes['currency_code'])) {
            $attributes['currency_code'] = strtoupper($attributes['currency_code']);
        }

        return $attributes;
    }

    public function filters(): array
    {
        return ['is_active' => ['Status', 'select', ['1' => 'Active', '0' => 'Inactive']]];
    }

    public function exportQuery(array $filters): Builder
    {
        return Supplier::query()->when(isset($filters['is_active']) && $filters['is_active'] !== '', fn ($q) => $q->where('is_active', (bool) $filters['is_active']));
    }

    public function exportRow(Model $supplier): array
    {
        return $supplier->only(['code', ...self::FIELDS]) + ['is_active' => $supplier->is_active ? 1 : 0, 'created_at' => $supplier->created_at];
    }

    public function examples(): array
    {
        return [['code' => 'SUP-001', 'name' => 'Cairo Pharma Distribution', 'phone' => '01000000000', 'currency_code' => 'EGP', 'payment_terms_days' => '30', 'is_active' => '1']];
    }
}
