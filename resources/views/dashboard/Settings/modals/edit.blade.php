<div class="modal fade" id="editSettingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('dashboard.settings.update', $setting->id) }}">
            @csrf
            @method('PUT')

            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Setting</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label>Privacy Policy (Arabic)</label>
                        <textarea name="privacy_policy_ar" class="form-control" rows="2">{{ old('privacy_policy_ar', $setting->privacy_policy_ar) }}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Privacy Policy (English)</label>
                        <textarea name="privacy_policy_en" class="form-control" rows="2">{{ old('privacy_policy_en', $setting->privacy_policy_en) }}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Terms and Conditions (Arabic)</label>
                        <textarea name="terms_conditions_ar" class="form-control" rows="2">{{ old('terms_conditions_ar', $setting->terms_conditions_ar) }}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Terms and Conditions (English)</label>
                        <textarea name="terms_conditions_en" class="form-control" rows="2">{{ old('terms_conditions_en', $setting->terms_conditions_en) }}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Facebook</label>
                        <input type="url" name="facebook" class="form-control" value="{{ old('facebook', $setting->facebook) }}">
                    </div>
                    <div class="form-group">
                        <label>Instagram</label>
                        <input type="url" name="instagram" class="form-control" value="{{ old('instagram', $setting->instagram) }}">
                    </div>
                    <div class="form-group">
                        <label>TikTok</label>
                        <input type="url" name="tiktok" class="form-control" value="{{ old('tiktok', $setting->tiktok) }}">
                    </div>
                    <div class="form-group">
                        <label>YouTube</label>
                        <input type="url" name="youtube" class="form-control" value="{{ old('youtube', $setting->youtube) }}">
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone_number" class="form-control" value="{{ old('phone_number', $setting->phone_number) }}">
                    </div>

                     <div class="form-group">
                        <label>Zain Cash Number</label>
                        <input type="text" name="zaincash" class="form-control" value="{{ old('zaincash', $setting->zaincash) }}">
                    </div>

                     
                    <div class="form-group">
                        <label>Map Location URL</label>
                        <input type="url" name="map_location_url" class="form-control" value="{{ old('map_location_url', $setting->map_location_url) }}">
                    </div>
                </div>
                    <div class="form-group">
                        <label>Buy By value</label>
                        <input type="text" name="spend_x" class="form-control" value="{{ old('spend_x', $setting->spend_x) }}">
                    </div>

                     <div class="form-group">
                        <label>Get points</label>
                        <input type="text" name="get_y" class="form-control" value="{{ old('get_y', $setting->get_y) }}">
                    </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Update Setting</button>
                </div>
            </div>
        </form>
    </div>
</div>
