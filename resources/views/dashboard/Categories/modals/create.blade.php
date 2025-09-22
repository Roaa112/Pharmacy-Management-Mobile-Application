<div class="modal fade" id="createCategoryModal" tabindex="-1" aria-labelledby="createCategoryModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form action="{{ route('dashboard.categories.store') }}" method="POST" enctype="multipart/form-data">
      @csrf
      
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">إضافة كاتيجوري</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <!-- اسم الكاتيجوري باللغة العربية -->
          <div class="form-group">
            <label>اسم الكاتيجوري (عربي)</label>
            <input type="text" name="name_ar" class="form-control" value="{{ old('name_ar') }}" required>
          </div>
          <!-- اسم الكاتيجوري باللغة الإنجليزية -->
          <div class="form-group">
            <label>اسم الكاتيجوري (إنجليزي)</label>
            <input type="text" name="name_en" class="form-control" value="{{ old('name_en') }}" required>
          </div>
          <div class="form-group">
            <label for="createBannerImage">Image</label>
            <input type="file" name="image" id="createhealthIssueImage" class="form-control">
          </div>
          <input type="hidden" name="parent_id" value=""> {{-- فارغ لأنه كاتيجوري رئيسي --}}
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">إضافة</button>
        </div>
      </div>
    </form>
  </div>
</div>
