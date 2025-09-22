<div class="modal fade" id="createDeliveryPriceModal" tabindex="-1" aria-labelledby="createDeliveryPriceModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form action="{{ route('dashboard.delivery_prices.store') }}" method="POST">
      @csrf
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Add DeliveryPrice</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">
         <div class="form-group">
            <label for="governorate">Governorate</label>
            <input type="governorate" name="governorate" class="form-control" " required>
        </div>



          <div class="form-group">
            <label for="price">Price</label>
            <input type="number" name="price" class="form-control"  min="0" max="100" step="0.01" required>
          </div>


        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Add DeliveryPrice</button>
        </div>
      </div>
    </form>
  </div>
</div>
