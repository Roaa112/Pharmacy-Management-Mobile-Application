<?php

namespace App\Modules\FlashSale\Requests;

use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class StoreFlashSaleRequest extends FormRequest
{
    /** New sales need an explicit end; there is no built-in duration. */
    protected bool $endRequired = true;

    public function rules(): array
    {
        $fixed = $this->input('discount_type') === FlashSale::TYPE_FIXED;

        return [
            'name_en' => 'nullable|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'discount_type' => 'nullable|in:percent,fixed',
            'discount_value' => ['required', 'numeric', 'gt:0', $fixed ? 'max:99999999' : 'max:100'],
            'is_active' => 'required|boolean',
            'date' => $this->endRequired ? 'required|date|after_or_equal:today' : 'required|date',
            'time' => 'required|date_format:H:i',
            // Store wall-clock time.
            'ends_at' => [$this->endRequired ? 'required' : 'nullable', 'date', function ($attribute, $value, $fail) {
                $start = Carbon::parse($this->input('date').' '.$this->input('time'));
                if (Carbon::parse($value)->lte($start)) {
                    $fail('The end must be after the start.');
                }
            }],
            'priority' => 'nullable|integer|min:-1000|max:1000',
            'quantity_limit' => 'nullable|integer|min:1',
            'per_customer_limit' => 'nullable|integer|min:1|max:1000',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'size_ids' => 'nullable|array',
            'size_ids.*' => 'integer|exists:product_sizes,id',
            // The form always sends it, so clearing every product is possible.
            'sync_items' => 'nullable|boolean',
        ];
    }

    /** No two active flash sales may cover the same product or variant at the same time. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || !$this->boolean('is_active')) {
                return;
            }

            $sale = $this->route('flash_sale') ? FlashSale::find($this->route('flash_sale')) : null;
            $start = Carbon::parse($this->input('date').' '.$this->input('time'));
            $end = $this->filled('ends_at') ? Carbon::parse($this->input('ends_at')) : ($sale?->ends_at ?? $start->copy()->addMinutes((int) config('promotions.flash_sale_default_minutes', 60)));
            $products = array_map('intval', (array) $this->input('product_ids', []));
            $sizes = array_map('intval', (array) $this->input('size_ids', []));
            if (!$this->has('product_ids') && !$this->has('size_ids') && $sale) {
                $products = $sale->items->whereNull('product_size_id')->pluck('product_id')->all();
                $sizes = $sale->items->whereNotNull('product_size_id')->pluck('product_size_id')->all();
            }
            $sizeProducts = \App\Models\ProductSize::whereIn('id', $sizes)->pluck('product_id', 'id');

            $others = FlashSale::with('items')->where('is_active', true)->where('id', '!=', $sale?->id ?? 0)->get()
                ->filter(fn (FlashSale $other) => $other->starts_at->lt($end) && $other->ends_at->gt($start));

            foreach ($others as $other) {
                $clash = $other->items->first(fn (FlashSaleItem $item) => in_array($item->product_id, $products, true)
                    || ($item->product_size_id === null && $sizeProducts->contains($item->product_id))
                    || ($item->product_size_id !== null && (in_array($item->product_size_id, $sizes, true) || in_array($item->product_id, $products, true))));
                if ($clash) {
                    $validator->errors()->add('product_ids', "Flash sale “{$other->name}” already covers product #{$clash->product_id} at that time.");
                    break;
                }
            }
        });
    }
}
