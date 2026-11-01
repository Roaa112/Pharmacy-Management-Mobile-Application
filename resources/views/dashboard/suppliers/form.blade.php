<div class="row">
    <x-admin.input col="col-md-4 mb-3" name="code" label="Code" :value="$supplier?->code" required maxlength="30" />
    <x-admin.input col="col-md-8 mb-3" name="name" label="Name" :value="$supplier?->name" required />
    <x-admin.input col="col-md-6 mb-3" name="contact_name" label="Contact person" :value="$supplier?->contact_name" />
    <x-admin.input col="col-md-6 mb-3" name="phone" label="Phone" :value="$supplier?->phone" />
    <x-admin.input col="col-md-6 mb-3" name="email" type="email" label="Email" :value="$supplier?->email" />
    <x-admin.input col="col-md-6 mb-3" name="tax_number" label="Tax number" :value="$supplier?->tax_number" />
    <x-admin.input col="col-md-8 mb-3" name="address" label="Address" :value="$supplier?->address" />
    <x-admin.input col="col-md-2 mb-3" name="currency_code" label="Currency" :value="$supplier?->currency_code" maxlength="3" />
    <x-admin.input col="col-md-2 mb-3" name="payment_terms_days" type="number" min="0" label="Terms (days)" :value="$supplier?->payment_terms_days" />
</div>
<x-admin.input name="notes" type="textarea" label="Notes" :value="$supplier?->notes" />
<input type="hidden" name="is_active" value="0">
<label class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($supplier?->is_active ?? true)><span class="form-check-label">Active</span></label>
