<div class="modal fade" id="createSettingModal" tabindex="-1" aria-labelledby="createSettingModalLabel"
    aria-hidden="true">
    <div class="modal-dialog">
        <form action="{{ route('dashboard.settings.store') }}" method="POST">
            @csrf

            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Setting</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label>Privacy Policy (Arabic)</label>
                        <textarea name="privacy_policy_ar" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Privacy Policy (English)</label>
                        <textarea name="privacy_policy_en" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Terms and Conditions (Arabic)</label>
                        <textarea name="terms_conditions_ar" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Terms and Conditions (English)</label>
                        <textarea name="terms_conditions_en" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Facebook</label>
                        <input type="url" name="facebook" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Instagram</label>
                        <input type="url" name="instagram" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>TikTok</label>
                        <input type="url" name="tiktok" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>YouTube</label>
                        <input type="url" name="youtube" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone_number" class="form-control">
                    </div>
                     <div class="form-group">
                        <label>Zain Cash Number</label>
                        <input type="text" name="zaincash" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Map Location URL</label>
                        <input type="url" name="map_location_url" class="form-control">
                    </div>
                </div>

                 <div class="form-group">
                        <label>Buy By value   (money for points)</label>
                        <input type="text" name="spend_x" class="form-control">
                    </div>

                     <div class="form-group">
                        <label>Get points</label>
                        <input type="text" name="get_y" class="form-control">
                    </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Add Setting</button>
                </div>
            </div>
        </form>
    </div>
</div>
