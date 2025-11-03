<div class="modal fade" id="createDiscountModal" tabindex="-1" aria-labelledby="createDiscountModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form action="{{ route('dashboard.discounts.store') }}" method="POST" class="modal-content">
      @csrf

      <div class="modal-header">
        <h5 class="modal-title">Create Discount</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>

      <div class="modal-body">
        {{-- Discount Type --}}
        <div class="form-group">
          <label>Discount Type</label>
          <select name="discount_type" class="form-control" id="discountTypeSelect" required>
            <option value="fixed">Fixed Amount</option>
            <option value="percent">Percent</option>
            <option value="buy_x_get_y">Buy X Get Y</option>
            <option value="amount_gift">Spend X, Get Gift</option>
          </select>
        </div>

        {{-- Fixed / Percent --}}
        <div id="fixedPercentFields" class="discount-fields d-none">
          <div class="form-group">
            <label>Discount Value</label>
            <input type="number" step="0.01" name="discount_value" class="form-control">
          </div>
        </div>

        {{-- Buy X Get Y --}}
        <div id="buyXgetYFields" class="discount-fields d-none">
          <div class="form-group">
            <label>Min Quantity</label>
            <input type="number" name="min_quantity" class="form-control">
          </div>
          <div class="form-group">
            <label>Free Quantity</label>
            <input type="number" name="free_quantity" class="form-control">
          </div>
        </div>

        {{-- Spend X Get Gift --}}
        <div id="amountGiftFields" class="discount-fields d-none">
          <div class="form-group">
            <label>Min Amount</label>
            <input type="number" name="min_amount" class="form-control">
          </div>
        </div>

        {{-- Buy Target --}}
        <div class="form-group" id="buyTargetSection">
          <label>Buy Target</label>
          <div class="d-flex gap-2">
            <select name="targets[0][type]" class="form-control w-25" id="targetTypeSelect">
              <option value="product">Product</option>
              <option value="brand">Brand</option>
              <option value="category">Category</option>
            </select>

            <select name="targets[0][id]" id="productSelect" class="form-control w-50 target-select d-none" disabled>
              @foreach($products as $product)
                <option value="{{ $product->id }}">{{ $product->name }}</option>
              @endforeach
            </select>

            <select name="targets[0][id]" id="brandSelect" class="form-control w-50 target-select d-none" disabled>
              @foreach($brands as $brand)
                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
              @endforeach
            </select>

            <select name="targets[0][id]" id="categorySelect" class="form-control w-50 target-select d-none" disabled>
              @foreach($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
              @endforeach
            </select>
          </div>
        </div>

        {{-- Gift Target --}}
        <div class="form-group d-none" id="giftTargetSection">
          <label>Gift Target</label>
          <div class="d-flex gap-2">
            <input type="hidden" name="gift_targets[0][is_gift]" value="1">

            <select name="gift_targets[0][type]" class="form-control w-25" id="giftTargetTypeSelect">
              <option value="product">Product</option>
              <option value="brand">Brand</option>
              <option value="category">Category</option>
            </select>

            {{-- No name attribute here by default --}}
            <select id="giftProductSelect" class="form-control w-50 gift-target-select d-none" disabled>
              @foreach($products as $product)
                <option value="{{ $product->id }}">{{ $product->name }}</option>
              @endforeach
            </select>

            <select id="giftBrandSelect" class="form-control w-50 gift-target-select d-none" disabled>
              @foreach($brands as $brand)
                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
              @endforeach
            </select>

            <select id="giftCategorySelect" class="form-control w-50 gift-target-select d-none" disabled>
              @foreach($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
              @endforeach
            </select>
          </div>
        </div>

        {{-- Validity --}}
        <div class="form-group">
          <label>Starts At</label>
          <input type="datetime-local" name="starts_at" class="form-control">
        </div>
        <div class="form-group">
          <label>Ends At</label>
          <input type="datetime-local" name="ends_at" class="form-control">
        </div>
      </div>

      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Save Discount</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>

@section('js')
<script>
document.addEventListener("DOMContentLoaded", function () {
  const typeSelect = document.getElementById("discountTypeSelect");
  const targetTypeSelect = document.getElementById("targetTypeSelect");
  const giftTargetTypeSelect = document.getElementById("giftTargetTypeSelect");

  function updateFields() {
    const selectedType = typeSelect.value;
    document.querySelectorAll('.discount-fields').forEach(el => el.classList.add('d-none'));

    const giftSection = document.getElementById('giftTargetSection');
    const giftInputs = giftSection.querySelectorAll('select, input:not([type="hidden"])');
    const giftHiddenInputs = giftSection.querySelectorAll('input[type="hidden"]');

    if (selectedType === 'fixed' || selectedType === 'percent') {
      document.getElementById('fixedPercentFields')?.classList.remove('d-none');
      giftSection?.classList.add('d-none');
      giftInputs.forEach(el => el.disabled = true);
      giftHiddenInputs.forEach(el => el.disabled = true);
    } else if (selectedType === 'buy_x_get_y') {
      document.getElementById('buyXgetYFields')?.classList.remove('d-none');
      giftSection?.classList.remove('d-none');
      giftInputs.forEach(el => el.disabled = false);
      giftHiddenInputs.forEach(el => el.disabled = false);
    } else if (selectedType === 'amount_gift') {
      document.getElementById('amountGiftFields')?.classList.remove('d-none');
      giftSection?.classList.remove('d-none');
      giftInputs.forEach(el => el.disabled = false);
      giftHiddenInputs.forEach(el => el.disabled = false);
    }
  }

  function updateTargetSelect() {
    const selected = targetTypeSelect.value;
    document.querySelectorAll('.target-select').forEach(el => {
      el.classList.add('d-none');
      el.disabled = true;
    });

    const selectedEl = document.getElementById(`${selected}Select`);
    if (selectedEl) {
      selectedEl.classList.remove('d-none');
      selectedEl.disabled = false;
    }
  }

  function updateGiftTargetSelect() {
    if (!giftTargetTypeSelect) return;
    const selected = giftTargetTypeSelect.value;

    document.querySelectorAll('.gift-target-select').forEach(el => {
      el.classList.add('d-none');
      el.disabled = true;
      el.name = ''; // Clear name to prevent submission
    });

    const selectedEl = document.getElementById(`gift${capitalize(selected)}Select`);
    if (selectedEl) {
      selectedEl.classList.remove('d-none');
      selectedEl.disabled = false;
      selectedEl.name = 'gift_targets[0][id]'; // Set name only for the active one
    }

    const giftHiddenInput = document.querySelector('input[name="gift_targets[0][is_gift]"]');
    if (giftHiddenInput) {
      giftHiddenInput.disabled = false;
      giftHiddenInput.value = 1;
    }
  }

  function capitalize(string) {
    return string.charAt(0).toUpperCase() + string.slice(1);
  }

  typeSelect.addEventListener("change", updateFields);
  targetTypeSelect.addEventListener("change", updateTargetSelect);
  giftTargetTypeSelect?.addEventListener("change", updateGiftTargetSelect);

  updateFields();
  updateTargetSelect();
  updateGiftTargetSelect();
});
</script>
@stop
