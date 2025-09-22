<div class="modal fade" id="addSubcategoryModal-{{ $category->id }}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="{{ route('dashboard.categories.storeSubcategory', $category->id) }}"enctype="multipart/form-data">
      @csrf
      <input type="hidden" name="parent_id" value="{{ $category->id }}">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Create Subcategory</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
        <div class="form-group">
            <label>الاسم الفرعي (عربي)</label>
            <input type="text" name="name_ar" class="form-control" required>
          </div>
          <!-- Subcategory Name in English -->
          <div class="form-group">
            <label>Sub Category Name (English)</label>
            <input type="text" name="name_en" class="form-control" required>
          </div>
        </div>
        <div class="form-group">
            <label for="createBannerImage">Image</label>
            <input type="file" name="image" id="createhealthIssueImage" class="form-control">
          </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">إضافة</button>
        </div>
      </div>
    </form>
  </div>
</div>