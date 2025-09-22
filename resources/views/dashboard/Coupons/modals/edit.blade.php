<div class="modal fade" id="editCouponModal{{ $coupon->id }}" tabindex="-1" role="dialog" aria-labelledby="editCouponModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form action="{{ route('dashboard.coupons.update', $coupon->id) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editCouponModalLabel">Edit Coupon</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label for="code">Coupon Code</label>
                        <input type="text" name="code" class="form-control" value="{{ $coupon->code }}" required>
                    </div>

                    <div class="form-group">
                        <label for="discount_value">Discount Value (%)</label>
                        <input type="number" name="discount_value" class="form-control" min="1" max="100" value="{{ $coupon->discount_value }}" required>
                    </div>

                    <div class="form-group">
                        <label for="usage_limit">Usage Limit</label>
                        <input type="number" name="usage_limit" class="form-control" min="1" value="{{ $coupon->usage_limit }}">
                    </div>

                    <div class="form-group">
                        <label for="once_per_user">Once Per User</label>
                        <select name="once_per_user" class="form-control">
                            <option value="0" {{ $coupon->once_per_user == 0 ? 'selected' : '' }}>No</option>
                            <option value="1" {{ $coupon->once_per_user == 1 ? 'selected' : '' }}>Yes</option>
                        </select>
                    </div>

                    <div class="form-group">
    <label for="start_at">Start Date</label>
    <input type="date" name="start_at" class="form-control" value="{{ old('start_at', $coupon->start_at ? $coupon->start_at->toDateString() : '') }}">
</div>

<div class="form-group">
    <label for="end_at">End Date</label>
    <input type="date" name="end_at" class="form-control" value="{{ old('end_at', $coupon->end_at ? $coupon->end_at->toDateString() : '') }}">
</div>


                    <div class="form-group form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" value="1" {{ $coupon->is_active ? 'checked' : '' }}>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Update</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>
