<!-- Edit DeliveryPrice Modal -->
<div class="modal fade" id="editDeliveryPriceModal-{{ $delivery->id }}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="{{ route('dashboard.delivery_prices.update', $delivery->id) }}">
      @csrf
      @method('PUT')

      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit DeliveryPrice</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">

        <div class="form-group">
            <label for="governorate">Governorate</label>
            <input type="governorate" name="governorate" class="form-control" value="{{ old('governorate', $delivery->governorate) }}" required>
        </div>



          <div class="form-group">
            <label for="price">Price</label>
            <input type="number" name="price" class="form-control" value="{{ old('price', $delivery->price) }}" min="0" max="100" step="0.01" required>
          </div>

        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Update DeliveryPrice</button>
        </div>
      </div>
    </form>
  </div>
</div>
