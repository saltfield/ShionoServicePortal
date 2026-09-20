@php
    $prefix = $prefix ?? '';
    $postalId = ($prefix === '' ? '' : $prefix.'_').'postal_code';
    $addressId = ($prefix === '' ? '' : $prefix.'_').'address';
    $buildingId = ($prefix === '' ? '' : $prefix.'_').'building_name';
    $phoneId = ($prefix === '' ? '' : $prefix.'_').'phone';
    $postalName = $postalId;
    $addressName = $addressId;
    $buildingName = $buildingId;
    $phoneName = $phoneId;
    $model = $model ?? null;
@endphp

<div class="mb-3">
    <label class="form-label" for="{{ $postalId }}">郵便番号</label>
    <div class="input-group">
        <input
            type="text"
            name="{{ $postalName }}"
            id="{{ $postalId }}"
            class="form-control js-postal-code"
            value="{{ old($postalName, data_get($model, $postalName)) }}"
            inputmode="numeric"
            maxlength="8"
            placeholder="100-0001"
            autocomplete="postal-code"
            data-address-target="{{ $addressId }}"
        >
        <button type="button" class="btn btn-outline-secondary js-postal-lookup" data-postal-input="{{ $postalId }}">住所検索</button>
    </div>
    <div class="form-text">半角数字（ハイフン可）。検索で住所を自動入力します。</div>
</div>
<div class="mb-3">
    <label class="form-label" for="{{ $addressId }}">住所</label>
    <input
        type="text"
        name="{{ $addressName }}"
        id="{{ $addressId }}"
        class="form-control js-address"
        value="{{ old($addressName, data_get($model, $addressName)) }}"
        placeholder="都道府県・市区町村・町域"
    >
</div>
<div class="mb-3">
    <label class="form-label" for="{{ $buildingId }}">建物名</label>
    <input
        type="text"
        name="{{ $buildingName }}"
        id="{{ $buildingId }}"
        class="form-control"
        value="{{ old($buildingName, data_get($model, $buildingName)) }}"
        placeholder="ビル名・部屋番号など"
    >
</div>
<div class="mb-3">
    <label class="form-label" for="{{ $phoneId }}">電話</label>
    <input
        type="text"
        name="{{ $phoneName }}"
        id="{{ $phoneId }}"
        class="form-control js-phone"
        value="{{ old($phoneName, data_get($model, $phoneName)) }}"
        inputmode="tel"
        maxlength="20"
        placeholder="03-1234-5678"
        autocomplete="tel"
    >
    <div class="form-text">半角数字とハイフンのみ。</div>
</div>
