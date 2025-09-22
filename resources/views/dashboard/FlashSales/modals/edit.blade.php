<!-- Edit FlashSale Modal -->
<div class="modal fade" id="editFlashSaleModal-{{ $flashSale->id }}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="{{ route('dashboard.flash_sales.update', $flashSale->id) }}">
      @csrf
      @method('PUT')

      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit FlashSale</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">
          <!-- Discount Value -->
          <div class="form-group">
            <label for="discount_value">Discount Value (%)</label>
            <input type="number" name="discount_value" class="form-control" value="{{ old('discount_value', $flashSale->discount_value) }}" min="0" max="100" step="0.01" required>
          </div>

          <!-- Active Status -->
          <div class="form-group">
            <label for="is_active">Active</label>
            <select name="is_active" class="form-control" required>
              <option value="1" {{ $flashSale->is_active ? 'selected' : '' }}>Yes</option>
              <option value="0" {{ !$flashSale->is_active ? 'selected' : '' }}>No</option>
            </select>
          </div>

          <!-- Date -->
          <div class="form-group">
            <label for="date">Date</label>
            <input type="date" name="date" class="form-control" value="{{ old('date', $flashSale->date) }}" required>
          </div>

          <!-- Time -->
          <div class="form-group">
            <label for="time">Time</label>
            <input type="time" name="time" class="form-control" value="{{ old('time', $flashSale->time) }}" required>
          </div>

        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Update FlashSale</button>
        </div>
      </div>
    </form>
  </div>
</div>
