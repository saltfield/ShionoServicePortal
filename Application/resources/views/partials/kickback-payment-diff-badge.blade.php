@if ($invoice->hasPaymentDifference())
    <span class="badge text-bg-warning{{ ! empty($class) ? ' '.$class : '' }}" title="税込合計 {{ number_format($invoice->total) }} / 入金 {{ number_format($invoice->paid_amount) }}">差異</span>
@endif
