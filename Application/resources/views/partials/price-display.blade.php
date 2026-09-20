@php
    use App\Support\TaxPrice;
    $amount = $amount ?? 0;
    $rate = (int) ($taxRate ?? 10);
@endphp
<span class="text-nowrap">
    税別 {{ TaxPrice::formatExclusive($amount) }}
    <span class="text-muted small ms-1">（税込 {{ TaxPrice::formatInclusive($amount, $rate) }}）</span>
</span>
