{{--
    Repeatable product lines for document forms (purchase orders, transfers, supplier returns).
    @include('dashboard.partials.line-items', ['id' => 'po', 'sizes' => $sizes, 'cost' => true, 'bucket' => false])
    Posts lines[i][product_size_id], lines[i][quantity] and, when enabled, lines[i][unit_cost] / lines[i][source_bucket].
--}}
@php $cost ??= false; $bucket ??= false; @endphp
<div class="line-items" id="lines-{{ $id }}">
    <div class="table-responsive">
        <table class="table table-sm table-vcenter mb-2">
            <thead><tr><th>Product / SKU</th><th class="w-1">Qty</th>@if ($cost)<th class="w-1">Unit cost</th>@endif @if ($bucket)<th class="w-1">From</th>@endif<th class="w-1"></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
    <button type="button" class="btn btn-sm btn-outline-primary" data-add-line><i class="ti ti-plus me-1"></i>Add line</button>
    <template>
        <tr>
            <td>
                <select class="form-select form-select-sm" data-name="product_size_id" required aria-label="Product">
                    <option value="">Choose…</option>
                    @foreach ($sizes as $s)
                        <option value="{{ $s->id }}" data-cost="{{ $s->cost_price }}">{{ $s->product?->name_en ?? 'Product' }} — {{ $s->size }}{{ $s->sku ? ' ('.$s->sku.')' : '' }}</option>
                    @endforeach
                </select>
            </td>
            <td><input type="number" min="1" step="1" class="form-control form-control-sm" data-name="quantity" required style="width: 6rem" aria-label="Quantity"></td>
            @if ($cost)<td><input type="number" min="0" step="0.01" class="form-control form-control-sm" data-name="unit_cost" required style="width: 7rem" aria-label="Unit cost"></td>@endif
            @if ($bucket)
                <td><select class="form-select form-select-sm" data-name="source_bucket" aria-label="Source stock">
                    <option value="damaged">Damaged</option><option value="on_hand">Sellable</option><option value="quarantined">Quarantine</option>
                </select></td>
            @endif
            <td><button type="button" class="btn btn-sm btn-ghost-danger btn-icon" data-remove-line aria-label="Remove line"><i class="ti ti-x"></i></button></td>
        </tr>
    </template>
</div>

@once
    @push('scripts')
        <script>
            document.querySelectorAll('.line-items').forEach(function (box) {
                var body = box.querySelector('tbody'), template = box.querySelector('template'), index = 0;
                function add() {
                    var row = template.content.firstElementChild.cloneNode(true);
                    row.querySelectorAll('[data-name]').forEach(function (field) { field.name = 'lines[' + index + '][' + field.dataset.name + ']'; });
                    var select = row.querySelector('[data-name="product_size_id"]'), costField = row.querySelector('[data-name="unit_cost"]');
                    if (costField) select.addEventListener('change', function () { var c = select.selectedOptions[0].dataset.cost; if (c && !costField.value) costField.value = c; });
                    row.querySelector('[data-remove-line]').addEventListener('click', function () { row.remove(); });
                    body.appendChild(row); index++;
                }
                box.querySelector('[data-add-line]').addEventListener('click', add);
                add();
            });
        </script>
    @endpush
@endonce
