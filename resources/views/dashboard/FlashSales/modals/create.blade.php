<div class="modal fade" id="createFlashSaleModal" tabindex="-1" aria-labelledby="createFlashSaleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form action="{{ route('dashboard.flash_sales.store') }}" method="POST">
      @csrf
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Add FlashSale</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">
          <!-- Discount Value -->
          <div class="form-group">
            <label for="discount_value">Discount Value (%)</label>
            <input type="number" name="discount_value" class="form-control" min="0" max="100" step="0.01" required>
          </div>

          <!-- Active Status -->
          <div class="form-group">
            <label for="is_active">Active</label>
            <select name="is_active" class="form-control" required>
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>

          <!-- Date -->
          <div class="form-group">
            <label for="date">Date</label>
            <input type="date" name="date" class="form-control" required>
          </div>

          <!-- Time -->
          <div class="form-group">
            <label for="time">Time</label>
            <input type="time" name="time" class="form-control" required>
          </div>

        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Add FlashSale</button>
        </div>
      </div>
    </form>
  </div>
</div>
