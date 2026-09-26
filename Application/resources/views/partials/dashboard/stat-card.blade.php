@props([
    'url',
    'label',
    'count',
    'attention' => false,
])
<a href="{{ $url }}" class="ssp-stat-card{{ $attention ? ' ssp-stat-card--attention' : '' }}">
    <div class="ssp-stat-card__label">{{ $label }}</div>
    <div class="ssp-stat-card__value">{{ $count }}</div>
</a>
