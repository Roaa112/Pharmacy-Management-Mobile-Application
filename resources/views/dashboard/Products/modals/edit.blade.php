

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal-{{ $product->id }}" tabindex="-1" role="dialog"
    aria-labelledby="editProductModalLabel-{{ $product->id }}" >
    <div class="modal-dialog modal-lg" role="document">
   <form method="POST" action="{{ route('dashboard.products.update', $product->id) }}"  enctype="multipart/form-data">

            @csrf
            @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Product</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>

                <div class="modal-body row">
                    <!-- Basic Info -->
                    <div class="form-group col-md-6">
                        <label>Name Ar</label>
                        <input type="text" name="name_ar" value="{{ old('name_ar', $product->name_ar) }}" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Name En</label>
                        <input type="text" name="name_en" value="{{ old('name_en', $product->name_en) }}" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Description Ar</label>
                        <input type="text" name="description_ar" value="{{ old('description_ar', $product->description_ar) }}" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Description En</label>
                        <input type="text" name="description_en" value="{{ old('description_en', $product->description_en) }}" class="form-control" required>
                    </div>

                    <!-- Sizes / Prices / Quantities -->
                    <div class="form-group col-md-12">
                        <label>Sizes / Prices / Quantities</label>
                        <div id="edit-sizes-wrapper-{{ $product->id }}">
                            @foreach($product->sizes as $index => $size)
                            <div class="row size-item mb-2">
                                <input type="hidden" name="sizes[{{ $index }}][id]" value="{{ $size->id }}">
                                <div class="col-md-4">
                                    <input type="text" name="sizes[{{ $index }}][size]" value="{{ $size->size }}" class="form-control" placeholder="Size">
                                </div>
                                <div class="col-md-4">
                                    <input type="number" step="0.01" name="sizes[{{ $index }}][price]" value="{{ $size->price }}" class="form-control" placeholder="Price">
                                </div>
                                <div class="col-md-3">
                                    <input type="number" name="sizes[{{ $index }}][stock]" value="{{ $size->stock }}" class="form-control" placeholder="Quantity">
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-danger btn-sm remove-size">&times;</button>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        <button type="button" class="btn btn-primary btn-sm mt-2 add-size-btn"  data-product-id="{{ $product->id }}">+ Add More</button>
                    </div>

                    <!-- Other Fields -->
                    <div class="form-group col-md-6">
                        <label>Rate</label>
                        <input type="number" step="0.1" name="rate" value="{{ old('rate', $product->rate) }}" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Brand</label>
                        <select name="brand_id" class="form-control">
                            @foreach($brands as $brand)
                                <option value="{{ $brand->id }}" {{ old('brand_id', $product->brand_id) == $brand->id ? 'selected' : '' }}>
                                    {{ $brand->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                  <div class="form-group col-md-6">
    <label>Category</label>
    <select name="category_id" class="form-control">
        @foreach($categories as $cat)
            {{-- الفئة الرئيسية --}}
            <option value="{{ $cat->id }}" {{ old('category_id', $product->category_id) == $cat->id ? 'selected' : '' }}>
                {{ $cat->name }}
            </option>

            {{-- الفئات الفرعية --}}
            @if($cat->children && $cat->children->count())
                @foreach($cat->children as $sub)
                    <option value="{{ $sub->id }}" {{ old('category_id', $product->category_id) == $sub->id ? 'selected' : '' }}>
                        -- {{ $sub->name }}
                    </option>
                @endforeach
            @endif
        @endforeach
    </select>
</div>

                    <div class="form-group col-md-6">
                        <label>Health Issues</label>
                        <select name="health_issues[]" class="form-control" multiple>
                            @foreach($healthIssues as $issue)
                                <option value="{{ $issue->id }}"
                                    {{ in_array($issue->id, old('health_issues', $product->healthIssues->pluck('id')->toArray())) ? 'selected' : '' }}>
                                    {{ $issue->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                  <div class="form-group col-md-6">
    <label>Flash Sale Type</label>
    <select name="promotion_type" id="promotion_type_{{ $product->id }}" class="form-control promotion-selector" data-id="{{ $product->id }}">
        <option value="">None</option>
        {{-- <option value="discount" {{ old('promotion_type', $product->promotion_type) === 'discount' ? 'selected' : '' }}>Discount</option> --}}
        <option value="flash_sale" {{ old('promotion_type', $product->promotion_type) === 'flash_sale' ? 'selected' : '' }}>Flash Sale</option>
    </select>
</div>

{{-- <div class="form-group col-md-6 {{ old('promotion_type', $product->promotion_type) === 'discount' ? '' : 'd-none' }}" id="discount_select_{{ $product->id }}">
    <label>Select Discount</label>
    <select name="discount_id" class="form-control">
        @foreach($discounts as $discount)
            <option value="{{ $discount->id }}" {{ old('discount_id', $product->discount_id) == $discount->id ? 'selected' : '' }}>
                {{ $discount->title }} ({{ $discount->precentage }}%)
            </option>
        @endforeach
    </select>
</div> --}}

<div class="form-group col-md-6 {{ old('promotion_type', $product->promotion_type) === 'flash_sale' ? '' : 'd-none' }}" id="flash_sale_select_{{ $product->id }}">
    <label>Select Flash Sale</label>
    <select name="flash_sale_id" class="form-control">
        @foreach($flashSales as $flashSale)
            <option value="{{ $flashSale->id }}" {{ old('flash_sale_id', $product->flash_sale_id) == $flashSale->id ? 'selected' : '' }}>
                {{ $flashSale->discount_value }} ({{ $flashSale->date }} → {{ $flashSale->time }})
            </option>
        @endforeach
    </select>
</div>


                    <!-- Images -->
                    <div class="form-group col-md-6">
                        <label>Current Main Image</label><br>
                        @if($product->image)
                            <img src="{{ asset($product->image) }}" alt="Main Image" width="100" class="mb-2">
                        @endif
                        <label>New Main Image (optional)</label>
                        <input type="file" name="image" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Current Extra Images</label><br>
                        @foreach($product->productImages as $img)
                            <div style="display:inline-block; position:relative;">
                                <img src="{{ asset($img->image_path) }}" alt="Extra Image" width="100" class="m-1">
                                <div>
                                    <input type="checkbox" name="remove_extra_images[]" value="{{ $img->id }}"> Remove
                                </div>
                            </div>
                        @endforeach
                        <label class="mt-2 d-block">Add Extra Images</label>
                        <input type="file" name="images[]" class="form-control" multiple>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Update</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>
@section('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
    let sizeIndexes = {};
    sizeIndexes[{{ $product->id }}] = {{ count($product->sizes) }};
console.log(sizeIndexes);

    // Add new size row
    document.querySelectorAll('.add-size-btn').forEach(button => {
        button.addEventListener('click', function () {
            const productId = this.dataset.productId;
            console.log(productId);

            const wrapper = document.getElementById('edit-sizes-wrapper-' + productId);
            console.log(wrapper);
            // const index = sizeIndexes[productId];
            const index = wrapper.childElementCount;
            console.log(index);

            const row = document.createElement('div');
            row.classList.add('row', 'size-item', 'mb-2');
            row.innerHTML = `
                <div class="col-md-4">
                    <input type="text" name="sizes[${index}][size]" class="form-control" placeholder="Size">
                </div>
                <div class="col-md-4">
                    <input type="number" step="0.01" name="sizes[${index}][price]" class="form-control" placeholder="Price">
                </div>
                <div class="col-md-3">
                    <input type="number" name="sizes[${index}][stock]" class="form-control" placeholder="stock">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-danger btn-sm remove-size">&times;</button>
                </div>
            `;
            wrapper.appendChild(row);
            sizeIndexes[productId]++;
        });
    });

    // Remove size row
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-size')) {
            e.target.closest('.size-item').remove();
        }
    });

    // Show/hide promotion fields
  document.querySelectorAll('.promotion-selector').forEach(selector => {
    selector.addEventListener('change', function () {
        const id = this.dataset.id;
        const type = this.value;

        // document.getElementById('discount_select_' + id).classList.add('d-none');
        document.getElementById('flash_sale_select_' + id).classList.add('d-none');

        // if (type === 'discount') {
        //     document.getElementById('discount_select_' + id).classList.remove('d-none');
        // }

        if (type === 'flash_sale') {
            document.getElementById('flash_sale_select_' + id).classList.remove('d-none');
        }
    });
});

});
</script>
@endsection
