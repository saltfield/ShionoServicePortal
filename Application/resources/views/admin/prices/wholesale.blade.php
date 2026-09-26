@extends('layouts.app')

@section('title', '卸価格')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $selectedBuyer = $selectedBuyer ?? null;
    @endphp
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="ssp-page-title mb-0">卸価格（親→直接子）</h1>
        <a href="{{ route($prefix.'.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @unless ($lockSeller ?? false)
        <form method="GET" class="row g-2 align-items-end mb-3">
            <div class="col-md-6">
                <label class="form-label" for="seller_bp_id">売手BP（親）</label>
                <select name="seller_bp_id" id="seller_bp_id" class="form-select" onchange="this.form.submit()">
                    <option value="">選択してください</option>
                    @foreach ($sellers as $candidate)
                        <option value="{{ $candidate->id }}" @selected($seller?->id === $candidate->id)>
                            {{ $candidate->code }} / {{ $candidate->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </form>
    @else
        <p class="text-muted mb-3">売手BP: <code>{{ $seller->code }}</code> / {{ $seller->name }}</p>
    @endunless

    @if ($seller)
        @if ($buyers->isEmpty())
            <p class="text-muted">直接の子BPがありません。</p>
        @else
            <form method="GET" class="row g-2 align-items-end mb-4">
                @unless ($lockSeller ?? false)
                    <input type="hidden" name="seller_bp_id" value="{{ $seller->id }}">
                @endunless
                <div class="col-md-6">
                    <label class="form-label" for="buyer_bp_id">買手BP（直接の子）</label>
                    <select name="buyer_bp_id" id="buyer_bp_id" class="form-select" onchange="this.form.submit()">
                        <option value="">選択してください</option>
                        @foreach ($buyers as $buyer)
                            <option value="{{ $buyer->id }}" @selected($selectedBuyer?->id === $buyer->id)>
                                {{ $buyer->code }} / {{ $buyer->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </form>

            @if ($selectedBuyer)
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h5 mb-0">
                        仕切り一覧
                        <span class="text-muted small fw-normal">
                            （{{ $selectedBuyer->code }} / {{ $selectedBuyer->name }}）
                        </span>
                    </h2>
                </div>
                <p class="text-muted small">未設定の品目は標準仕切りが表示されています。値を変更して一括保存できます。</p>

                @if ($items->isEmpty())
                    <p class="text-muted">編集可能な品目がありません。</p>
                @else
                    <form method="POST" action="{{ route($prefix.'.prices.wholesale.store') }}">
                        @csrf
                        @unless ($lockSeller ?? false)
                            <input type="hidden" name="seller_bp_id" value="{{ $seller->id }}">
                        @endunless
                        <input type="hidden" name="buyer_bp_id" value="{{ $selectedBuyer->id }}">

                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>コード</th>
                                        <th>名称</th>
                                        <th>区分</th>
                                        <th>標準仕切り</th>
                                        <th style="min-width:10rem">卸価格（税別）</th>
                                        <th>税込（参考）</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($items as $item)
                                        @php
                                            $current = (int) $service->resolveWholesaleAmount($item, $seller, $selectedBuyer);
                                            $isCustom = isset($wholesaleByItem[$item->id]);
                                        @endphp
                                        <tr>
                                            <td><code>{{ $item->code }}</code></td>
                                            <td>
                                                {{ $item->name }}
                                                @if ($item->owning_bp_id)
                                                    <span class="badge text-bg-info">独自</span>
                                                @endif
                                            </td>
                                            <td>{{ $item->billing_type->label() }}</td>
                                            <td>
                                                @include('partials.price-display', [
                                                    'amount' => $item->partition_price,
                                                    'taxRate' => $item->tax_rate,
                                                ])
                                            </td>
                                            <td>
                                                <input
                                                    type="number"
                                                    step="1"
                                                    min="0"
                                                    name="amounts[{{ $item->id }}]"
                                                    class="form-control form-control-sm js-wholesale-amount"
                                                    value="{{ old('amounts.'.$item->id, $current) }}"
                                                    data-tax-rate="{{ $item->tax_rate }}"
                                                    data-inclusive-target="wholesale-inclusive-{{ $item->id }}"
                                                    required
                                                >
                                                <div class="form-text">
                                                    {{ $isCustom ? '個別設定あり' : '標準仕切りを適用中' }}
                                                </div>
                                            </td>
                                            <td>
                                                <span id="wholesale-inclusive-{{ $item->id }}" class="small text-nowrap">
                                                    税込 {{ number_format((int) round($current * (100 + (int) $item->tax_rate) / 100)) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-primary" type="submit">一覧を保存</button>
                    </form>
                @endif
            @else
                <p class="text-muted">買手BPを選択すると、品目ごとの仕切りを一覧編集できます。</p>
            @endif
        @endif
    @endif
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.js-wholesale-amount').forEach((input) => {
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
