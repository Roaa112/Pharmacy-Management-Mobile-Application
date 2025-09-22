<div class="modal fade" id="createCouponModal" tabindex="-1" role="dialog" aria-labelledby="createCouponModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form action="{{ route('dashboard.coupons.store') }}" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createCouponModalLabel">Add New Coupon</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label for="code">Coupon Code</label>
                        <input type="text" name="code" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="discount_value">Discount Value (%)</label>
                        <input type="number" name="discount_value" class="form-control" min="1" max="100" required>
                    </div>

                    <div class="form-group">
                        <label for="usage_limit">Usage Limit</label>
                        <input type="number" name="usage_limit" class="form-control" min="1">
                    </div>

                    <div class="form-group">
                        <label for="once_per_user">Once Per User</label>
                        <select name="once_per_user" class="form-control">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="start_at">Start Date</label>
                        <input type="date" name="start_at" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="end_at">End Date</label>
                        <input type="date" name="end_at" class="form-control">
                    </div>

                    <div class="form-group form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Create</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>
