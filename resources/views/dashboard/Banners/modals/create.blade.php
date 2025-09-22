<div class="modal fade" id="createBannerModal" tabindex="-1" aria-labelledby="createBannerModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form action="{{ route('dashboard.banners.store') }}" method="POST" enctype="multipart/form-data">
      @csrf
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="createBannerModalLabel">Create New Banner</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span>&times;</span>
          </button>
        </div>
        <div class="modal-body">

          <div class="form-group">
            <label for="createBannerTitle">Title Ar</label>
            <input type="text" name="title_ar" id="createBannerTitle" class="form-control" value="{{ old('title_ar') }}" required>
          </div>

          <div class="form-group">
            <label for="createBannerTitle">Title En</label>
            <input type="text" name="title_en" id="createBannerTitle" class="form-control" value="{{ old('title_en') }}" required>
          </div>

          <div class="form-group">
            <label for="createBannerImage">Image</label>
            <input type="file" name="image" id="createBannerImage" class="form-control">
          </div>

        <div class="form-group">
  <label for="createBannerLink">Link</label>
  <select name="link" id="createBannerLink" class="form-control">
    <option value="{{ url('/api/v1/user/top-discount-products') }}"
      {{ old('link') == url('/api/v1/user/top-discount-products') ? 'selected' : '' }}>
      /api/v1/user/top-discount-products
    </option>

    <option value="{{ url('/api/v1/user/products/on/sale') }}"
      {{ old('link') == url('/api/v1/user/products/on/sale') ? 'selected' : '' }}>
      /api/v1/user/products/on/sale
    </option>

    <option value="{{ url('/api/v1/user/health_issues') }}"
      {{ old('link') == url('/api/v1/user/health_issues') ? 'selected' : '' }}>
      /api/v1/user/health_issues
    </option>

    <option value="{{ url('/api/v1/user/categories') }}"
      {{ old('link') == url('/api/v1/user/categories') ? 'selected' : '' }}>
      /api/v1/user/categories
    </option>

    <option value="{{ url('/api/v1/user/brands') }}"
      {{ old('link') == url('/api/v1/user/brands') ? 'selected' : '' }}>
      /api/v1/user/brands
    </option>
  </select>
</div>


          <div class="form-group">
            <label for="createBannerStatus">Status</label>
            <select name="status" id="createBannerStatus" class="form-control">
              <option value="1" {{ old('status') == '1' ? 'selected' : '' }}>Active</option>
              <option value="0" {{ old('status') == '0' ? 'selected' : '' }}>Inactive</option>
            </select>
          </div>

          <div class="form-group">
            <label for="createBannerOrder">Order of Appearance</label>
            <input type="number" name="order_of_appearance" id="createBannerOrder" class="form-control" value="{{ old('order_of_appearance', 0) }}" min="0">
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Add Banner</button>
        </div>
      </div>
    </form>
  </div>
</div>
