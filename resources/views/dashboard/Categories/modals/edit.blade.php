<div class="modal fade" id="editCategoryModal{{ $category->id }}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="editCategoryForm" method="POST" action="{{ route('dashboard.categories.update', $category->id) }}" enctype="multipart/form-data">
      @csrf
      @method('PUT')
      <input type="hidden" name="parent_id" value="{{ $category->parent_id }}">
      
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">تعديل كاتيجوري</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <!-- اسم الكاتيجوري باللغة العربية -->
          <div class="form-group">
            <label>اسم الكاتيجوري (عربي)</label>
            <input type="text" name="name_ar" id="editCategoryName_ar" value="{{ old('name_ar', $category->name_ar) }}" class="form-control" required>
          </div>
          <!-- اسم الكاتيجوري باللغة الإنجليزية -->
          <div class="form-group">
            <label>اسم الكاتيجوري (إنجليزي)</label>
            <input type="text" name="name_en" id="editCategoryName_en" value="{{ old('name_en', $category->name_en) }}" class="form-control" required>
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
