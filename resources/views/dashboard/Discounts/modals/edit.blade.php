@foreach($discounts as $discount)
    <div class="modal fade" id="editDiscountModal-{{ $discount->id }}" tabindex="-1" role="dialog" aria-labelledby="editDiscountModalLabel-{{ $discount->id }}" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editDiscountModalLabel-{{ $discount->id }}">Edit Discount Time</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form action="{{ route('dashboard.discounts.update', $discount->id) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="starts_at_{{ $discount->id }}">Starts At</label>
                            <input type="datetime-local" name="starts_at"
                                   id="starts_at_{{ $discount->id }}"
                                   value="{{ \Carbon\Carbon::parse($discount->starts_at)->format('Y-m-d\TH:i') }}"
                                   class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="ends_at_{{ $discount->id }}">Ends At</label>
                            <input type="datetime-local" name="ends_at"
                                   id="ends_at_{{ $discount->id }}"
                                   value="{{ \Carbon\Carbon::parse($discount->ends_at)->format('Y-m-d\TH:i') }}"
                                   class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Time</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach
