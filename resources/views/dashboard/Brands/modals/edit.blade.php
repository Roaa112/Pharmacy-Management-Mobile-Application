<!-- Edit Brand Modal -->
<div class="modal fade" id="editBrandModal{{ $brand->id }}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="editBrandForm" method="POST" action="{{ route('dashboard.brands.update', $brand->id) }}" enctype="multipart/form-data">
      @csrf
      @method('PUT')
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit Brand</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label> Brand Name En</label>
            <input type="text" name="name_en" id="editBrandNameEn" value="{{ old('name_en', $brand->name_en) }}" class="form-control" required>
          </div>
          <div class="form-group">
            <label> Brand Name Ar</label>
            <input type="text" name="name_ar" id="editBrandNameAr" value="{{ old('name_ar', $brand->name_ar) }}" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="createBannerImage">Image</label>
            <input type="file" name="image" id="createhealthIssueImage" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">تحديث</button>
        </div>
      </div>
    </form>
  </div>
</div>
