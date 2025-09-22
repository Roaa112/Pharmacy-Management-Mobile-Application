

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1" role="dialog"
    aria-labelledby="addProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form action="{{ route('dashboard.products.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Product</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body row">

                    <div class="form-group col-md-6">
                        <label>Name Ar</label>
                        <input type="text" name="name_ar" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Name En</label>
                        <input type="text" name="name_en" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Description Ar</label>
                        <input type="text" name="description_ar" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Description En</label>
                        <input type="text" name="description_en" class="form-control" required>
                    </div>

                    <div class="form-group col-md-12">
    <label>Sizes / Prices / Stock</label>
    <div id="sizes-wrapper">
        <div class="row size-item mb-2">
            <div class="col-md-4">
                <input type="text" name="sizes[0][size]" class="form-control" placeholder="Size (e.g. Small)">
            </div>
            <div class="col-md-4">
                <input type="number" step="0.01" name="sizes[0][price]" class="form-control" placeholder="Price">
            </div>
            <div class="col-md-3">
                <input type="number" name="sizes[0][stock]" class="form-control" placeholder="Stock">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-danger btn-sm remove-size">&times;</button>
            </div>
        </div>
    </div>
    <button type="button" id="add-size" class="btn btn-primary btn-sm mt-2">+ Add More</button>
</div>


                    <div class="form-group col-md-6">
                        <label>Rate</label>
                        <input type="number" step="0.1" name="rate" class="form-control">
                    </div>

                    <div class="form-group col-md-6">
                        <label>Brand</label>
                        <select name="brand_id" class="form-control">
                            @foreach($brands as $brand)
                            <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </div>

                   <div class="form-group col-md-6">
    <label>Category</label>
    <select name="category_id" class="form-control">
        @foreach($categories as $cat)
            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            
            @if($cat->children && $cat->children->count())
                @foreach($cat->children as $sub)
                    <option value="{{ $sub->id }}">-- {{ $sub->name }}</option>
                @endforeach
            @endif
        @endforeach
    </select>
</div>

                    <div class="form-group col-md-6">
                        <label>Health Issues</label>
                        <select name="health_issues[]" class="form-control" multiple>
                            @foreach($healthIssues as $issue)
                            <option value="{{ $issue->id }}">{{ $issue->name }}</option>
                            @endforeach
                        </select>
                    </div>

                  <div class="form-group col-md-6">
    <label for="promotion_type">Flash Sale Type
        <br>
        "Optional"
    </label>
    <select name="promotion_type" id="promotion_type_create" class="form-control">
        <option value="">None</option>
        {{-- <option value="discount">Discount</option> --}}
        <option value="flash_sale">Flash Sale</option>
    </select>
</div>

{{-- <div class="form-group col-md-6 d-none" id="discount_select_create">
    <label for="discount_id">Select Discount</label>
    <select name="discount_id" class="form-control">
        @foreach($discounts as $discount)
            <option value="{{ $discount->id }}">{{ $discount->title }} ({{ $discount->precentage }}%)</option>
        @endforeach
    </select>
</div> --}}

<div class="form-group col-md-6 d-none" id="flash_sale_select_create">
    <label for="flash_sale_id">Select Flash Sale</label>
    <select name="flash_sale_id" class="form-control">
        @foreach($flashSales as $flashSale)
            <option value="{{ $flashSale->id }}">{{ $flashSale->discount_value }} ({{ $flashSale->date }} → {{ $flashSale->time }})</option>
        @endforeach
    </select>
</div>

                    <div class="form-group col-md-6">
                        <label>Main Image</label>
                        <input type="file" name="image" class="form-control">
                    </div>

                    <div class="form-group col-md-6">
                        <label>Extra Images</label>
                        <input type="file" name="images[]" class="form-control" multiple>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Save</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('promotion_type_create').addEventListener('change', function() {
    const selected = this.value;

    // document.getElementById('discount_select_create').classList.add('d-none');
    document.getElementById('flash_sale_select_create').classList.add('d-none');

    // if (selected === 'discount') {
    //     document.getElementById('discount_select_create').classList.remove('d-none');
    // }

    if (selected === 'flash_sale') {
        document.getElementById('flash_sale_select_create').classList.remove('d-none');
    }
});


let sizeIndex = 1;
document.getElementById('add-size').addEventListener('click', function () {
    const wrapper = document.getElementById('sizes-wrapper');
    const newRow = document.createElement('div');
    newRow.classList.add('row', 'size-item', 'mb-2');
    newRow.innerHTML = `
        <div class="col-md-4">
            <input type="text" name="sizes[${sizeIndex}][size]" class="form-control" placeholder="Size (e.g. Medium)">
        </div>
        <div class="col-md-4">
            <input type="number" step="0.01" name="sizes[${sizeIndex}][price]" class="form-control" placeholder="Price">
        </div>
        <div class="col-md-3">
            <input type="number" name="sizes[${sizeIndex}][stock]" class="form-control" placeholder="Stock">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger btn-sm remove-size">&times;</button>
        </div>
    `;
    wrapper.appendChild(newRow);
    sizeIndex++;
});


// remove size row
document.addEventListener('click', function (e) {
    if (e.target.classList.contains('remove-size')) {
        e.target.closest('.size-item').remove();
    }
});
</script>
