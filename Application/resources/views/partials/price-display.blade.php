@php
    use App\Support\TaxPrice;
    $amount = $amount ?? 0;
    $rate = (int) ($taxRate ?? 10);
    $stacked = (bool) ($stacked ?? false);
@endphp
@if ($stacked)
    <span class="d-inline-block">
        <span class="d-block text-nowrap">税別 {{ TaxPrice::formatExclusive($amount) }}</span>
        <span class="d-block text-nowrap text-muted small">税込 {{ TaxPrice::formatInclusive($amount, $rate) }}</span>
    </span>
@else
    <span class="text-nowrap">
        税別 {{ TaxPrice::formatExclusive($amount) }}
        <span class="text-muted small ms-1">（税込 {{ TaxPrice::formatInclusive($amount, $rate) }}）</span>
    </span>
@endif
