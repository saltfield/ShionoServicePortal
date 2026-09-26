@extends('layouts.app')

@section('title', '契約一覧')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $filters = $filters ?? [
            'contract_code' => '',
            'bpn' => '',
            'bp_name' => '',
            'cn' => '',
            'customer_name' => '',
            'item_code' => '',
            'item_name' => '',
            'data_name' => '',
            'data_value' => '',
            'match' => 'and',
        ];
        $filterQuery = collect($filters)
            ->reject(fn ($value, $key) => $key === 'match' ? $value !== 'or' : $value === '')
            ->all();
        $statusFilter = $statusFilter ?? null;
        $unreadMessagesFilter = $unreadMessagesFilter ?? false;
        $statusTabs = [
            '' => 'すべて',
            'draft' => 'オーダー作成中',
            'pending_price_approval' => '価格申請（未決裁）',
            'approved' => '承認済・手配中',
            'activated' => 'サービス提供開始',
            'cancelled' => '解約',
        ];
        $showAppliedAt = $statusFilter === 'pending_price_approval';
        $showActivatedAt = $statusFilter === 'activated';
        $colspan = 5 + ($showAppliedAt ? 1 : 0) + ($showActivatedAt ? 1 : 0);
        $clearQuery = array_filter([
            'status' => $statusFilter,
            'unread_messages' => $unreadMessagesFilter ? 1 : null,
        ]);
        $tabBaseQuery = array_merge($filterQuery, array_filter([
            'unread_messages' => $unreadMessagesFilter ? 1 : null,
        ]));
        $searchExpanded = $filterQuery !== [];
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="ssp-page-title mb-0">契約一覧</h1>
            <p class="text-muted small mb-0 mt-1">
                @if (($routePrefix ?? 'admin') === 'bp')
                    配下BPの全契約を表示します。特定カスタマーの契約はカスタマー詳細から確認できます。
                @else
                    全カスタマーの契約を表示します。特定カスタマーの契約はカスタマー詳細から確認できます。
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route(($routePrefix ?? 'admin').'.contracts.create') }}" class="btn btn-primary btn-sm">オーダー作成</a>
            <a href="{{ route(($routePrefix ?? 'admin').'.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
        </div>
    </div>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (! empty($unreadMessagesFilter))
        <div class="alert alert-info py-2">
            未読オーダーメッセージがある契約のみ表示しています。
            <a href="{{ route(($routePrefix ?? 'admin').'.contracts.index', array_merge($filterQuery, array_filter(['status' => $statusFilter]))) }}" class="ms-2">すべて表示</a>
        </div>
    @endif

    <div class="accordion mb-3" id="contractSearchAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="contractSearchHeading">
                <button
                    class="accordion-button{{ $searchExpanded ? '' : ' collapsed' }} py-2"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#contractSearchCollapse"
                    aria-expanded="{{ $searchExpanded ? 'true' : 'false' }}"
                    aria-controls="contractSearchCollapse"
                >
                    検索条件
                    @if ($searchExpanded)
                        <span class="badge text-bg-primary ms-2">適用中</span>
                    @endif
                </button>
            </h2>
            <div
                id="contractSearchCollapse"
                class="accordion-collapse collapse{{ $searchExpanded ? ' show' : '' }}"
                aria-labelledby="contractSearchHeading"
                data-bs-parent="#contractSearchAccordion"
            >
                <div class="accordion-body">
                    <form method="GET">
                        @if ($statusFilter)
                            <input type="hidden" name="status" value="{{ $statusFilter }}">
                        @endif
                        @if ($unreadMessagesFilter)
                            <input type="hidden" name="unread_messages" value="1">
                        @endif
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small mb-1" for="contract_code">契約番号</label>
                                <input type="text" id="contract_code" name="contract_code" value="{{ $filters['contract_code'] }}" class="form-control form-control-sm" placeholder="例: CTR*">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small mb-1" for="bpn">BPN</label>
                                <input type="text" id="bpn" name="bpn" value="{{ $filters['bpn'] }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small mb-1" for="bp_name">BP名</label>
                                <input type="text" id="bp_name" name="bp_name" value="{{ $filters['bp_name'] }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small mb-1" for="cn">CN</label>
                                <input type="text" id="cn" name="cn" value="{{ $filters['cn'] }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small mb-1" for="customer_name">カスタマー名</label>
                                <input type="text" id="customer_name" name="customer_name" value="{{ $filters['customer_name'] }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small mb-1" for="item_code">品目コード</label>
                                <input type="text" id="item_code" name="item_code" value="{{ $filters['item_code'] }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small mb-1" for="item_name">品目名</label>
                                <input type="text" id="item_name" name="item_name" value="{{ $filters['item_name'] }}" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small mb-1" for="data_name">データ名</label>
                                <input type="text" id="data_name" name="data_name" value="{{ $filters['data_name'] }}" class="form-control form-control-sm" placeholder="例: 回線番号">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small mb-1" for="data_value">データ値</label>
                                <input type="text" id="data_value" name="data_value" value="{{ $filters['data_value'] }}" class="form-control form-control-sm" placeholder="例: CAF*">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label small mb-1" for="match">条件結合</label>
                                <select id="match" name="match" class="form-select form-select-sm">
                                    <option value="and" @selected(($filters['match'] ?? 'and') === 'and')>AND（すべて一致）</option>
                                    <option value="or" @selected(($filters['match'] ?? 'and') === 'or')>OR（いずれか一致）</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-primary btn-sm" type="submit">検索</button>
                                <a href="{{ route(($routePrefix ?? 'admin').'.contracts.index', $clearQuery) }}" class="btn btn-outline-secondary btn-sm">クリア</a>
                            </div>
                        </div>
                        <p class="small text-muted mb-0 mt-2">未指定時は部分一致。ワイルドカード <code>*</code> が使えます（例: <code>CTR*</code>、<code>*123</code>）。</p>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-pills flex-wrap gap-1 mb-3">
        @foreach ($statusTabs as $value => $label)
            <li class="nav-item">
                <a
                    class="nav-link py-1 px-2{{ ($statusFilter ?? '') === $value ? ' active' : '' }}"
                    href="{{ route(($routePrefix ?? 'admin').'.contracts.index', array_merge($tabBaseQuery, $value === '' ? [] : ['status' => $value])) }}"
                >{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    @if (in_array($statusFilter, ['pending_price_approval', 'approved'], true))
        <p class="small text-muted mb-2">古いものから上に表示しています。上から順に対応してください。</p>
    @endif

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>契約番号</th>
                    <th>カスタマー</th>
                    <th>拠点</th>
                    <th>管理BP</th>
                    <th>状態</th>
                    @if ($showAppliedAt)
                        <th>申請日時</th>
                    @endif
                    @if ($showActivatedAt)
                        <th>提供開始日</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($contracts as $contract)
                    <tr>
                        <td>
                            <a href="{{ route(($routePrefix ?? 'admin').'.contracts.show', array_merge(['contract' => $contract], ! empty($unreadMessagesFilter) ? ['tab' => 'messages'] : [])) }}">
                                <code>{{ $contract->code }}</code>
                            </a>
                        </td>
                        <td>{{ $contract->customer?->code }}</td>
                        <td>{{ $contract->site?->name }}</td>
                        <td>{{ $contract->owningBp?->code }}</td>
                        <td>{{ $contract->status->label() }}
                            @if ($contract->special_price_requested)
                                <span class="badge text-bg-danger ms-1">特価申請あり</span>
                            @endif
                            @if ($contract->status->value === 'activated' && $contract->billing_suspended)
                                <span class="badge text-bg-warning ms-1">請求停止</span>
                            @endif
                            @if ($contract->status->value === 'activated' && $contract->end_user_billing_disabled)
                                <span class="badge text-bg-secondary ms-1">EU請求無効</span>
                            @endif
                        </td>
                        @if ($showAppliedAt)
                            <td class="small text-nowrap">
                                {{ $contract->applied_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}
                            </td>
                        @endif
                        @if ($showActivatedAt)
                            <td class="small text-nowrap">
                                {{ $contract->activated_at?->timezone(config('app.timezone'))->format('Y-m-d') ?? '—' }}
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $colspan }}" class="text-muted">契約がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $contracts->links() }}
@endsection
