@extends('layouts.app')

@section('title', '卸価格')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">卸価格（親→直接子）</h1>
        <a href="{{ route(($routePrefix ?? 'admin').'.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @unless ($lockSeller ?? false)
        <form method="GET" class="row g-2 align-items-end mb-4">
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
        <p class="text-muted">売手BP: <code>{{ $seller->code }}</code> / {{ $seller->name }}</p>
    @endunless

    @if ($seller)
        @if ($buyers->isEmpty())
            <p class="text-muted">直接の子BPがありません。</p>
        @else
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.prices.wholesale.store') }}" class="card card-body mb-4" style="max-width:40rem">
                @csrf
                @unless ($lockSeller ?? false)
                    <input type="hidden" name="seller_bp_id" value="{{ $seller->id }}">
                @endunless
                <div class="mb-3">
                    <label class="form-label" for="buyer_bp_id">買手BP（直接の子）</label>
                    <select name="buyer_bp_id" id="buyer_bp_id" class="form-select" required>
                        @foreach ($buyers as $buyer)
                            <option value="{{ $buyer->id }}">{{ $buyer->code }} / {{ $buyer->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="item_id">品目</label>
                    <select name="item_id" id="item_id" class="form-select" required>
                        @foreach ($items as $item)
                            <option value="{{ $item->id }}" data-tax-rate="{{ $item->tax_rate }}">
                                {{ $item->code }} / {{ $item->name }}（{{ $item->billing_type->label() }}）標準仕切り 税別 {{ number_format($item->partition_price) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="amount">卸価格（仕切り・税別）</label>
                    <input type="number" step="1" min="0" name="amount" id="amount" class="form-control" required>
                    <div class="form-text" id="amountInclusiveHint">税込は保存後の一覧で確認できます。</div>
                </div>
                <button class="btn btn-primary" type="submit">保存</button>
            </form>

            <h2 class="h5">現在値（未設定は標準仕切り・税別／税込）</h2>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>品目</th>
                            @foreach ($buyers as $buyer)
                                <th>{{ $buyer->code }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td>{{ $item->code }}</td>
                                @foreach ($buyers as $buyer)
                                    <td>
                                        @include('partials.price-display', [
                                            'amount' => $service->resolveWholesaleAmount($item, $seller, $buyer),
                                            'taxRate' => $item->tax_rate,
                                        ])
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endsection
