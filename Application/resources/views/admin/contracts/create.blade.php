@extends('layouts.app')

@section('title', '契約申込')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $returnCustomerId = $returnCustomerId ?? null;
        $returnQuery = array_filter(['return_customer_id' => $returnCustomerId]);
    @endphp
    <h1 class="h3 mb-3">契約申込（下書き）</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <ol class="breadcrumb small mb-3">
        <li class="breadcrumb-item {{ ! $selectedCustomer ? 'active' : '' }}">1. カスタマー選択</li>
        <li class="breadcrumb-item {{ $selectedCustomer && ! $selectedSite ? 'active' : '' }}">2. 拠点選択</li>
        <li class="breadcrumb-item {{ $selectedCustomer && $selectedSite ? 'active' : '' }}">3. 品目選択</li>
    </ol>

    @unless ($selectedCustomer)
        <form method="GET" action="{{ route($prefix.'.contracts.create') }}" class="row g-2 align-items-end mb-3">
            @if ($returnCustomerId)
                <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
            @endif
            <div class="col-md-3">
                <label class="form-label small mb-1" for="cn">CN</label>
                <input type="text" name="cn" id="cn" class="form-control form-control-sm" value="{{ $filters['cn'] ?? '' }}" placeholder="部分一致">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1" for="customer_name">カスタマー名</label>
                <input type="text" name="customer_name" id="customer_name" class="form-control form-control-sm" value="{{ $filters['customer_name'] ?? '' }}" placeholder="部分一致">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary btn-sm" type="submit">フィルター</button>
                <a href="{{ route($prefix.'.contracts.create', $returnQuery) }}" class="btn btn-outline-secondary btn-sm">クリア</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>CN</th>
                        <th>カスタマー名</th>
                        <th>管理BP</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td><code>{{ $customer->code }}</code></td>
                            <td>{{ $customer->name }}</td>
                            <td>{{ $customer->managingBp?->code }} / {{ $customer->managingBp?->name }}</td>
                            <td class="text-end">
                                <a class="btn btn-outline-primary btn-sm" href="{{ route($prefix.'.contracts.create', array_filter(['customer_id' => $customer->id, 'cn' => $filters['cn'] ?: null, 'customer_name' => $filters['customer_name'] ?: null, 'return_customer_id' => $returnCustomerId])) }}">選択</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted text-center">カスタマーがありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $customers->links() }}
    @else
        <div class="alert alert-light border mb-3" style="max-width:40rem">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="small text-muted">選択中カスタマー</div>
                    <strong><code>{{ $selectedCustomer->code }}</code> / {{ $selectedCustomer->name }}</strong>
                    <div class="small text-muted">管理BP: {{ $selectedCustomer->managingBp?->code }}</div>
                </div>
                @if ($returnCustomerId)
                    <a href="{{ route($prefix.'.customers.show', ['customer' => $returnCustomerId, 'tab' => 'contracts']) }}" class="btn btn-outline-secondary btn-sm">戻る</a>
                @else
                    <a href="{{ route($prefix.'.contracts.create') }}" class="btn btn-outline-secondary btn-sm">変更</a>
                @endif
            </div>
        </div>

        @unless ($selectedSite)
            <form method="GET" action="{{ route($prefix.'.contracts.create') }}" class="card card-body" style="max-width:40rem">
                <input type="hidden" name="customer_id" value="{{ $selectedCustomer->id }}">
                @if ($returnCustomerId)
                    <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
                @endif
                <div class="mb-3">
                    <label class="form-label" for="site_id">拠点</label>
                    <select name="site_id" id="site_id" class="form-select" required>
                        <option value="">選択してください</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }}@if ($site->is_primary)（主）@endif</option>
                        @endforeach
                    </select>
                </div>
                @if ($sites->isEmpty())
                    <p class="text-danger small mb-0">このカスタマーに拠点がありません。先に拠点を登録してください。</p>
                @else
                    <button class="btn btn-primary" type="submit">次へ（品目選択）</button>
                @endif
            </form>
        @else
            <div class="alert alert-light border mb-3" style="max-width:40rem">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="small text-muted">選択中拠点</div>
                        <strong>{{ $selectedSite->name }}</strong>
                    </div>
                    <a href="{{ route($prefix.'.contracts.create', array_merge(['customer_id' => $selectedCustomer->id], $returnQuery)) }}" class="btn btn-outline-secondary btn-sm">変更</a>
                </div>
            </div>

            <form method="GET" action="{{ route($prefix.'.contracts.create') }}" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="customer_id" value="{{ $selectedCustomer->id }}">
                <input type="hidden" name="site_id" value="{{ $selectedSite->id }}">
                @if ($returnCustomerId)
                    <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
                @endif
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="item_code">コード</label>
                    <input type="text" name="item_code" id="item_code" class="form-control form-control-sm" value="{{ $filters['item_code'] ?? '' }}" placeholder="部分一致">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="item_name">品目名</label>
                    <input type="text" name="item_name" id="item_name" class="form-control form-control-sm" value="{{ $filters['item_name'] ?? '' }}" placeholder="部分一致">
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-primary btn-sm" type="submit">フィルター</button>
                    <a href="{{ route($prefix.'.contracts.create', array_merge(['customer_id' => $selectedCustomer->id, 'site_id' => $selectedSite->id], $returnQuery)) }}" class="btn btn-outline-secondary btn-sm">クリア</a>
                </div>
            </form>

            <form method="POST" action="{{ route($prefix.'.contracts.store') }}">
                @csrf
                <input type="hidden" name="site_id" value="{{ $selectedSite->id }}">
                @if ($returnCustomerId)
                    <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
                @endif
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th style="width:2.5rem"></th>
                                <th>コード</th>
                                <th>品目名</th>
                                <th>区分</th>
                                <th>必須セット</th>
                                <th>仕切り</th>
                                <th>ユーザー標準</th>
                                <th>税率</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($items as $item)
                                <tr>
                                    <td>
                                        <input class="form-check-input" type="checkbox" name="item_ids[]" value="{{ $item->id }}" id="item_{{ $item->id }}">
                                    </td>
                                    <td><label for="item_{{ $item->id }}" class="mb-0"><code>{{ $item->code }}</code></label></td>
                                    <td><label for="item_{{ $item->id }}" class="mb-0">{{ $item->name }}</label></td>
                                    <td>{{ $item->billing_type->label() }}</td>
                                    <td>{{ $item->requiredItem?->code ?? '—' }}</td>
                                    <td>@include('partials.price-display', ['amount' => $item->partition_price, 'taxRate' => $item->tax_rate])</td>
                                    <td>@include('partials.price-display', ['amount' => $item->user_price, 'taxRate' => $item->tax_rate])</td>
                                    <td>{{ $item->tax_rate }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-muted text-center">該当する品目がありません。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $items->links() }}
                <p class="small text-muted">必須セットがある品目は、セット先も同時に選択してください。</p>
                <button class="btn btn-primary" type="submit">下書き作成</button>
            </form>
        @endunless
    @endunless
@endsection
