@extends('layouts.app')

@section('title', 'カスタマー価格')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php $prefix = $routePrefix ?? 'admin'; @endphp
    @include('partials.breadcrumb', [
        'crumbs' => [
            ['label' => 'カスタマー', 'url' => route($prefix.'.customers.index', ['managing_bp_id' => $customer->managing_bp_id])],
            ['label' => 'カスタマー詳細', 'url' => route($prefix.'.customers.show', ['customer' => $customer, 'tab' => 'prices'])],
        ],
        'current' => 'カスタマー価格',
    ])
    <h1 class="h3 mb-3">カスタマー価格</h1>

    <p class="text-muted mb-3">
        <code>{{ $customer->code }}</code> / {{ $customer->name }}
        （管理BP: {{ $customer->managingBp?->code }}）
    </p>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.customers.prices.update', $customer) }}">
        @csrf
        @method('PUT')
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>品目</th>
                        <th>区分</th>
                        <th>税率</th>
                        <th>標準（税別）</th>
                        <th style="width:10rem">設定金額（税別）</th>
                        <th>税込（自動）</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        @php $current = old('prices.'.$item->id, $amounts[$item->id] ?? $item->user_price); @endphp
                        <tr>
                            <td>{{ $item->code }} / {{ $item->name }}</td>
                            <td>{{ $item->billing_type->label() }}</td>
                            <td>{{ $item->tax_rate }}%</td>
                            <td>@include('partials.price-display', ['amount' => $item->user_price, 'taxRate' => $item->tax_rate])</td>
                            <td>
                                <input
                                    type="number"
                                    step="1"
                                    min="0"
                                    name="prices[{{ $item->id }}]"
                                    class="form-control form-control-sm js-tax-exclusive"
                                    data-tax-rate="{{ $item->tax_rate }}"
                                    data-inclusive-target="inclusive-{{ $item->id }}"
                                    value="{{ $current }}"
                                >
                            </td>
                            <td>
                                <span id="inclusive-{{ $item->id }}" class="small text-muted">
                                    税込 {{ \App\Support\TaxPrice::formatInclusive($current, $item->tax_rate) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">有効な品目がありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($items->isNotEmpty())
            <button class="btn btn-primary" type="submit">保存</button>
        @endif
    </form>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.js-tax-exclusive').forEach((input) => {
            const sync = () => {
                const rate = Number(input.dataset.taxRate || 10);
                const exclusive = Number(input.value || 0);
                const inclusive = Math.round(exclusive * (100 + rate) / 100);
                const target = document.getElementById(input.dataset.inclusiveTarget);
                if (target) {
                    target.textContent = '税込 ' + inclusive.toLocaleString('ja-JP');
                }
            };
            input.addEventListener('input', sync);
            sync();
        });
    });
</script>
@endpush
