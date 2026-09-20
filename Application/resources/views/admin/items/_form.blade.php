<div class="mb-3">
    <label class="form-label" for="name">名称</label>
    <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $item?->name) }}" required>
    <div class="form-text">コードは自動採番されます。</div>
</div>
<div class="mb-3">
    <label class="form-label" for="description">説明</label>
    <textarea name="description" id="description" class="form-control" rows="2">{{ old('description', $item?->description) }}</textarea>
</div>
<div class="mb-3">
    <label class="form-label" for="billing_type">課金区分</label>
    <select name="billing_type" id="billing_type" class="form-select" required>
        @foreach ($billingTypes as $type)
            <option value="{{ $type->value }}" @selected(old('billing_type', $item?->billing_type?->value) === $type->value)>
                {{ $type->label() }}
            </option>
        @endforeach
    </select>
</div>
<div class="mb-3">
    <label class="form-label" for="required_item_id">必須セット品目（任意）</label>
    <select name="required_item_id" id="required_item_id" class="form-select">
        <option value="">なし</option>
        @foreach ($requiredCandidates as $candidate)
            <option value="{{ $candidate->id }}" @selected((int) old('required_item_id', $item?->required_item_id) === $candidate->id)>
                {{ $candidate->code }} / {{ $candidate->name }}（{{ $candidate->billing_type->label() }}）
            </option>
        @endforeach
    </select>
    <div class="form-text">例: ランニング品目に対してイニシャル品目を必須にする。</div>
</div>
<div class="mb-3">
    <label class="form-label" for="partition_price">標準仕切り（税別）</label>
    <input type="number" step="1" min="0" name="partition_price" id="partition_price" class="form-control" value="{{ old('partition_price', $item?->partition_price ?? 0) }}" required>
</div>
<div class="mb-3">
    <label class="form-label" for="recommended_price">推奨価格（税別）</label>
    <input type="number" step="1" min="0" name="recommended_price" id="recommended_price" class="form-control" value="{{ old('recommended_price', $item?->recommended_price ?? 0) }}" required>
</div>
<div class="mb-3">
    <label class="form-label" for="user_price">ユーザー標準（税別）</label>
    <input type="number" step="1" min="0" name="user_price" id="user_price" class="form-control" value="{{ old('user_price', $item?->user_price ?? 0) }}" required>
</div>
<div class="mb-3">
    <label class="form-label" for="tax_rate">消費税率（%）</label>
    <input type="number" step="1" min="0" max="100" name="tax_rate" id="tax_rate" class="form-control" value="{{ old('tax_rate', $item?->tax_rate ?? 10) }}" required>
    <div class="form-text">既定 10</div>
</div>
<div class="form-check mb-3">
    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $item?->is_active ?? true))>
    <label class="form-check-label" for="is_active">有効</label>
</div>
