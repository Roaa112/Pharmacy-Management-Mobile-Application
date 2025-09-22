<!-- Edit OpeningAd Modal -->
<div class="modal fade" id="editOpeningAdModal{{ $OpeningAd->id }}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="{{ route('dashboard.OpeningAd.update', $OpeningAd->id) }}" enctype="multipart/form-data">
      @csrf
      @method('PUT')
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit OpeningAd</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">
          <div class="form-group">
            <label for="openingAdImage">Current Image</label><br>
            <img src="{{ asset($OpeningAd->imagem) }}" width="100">
          </div>

          <div class="form-group">
            <label for="openingAdImage">Change Image</label>
            <input type="file" name="image" id="openingAdImage" class="form-control">
          </div>

          <div class="form-group">
            <label for="isActive">Is Active</label>
            <select name="is_active" id="isActive" class="form-control">
              <option value="1" {{ $OpeningAd->is_active ? 'selected' : '' }}>Yes</option>
              <option value="0" {{ !$OpeningAd->is_active ? 'selected' : '' }}>No</option>
            </select>
          </div>
        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Update</button>
        </div>
      </div>
    </form>
  </div>
</div>
