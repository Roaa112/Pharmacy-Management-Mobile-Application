<!-- Create OpeningAd Modal -->
<div class="modal fade" id="createOpeningAdModal" tabindex="-1" aria-labelledby="createOpeningAdModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form action="{{ route('dashboard.OpeningAd.store') }}" method="POST" enctype="multipart/form-data">
      @csrf

      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Add Opening Ad</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">

          <div class="form-group">
            <label for="openingAdImage">Image</label>
            <input type="file" name="image" id="openingAdImage" class="form-control" required>
          </div>

          <div class="form-group">
            <label for="is_active">Is Active</label>
            <select name="is_active" id="is_active" class="form-control">
              <option value="1">Yes</option>
              <option value="0" selected>No</option>
            </select>
          </div>

        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Add</button>
        </div>
      </div>
    </form>
  </div>
</div>
