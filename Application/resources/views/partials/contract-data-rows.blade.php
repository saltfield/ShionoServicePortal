{{--
  @var \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection $existingRows
  @var \Illuminate\Support\Collection $dataFieldNames
  @var int $maxRows
--}}
@php
    $existingRows = $existingRows ?? collect();
    $maxRows = $maxRows ?? 10;
    $initialCount = max(1, min($maxRows, $existingRows->count()));
@endphp
<div class="js-data-row-list" data-max-rows="{{ $maxRows }}">
    @for ($i = 0; $i < $initialCount; $i++)
        @php $row = $existingRows[$i] ?? null; @endphp
        <div class="row g-2 mb-2 js-data-row align-items-center">
            <div class="col-md-3">
                <select name="rows[{{ $i }}][data_field_name_id]" class="form-select form-select-sm js-data-field-select">
                    <option value="">（直接入力）</option>
                    @foreach ($dataFieldNames as $field)
                        <option
                            value="{{ $field->id }}"
                            data-replace-code="{{ $field->replace_code }}"
                            data-name="{{ $field->name }}"
                            @selected((int) ($row?->data_field_name_id) === $field->id)
                        >{{ $field->name }} ({{ $field->replace_code }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <input type="text" name="rows[{{ $i }}][name]" class="form-control form-control-sm js-data-field-name" placeholder="名称" value="{{ $row?->name }}">
            </div>
            <div class="col-md-2">
                <input type="text" name="rows[{{ $i }}][replace_code]" class="form-control form-control-sm js-data-field-code" placeholder="置換コード" pattern="[a-z][a-z0-9_]*" value="{{ $row?->replace_code }}">
            </div>
            <div class="col-md-3">
                <input type="text" name="rows[{{ $i }}][value]" class="form-control form-control-sm" placeholder="値" value="{{ $row?->value }}">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-secondary btn-sm js-data-row-remove" title="行を削除">削除</button>
            </div>
        </div>
    @endfor
</div>
<div class="d-flex align-items-center gap-2 mb-2">
    <button type="button" class="btn btn-outline-secondary btn-sm js-data-row-add">行を追加</button>
    <span class="text-muted small js-data-row-count"></span>
</div>
