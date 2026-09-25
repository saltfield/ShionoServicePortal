@extends('layouts.app')

@section('title', 'カスタマー詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $canManageUsers = $canManageUsers ?? false;
        $canViewContracts = $canViewContracts ?? false;
        $canCreateContracts = $canCreateContracts ?? false;
        $canViewTickets = ($prefix === 'admin') && ($canViewTickets ?? false);
        $canViewInvoices = $canViewInvoices ?? false;
        $customerContracts = $customerContracts ?? collect();
        $customerTickets = $customerTickets ?? collect();
        $customerInvoices = $customerInvoices ?? null;
        $allowedTabs = ['overview', 'sites'];
        if ($canManageUsers) {
            $allowedTabs[] = 'users';
        }
        if ($canViewContracts) {
            $allowedTabs[] = 'contracts';
        }
        if ($canViewInvoices) {
            $allowedTabs[] = 'invoices';
        }
        $allowedTabs[] = 'prices';
        if ($canViewTickets) {
            $allowedTabs[] = 'tickets';
        }
        $activeTab = $activeTab ?? 'overview';
        if (! in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'overview';
        }
        $tabLabels = [
            'overview' => '基本情報',
            'sites' => '拠点',
            'users' => 'ユーザー管理',
            'contracts' => '契約',
            'invoices' => '請求',
            'prices' => '価格',
            'tickets' => 'チケット',
        ];
    @endphp

    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'カスタマー', 'url' => route($prefix.'.customers.index', ['managing_bp_id' => $customer->managing_bp_id])]],
        'current' => 'カスタマー詳細',
    ])
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-0">カスタマー詳細</h1>
            <p class="text-muted small mb-0 mt-1">
                <code>{{ $customer->code }}</code> / {{ $customer->name }}
            </p>
        </div>
        <div class="d-flex gap-2">
            @if ($activeTab === 'overview')
                <a href="{{ route($prefix.'.customers.edit', $customer) }}" class="btn btn-primary btn-sm">編集</a>
            @elseif ($activeTab === 'sites')
                <a href="{{ route($prefix.'.sites.create', $customer) }}" class="btn btn-primary btn-sm">拠点追加</a>
            @elseif ($activeTab === 'users')
                <a
                    href="{{ route($prefix.'.users.create', ['type' => 'customer', 'customer_id' => $customer->id, 'return_customer_id' => $customer->id]) }}"
                    class="btn btn-primary btn-sm"
                >ユーザー追加</a>
            @elseif ($activeTab === 'contracts' && $canCreateContracts)
                <a
                    href="{{ route($prefix.'.contracts.create', ['customer_id' => $customer->id, 'return_customer_id' => $customer->id]) }}"
                    class="btn btn-primary btn-sm"
                >オーダー作成</a>
            @elseif ($activeTab === 'prices')
                <a href="{{ route($prefix.'.customers.prices.edit', $customer) }}" class="btn btn-primary btn-sm">価格編集</a>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <ul class="nav nav-tabs mb-3">
        @foreach ($allowedTabs as $tabKey)
            <li class="nav-item">
                <a
                    class="nav-link @if ($activeTab === $tabKey) active @endif"
                    href="{{ route($prefix.'.customers.show', ['customer' => $customer, 'tab' => $tabKey]) }}"
                >{{ $tabLabels[$tabKey] }}</a>
            </li>
        @endforeach
    </ul>

    @if ($activeTab === 'overview')
        <dl class="row mb-0">
            <dt class="col-sm-3">CN</dt><dd class="col-sm-9"><code>{{ $customer->code }}</code></dd>
            <dt class="col-sm-3">カスタマー名</dt><dd class="col-sm-9">{{ $customer->name }}</dd>
            <dt class="col-sm-3">管理BP</dt><dd class="col-sm-9">{{ $customer->managingBp?->code }} / {{ $customer->managingBp?->name }}</dd>
            <dt class="col-sm-3">区分</dt><dd class="col-sm-9">{{ $customer->entity_type?->label() ?? '—' }}</dd>
            <dt class="col-sm-3">2FA</dt><dd class="col-sm-9">{{ $customer->two_factor_mode?->label() ?? '—' }}</dd>
            <dt class="col-sm-3">住所</dt><dd class="col-sm-9">{{ $customer->postal_code }} {{ $customer->address }} {{ $customer->building_name }}</dd>
            <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $customer->is_active ? '有効' : '無効' }}</dd>
        </dl>

        <div class="mt-4">
            <button type="button" class="btn btn-outline-danger btn-sm" id="customerDeleteOpen">カスタマー削除</button>
        </div>
    @endif

    @if ($activeTab === 'sites')
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>名称</th>
                    <th>主拠点</th>
                    <th>設置住所</th>
                    <th>請求書送付先</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($customer->sites as $site)
                    <tr>
                        <td>{{ $site->name }}</td>
                        <td>@if ($site->is_primary)<span class="badge text-bg-primary">主</span>@endif</td>
                        <td class="small">{{ $site->postal_code }} {{ $site->address }} {{ $site->building_name }}</td>
                        <td class="small">{{ $site->billing_name }} / {{ $site->billing_postal_code }} {{ $site->billing_address }} {{ $site->billing_building_name }}</td>
                        <td><a href="{{ route($prefix.'.sites.edit', $site) }}" class="btn btn-outline-secondary btn-sm">編集</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted text-center">拠点なし</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($activeTab === 'users' && $canManageUsers)
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>ログインID</th>
                    <th>氏名</th>
                    <th>ロール</th>
                    <th>状態</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($customerUsers as $user)
                    <tr>
                        <td><code>{{ $user->login_id }}</code></td>
                        <td>{{ $user->name }}</td>
                        <td><code>{{ $user->roles->first()?->code ?? '-' }}</code></td>
                        <td>
                            @if ($user->is_active)
                                <span class="badge text-bg-success">有効</span>
                            @else
                                <span class="badge text-bg-secondary">無効</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a
                                href="{{ route($prefix.'.users.edit', ['user' => $user, 'return_customer_id' => $customer->id]) }}"
                                class="btn btn-outline-secondary btn-sm"
                            >編集</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted text-center">ユーザーなし</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($activeTab === 'contracts' && $canViewContracts)
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>契約番号</th>
                    <th>拠点</th>
                    <th>管理BP</th>
                    <th>状態</th>
                    <th>申し込み日</th>
                    <th>サービス提供日</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($customerContracts as $contract)
                    <tr>
                        <td>
                            <a href="{{ route($prefix.'.contracts.show', ['contract' => $contract, 'return_customer_id' => $customer->id]) }}">
                                <code>{{ $contract->code }}</code>
                            </a>
                        </td>
                        <td>{{ $contract->site?->name ?? '-' }}</td>
                        <td>{{ $contract->owningBp?->code ?? '-' }}</td>
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
                        <td class="small text-nowrap">
                            {{ $contract->applied_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}
                        </td>
                        <td class="small text-nowrap">
                            {{ $contract->activated_at?->timezone(config('app.timezone'))->format('Y-m-d') ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted text-center">契約なし</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($activeTab === 'prices')
        <p class="mb-3">このカスタマー向けの販売価格を設定・確認します。</p>
        <a href="{{ route($prefix.'.customers.prices.edit', $customer) }}" class="btn btn-outline-primary btn-sm">カスタマー価格を開く</a>
    @endif

    @if ($activeTab === 'invoices' && $canViewInvoices)
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th>請求番号</th>
                    <th>請求月</th>
                    <th>契約</th>
                    <th>発行BP</th>
                    <th class="text-end">請求額（税込）</th>
                    <th class="text-end">入金額（税込）</th>
                    <th>状態</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($customerInvoices ?? [] as $invoice)
                    <tr>
                        <td>
                            <a href="{{ route($prefix.'.invoices.show', $invoice) }}">
                                <code>{{ $invoice->code }}</code>
                            </a>
                        </td>
                        <td>{{ $invoice->billing_year_month }}</td>
                        <td><code>{{ $invoice->contract?->code }}</code></td>
                        <td>{{ $invoice->issuerBp?->code ?? '—' }}</td>
                        <td class="text-end">{{ number_format($invoice->total) }}</td>
                        <td class="text-end">
                            @if ($invoice->paid_amount !== null)
                                {{ number_format($invoice->paid_amount) }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            {{ $invoice->status->label() }}
                            @include('partials.invoice-payment-diff-badge', ['invoice' => $invoice, 'class' => 'ms-1'])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted text-center py-4">請求はありません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($customerInvoices)
            {{ $customerInvoices->links() }}
        @endif
    @endif

    @if ($activeTab === 'tickets' && $canViewTickets)
        <p class="small text-muted mb-3">このカスタマーに紐づくチケットを閲覧できます（操作はできません）。</p>
        @include('admin.tickets._org_list', [
            'tickets' => $customerTickets,
            'inspectBack' => ['return_customer_id' => $customer->id],
        ])
    @endif

    @if ($activeTab === 'overview')
        <dialog id="customerDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route($prefix.'.customers.destroy', $customer) }}" class="p-4" id="customerDeleteForm">
                @csrf
                @method('DELETE')
                <h2 class="h5 mb-3">カスタマー削除の確認</h2>
                <p class="mb-2">「{{ $customer->code }} / {{ $customer->name }}」を削除します。</p>
                <p class="mb-3">下の確認コードを入力してください。</p>
                <p class="text-center mb-3">
                    <span class="display-6 fw-bold letter-spacing" id="customerDeleteCode">{{ $deleteConfirmationCode }}</span>
                </p>
                <div class="mb-3">
                    <label class="form-label" for="confirmation_code">確認コード</label>
                    <input
                        type="text"
                        name="confirmation_code"
                        id="confirmation_code"
                        class="form-control text-center"
                        inputmode="numeric"
                        maxlength="4"
                        pattern="[0-9]{4}"
                        autocomplete="off"
                        required
                    >
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="customerDeleteCancel">キャンセル</button>
                    <button type="submit" class="btn btn-danger" id="customerDeleteSubmit" disabled>削除する</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection

@if (($activeTab ?? 'overview') === 'overview')
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dialog = document.getElementById('customerDeleteDialog');
        const openButton = document.getElementById('customerDeleteOpen');
        const cancelButton = document.getElementById('customerDeleteCancel');
        const codeDisplay = document.getElementById('customerDeleteCode');
        const input = document.getElementById('confirmation_code');
        const submit = document.getElementById('customerDeleteSubmit');

        if (!dialog || !openButton || !input || !submit || !codeDisplay) {
            return;
        }

        const expected = () => codeDisplay.textContent.trim();

        const syncSubmit = () => {
            submit.disabled = input.value.trim() !== expected();
        };

        openButton.addEventListener('click', () => {
            input.value = '';
            syncSubmit();
            dialog.showModal();
            input.focus();
        });

        cancelButton?.addEventListener('click', () => dialog.close());

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        input.addEventListener('input', () => {
            input.value = input.value.replace(/\D/g, '').slice(0, 4);
            syncSubmit();
        });
    });
</script>
@endpush
@endif
