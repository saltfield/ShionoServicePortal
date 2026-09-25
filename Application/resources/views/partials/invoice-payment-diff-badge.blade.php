@if ($invoice->hasPaymentDifference())
    <span class="badge text-bg-warning{{ ! empty($class) ? ' '.$class : '' }}" title="請求税込 {{ number_format($invoice->total) }} / 入金 {{ number_format($invoice->paid_amount) }}">差異</span>
@endif
