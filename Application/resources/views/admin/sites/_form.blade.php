@php
    $site = $site ?? null;
@endphp
<div class="mb-3">
    <label class="form-label" for="name">拠点名</label>
    <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $site?->name) }}" required>
</div>

<h2 class="h6">設置先</h2>
@include('partials.address-fields', ['model' => $site, 'prefix' => ''])

<h2 class="h6 mt-3">請求書送付先（設置先と別設定）</h2>
<div class="mb-3">
    <label class="form-label" for="billing_name">宛名</label>
    <input type="text" name="billing_name" id="billing_name" class="form-control" value="{{ old('billing_name', $site?->billing_name) }}" required>
</div>
<div class="mb-3">
    <label class="form-label" for="billing_department">部署</label>
    <input type="text" name="billing_department" id="billing_department" class="form-control" value="{{ old('billing_department', $site?->billing_department) }}">
</div>
@include('partials.address-fields', [
    'model' => $site,
    'prefix' => 'billing',
])

<div class="form-check mb-2">
    <input type="hidden" name="is_primary" value="0">
    <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="is_primary" @checked(old('is_primary', $site?->is_primary ?? false))>
    <label class="form-check-label" for="is_primary">主拠点にする</label>
</div>
<div class="form-check mb-3">
    <input type="hidden" name="is_active" value="0">
    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $site?->is_active ?? true))>
    <label class="form-check-label" for="is_active">有効</label>
</div>
