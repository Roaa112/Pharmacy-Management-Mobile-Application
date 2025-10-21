<div class="modal fade" id="editBannerModal{{ $banner->id }}" tabindex="-1" aria-labelledby="editBannerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form id="editBannerForm" method="POST" action="{{ route('dashboard.banners.update', $banner->id) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editBannerModalLabel">Edit Banner</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="editBannerTitle">Banner Title Ar</label>
                        <input type="text" name="title_ar" id="editBannerTitle_ar" value="{{ old('title_ar', $banner->title_ar) }}" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="editBannerTitle">Banner Title En</label>
                        <input type="text" name="title_en" id="editBannerTitle_en" value="{{ old('title_en', $banner->title_en) }}" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="editBannerImage">Banner Image</label>
                        <input type="file" name="image" id="editBannerImage" class="form-control">
                        <div id="editBannerImagePreview" class="mt-2">
                            @if($banner->image)
                                <img src="{{ asset('storage/' . $banner->image) }}" alt="Current Banner Image" width="100" class="img-thumbnail">
                            @endif
                        </div>
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
                        <label for="editBannerStatus">Status</label>
                        <select name="status" id="editBannerStatus" class="form-control">
                            <option value="1" {{ old('status', $banner->status) == 1 ? 'selected' : '' }}>Active</option>
                            <option value="0" {{ old('status', $banner->status) == 0 ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="editBannerOrder">Order of Appearance</label>
                        <input type="number" name="order_of_appearance" id="editBannerOrder" value="{{ old('order_of_appearance', $banner->order_of_appearance) }}" class="form-control" min="0">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success">Update</button>
                </div>
            </div>
        </form>
    </div>
</div>
