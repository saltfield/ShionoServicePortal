@extends('layouts.app')

@section('title', 'BP詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $canManageUsers = $canManageUsers ?? false;
        $canViewCustomers = $canViewCustomers ?? false;
        $canManageCustomers = $canManageCustomers ?? false;
        $canViewTickets = ($prefix === 'admin') && ($canViewTickets ?? false);
        $canViewBilling = $canViewBilling ?? false;
        $canViewContracts = $canViewContracts ?? false;
        $bpUsers = $bpUsers ?? collect();
        $bpCustomers = $bpCustomers ?? collect();
        $bpContracts = $bpContracts ?? collect();
        $bpTickets = $bpTickets ?? collect();
        $billing = $billing ?? null;
        $allowedTabs = ['overview'];
        if ($canViewCustomers) {
            $allowedTabs[] = 'customers';
        }
        if ($canViewContracts) {
            $allowedTabs[] = 'contracts';
        }
        if ($canManageUsers) {
            $allowedTabs[] = 'users';
        }
        if ($canViewBilling) {
            $allowedTabs[] = 'billing';
        }
        if ($canViewTickets) {
            $allowedTabs[] = 'tickets';
        }
        $activeTab = $activeTab ?? 'overview';
        if (! in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'overview';
        }
        $showTabs = count($allowedTabs) > 1;
        $tabLabels = [
            'overview' => '基本情報',
            'customers' => 'カスタマー',
            'contracts' => '契約',
            'users' => 'ユーザー管理',
            'billing' => '請求',
            'tickets' => 'チケット',
        ];
    @endphp
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'BP管理', 'url' => route($prefix.'.business-partners.index')]],
        'current' => 'BP詳細',
    ])
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="ssp-page-title mb-0">BP詳細</h1>
        <div class="d-flex gap-2">
            @if ($activeTab === 'overview')
                <a href="{{ route($prefix.'.business-partners.edit', $partner) }}" class="btn btn-primary btn-sm">編集</a>
                <a href="{{ route($prefix.'.business-partners.create', ['parent_id' => $partner->id]) }}" class="btn btn-outline-primary btn-sm">子BP追加</a>
            @elseif ($activeTab === 'customers' && $canManageCustomers)
                <a
                    href="{{ route($prefix.'.customers.create', ['managing_bp_id' => $partner->id]) }}"
                    class="btn btn-primary btn-sm"
                >カスタマー追加</a>
            @elseif ($activeTab === 'users')
                <a
                    href="{{ route($prefix.'.users.create', ['type' => 'bp', 'bp_id' => $partner->id, 'return_bp_id' => $partner->id]) }}"
                    class="btn btn-primary btn-sm"
                >ユーザー追加</a>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @if ($showTabs)
        <ul class="nav nav-tabs mb-3">
            @foreach ($tabLabels as $tabKey => $label)
                @if (in_array($tabKey, $allowedTabs, true))
                    <li class="nav-item">
                        <a
                            class="nav-link @if ($activeTab === $tabKey) active @endif"
                            href="{{ route($prefix.'.business-partners.show', $tabKey === 'overview' ? $partner : ['businessPartner' => $partner, 'tab' => $tabKey]) }}"
                        >{{ $label }}</a>
                    </li>
                @endif
            @endforeach
        </ul>
    @endif

    @if ($activeTab === 'overview')
    <dl class="row">
        <dt class="col-sm-3">BPN</dt><dd class="col-sm-9"><code>{{ $partner->code }}</code></dd>
        <dt class="col-sm-3">名称</dt><dd class="col-sm-9">{{ $partner->name }}</dd>
        <dt class="col-sm-3">階層</dt><dd class="col-sm-9">{{ $partner->depth }}</dd>
        <dt class="col-sm-3">親BP</dt><dd class="col-sm-9">{{ $partner->parent ? $partner->parent->code.' / '.$partner->parent->name : '（ルート）' }}</dd>
        <dt class="col-sm-3">郵便番号</dt><dd class="col-sm-9">{{ $partner->postal_code ?: '—' }}</dd>
        <dt class="col-sm-3">住所</dt><dd class="col-sm-9">{{ $partner->address ?: '—' }}</dd>
        <dt class="col-sm-3">建物名</dt><dd class="col-sm-9">{{ $partner->building_name ?: '—' }}</dd>
        <dt class="col-sm-3">電話</dt><dd class="col-sm-9">{{ $partner->phone ?: '—' }}</dd>
        <dt class="col-sm-3">メール</dt><dd class="col-sm-9">{{ $partner->email ?: '—' }}</dd>
        <dt class="col-sm-3">2FA</dt><dd class="col-sm-9">{{ $partner->two_factor_mode?->label() ?? '—' }}</dd>
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $partner->is_active ? '有効' : '無効' }}</dd>
    </dl>

    <h2 class="h5 mt-4">直下の子BP</h2>
    <ul>
        @forelse ($partner->children as $child)
            <li><a href="{{ route(($routePrefix ?? 'admin').'.business-partners.show', $child) }}">{{ $child->code }} / {{ $child->name }}</a></li>
        @empty
            <li class="text-muted">なし</li>
        @endforelse
    </ul>

    <div class="card mt-4">
        <div class="card-body">
            <h2 class="h6">階層移動</h2>
            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="form-label small" for="move_parent_id">新しい親BP（未選択でルート化）</label>
                    <select id="move_parent_id" class="form-select form-select-sm" @if (($allowRootMove ?? true) === false) required @endif>
                        @if (($allowRootMove ?? true) === true)
                            <option value="">（ルート）</option>
                        @endif
                        @foreach ($moveCandidates as $candidate)
                            <option value="{{ $candidate->id }}" @selected($partner->parent_id === $candidate->id)>
                                {{ $candidate->code }} / {{ $candidate->name }}（階層{{ $candidate->depth }}）
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-warning btn-sm" id="bpMoveOpen">移動</button>
                </div>
            </div>
        </div>
    </div>

    <button type="button" class="btn btn-outline-danger btn-sm mt-4" id="bpDeleteOpen">削除</button>
    @endif

    @if ($activeTab === 'customers')
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>CN</th>
                    <th>カスタマー名</th>
                    <th>2FA</th>
                    <th>状態</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($bpCustomers as $customer)
                    <tr>
                        <td><code>{{ $customer->code }}</code></td>
                        <td>{{ $customer->name }}</td>
                        <td>{{ $customer->two_factor_mode?->label() ?? '—' }}</td>
                        <td>
                            @if ($customer->is_active)
                                <span class="badge text-bg-success">有効</span>
                            @else
                                <span class="badge text-bg-secondary">無効</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route($prefix.'.customers.show', $customer) }}" class="btn btn-outline-secondary btn-sm">詳細</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted text-center">カスタマーなし</td></tr>
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
                    <th>カスタマー</th>
                    <th>拠点</th>
                    <th>状態</th>
                    <th>申し込み日</th>
                    <th>サービス提供日</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($bpContracts as $contract)
                    <tr>
                        <td>
                            <a href="{{ route($prefix.'.contracts.show', $contract) }}">
                                <code>{{ $contract->code }}</code>
                            </a>
                        </td>
                        <td>{{ $contract->customer?->code ?? '—' }}</td>
                        <td>{{ $contract->site?->name ?? '—' }}</td>
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

    @if ($activeTab === 'users')
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
                @forelse ($bpUsers as $user)
                    <tr>
                        <td><code>{{ $user->login_id }}</code></td>
                        <td>{{ $user->name }}</td>
                        <td><code class="small">{{ $user->roles->pluck('code')->implode(', ') ?: '-' }}</code></td>
                        <td>
                            @if ($user->is_active)
                                <span class="badge text-bg-success">有効</span>
                            @else
                                <span class="badge text-bg-secondary">無効</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a
                                href="{{ route($prefix.'.users.edit', ['user' => $user, 'return_bp_id' => $partner->id]) }}"
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

    @if ($activeTab === 'tickets' && $canViewTickets)
        <p class="small text-muted mb-3">このBPが受領したチケットを閲覧できます（操作はできません）。</p>
        @include('admin.tickets._org_list', [
            'tickets' => $bpTickets,
            'inspectBack' => ['return_bp_id' => $partner->id],
        ])
    @endif

    @if ($activeTab === 'billing' && $canViewBilling && $billing)
        <p class="small text-muted mb-3">
            このBPおよび配下BPが管理する契約について、ルートBPからカスタマーへの請求と、キックバック（発行済・月次想定計算）を表示します。
        </p>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="small text-muted">カスタマー請求（発行済）</div>
                    <div class="fs-5 fw-semibold">{{ number_format($billing['invoice_totals']['total']) }} <span class="fs-6 fw-normal">円（税込）</span></div>
                    <div class="small text-muted">{{ $billing['invoice_totals']['count'] }} 件 / 税抜 {{ number_format($billing['invoice_totals']['subtotal']) }}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="small text-muted">キックバック月次想定（税込合計）</div>
                    <div class="fs-5 fw-semibold">{{ number_format($billing['kickback_preview_totals']['total']) }} <span class="fs-6 fw-normal">円</span></div>
                    <div class="small text-muted">税抜 {{ number_format($billing['kickback_preview_totals']['subtotal']) }} / 税 {{ number_format($billing['kickback_preview_totals']['tax_total']) }}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="small text-muted">キックバック発行済</div>
                    <div class="fs-5 fw-semibold">{{ number_format($billing['kickback_invoices']->total()) }} <span class="fs-6 fw-normal">件</span></div>
                </div>
            </div>
        </div>

        <h2 class="h5">カスタマー請求（ルート発行）</h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>請求番号</th>
                    <th>請求月</th>
                    <th>契約</th>
                    <th>カスタマー</th>
                    <th>管理BP</th>
                    <th>発行BP</th>
                    <th class="text-end">請求額（税込）</th>
                    <th class="text-end">入金額（税込）</th>
                    <th>状態</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($billing['invoices'] as $invoice)
                    <tr>
                        <td><a href="{{ route($prefix.'.invoices.show', $invoice) }}"><code>{{ $invoice->code }}</code></a></td>
                        <td>{{ $invoice->billing_year_month }}</td>
                        <td><code>{{ $invoice->contract?->code }}</code></td>
                        <td>{{ $invoice->customer?->name }}</td>
                        <td class="small">{{ $invoice->owningBp?->code }}</td>
                        <td class="small">{{ $invoice->issuerBp?->code }}</td>
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
                    <tr><td colspan="9" class="text-muted text-center py-4">請求はありません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $billing['invoices']->links() }}

        <h2 class="h5 mt-4">キックバック金額（月次想定・計算）</h2>
        <p class="small text-muted mb-2">稼働中契約のランニング品目について、価格レイヤから区間差額を計算した想定額です（開始月条件は未適用）。</p>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>契約</th>
                    <th>カスタマー</th>
                    <th>管理BP</th>
                    <th>支払BP</th>
                    <th>受取BP</th>
                    <th class="text-end">税抜</th>
                    <th class="text-end">税込</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($billing['kickback_preview'] as $row)
                    @if (! empty($row['error']))
                        <tr class="table-warning">
                            <td><code>{{ $row['contract']->code }}</code></td>
                            <td>{{ $row['customer']?->name }}</td>
                            <td colspan="6" class="small text-danger">{{ $row['error'] }}</td>
                        </tr>
                    @else
                        <tr @class(['table-info' => $row['involves_partner']])>
                            <td><code>{{ $row['contract']->code }}</code></td>
                            <td>{{ $row['customer']?->name }}</td>
                            <td class="small">{{ $row['owning_bp']?->code }}</td>
                            <td class="small">{{ $row['from_bp']?->code }} / {{ $row['from_bp']?->name }}</td>
                            <td class="small">{{ $row['to_bp']?->code }} / {{ $row['to_bp']?->name }}</td>
                            <td class="text-end">{{ number_format($row['subtotal']) }}</td>
                            <td class="text-end">{{ number_format($row['total']) }}</td>
                            <td class="small">
                                @if ($row['involves_partner'])
                                    <span class="badge text-bg-primary">自区間</span>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="text-muted text-center py-4">計算対象のキックバックはありません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <h2 class="h5">キックバック（発行済）</h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-striped align-middle">
                <thead>
                <tr>
                    <th>番号</th>
                    <th>請求月</th>
                    <th>契約</th>
                    <th>支払BP</th>
                    <th>受取BP</th>
                    <th class="text-end">請求額（税込）</th>
                    <th class="text-end">入金額（税込）</th>
                    <th>状態</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($billing['kickback_invoices'] as $kickback)
                    <tr>
                        <td><a href="{{ route($prefix.'.kickbacks.show', $kickback) }}"><code>{{ $kickback->code }}</code></a></td>
                        <td>{{ $kickback->billing_year_month }}</td>
                        <td><code>{{ $kickback->contract?->code }}</code></td>
                        <td class="small">{{ $kickback->fromBp?->code }}</td>
                        <td class="small">{{ $kickback->toBp?->code }}</td>
                        <td class="text-end">{{ number_format($kickback->total) }}</td>
                        <td class="text-end">
                            @if ($kickback->paid_amount !== null)
                                {{ number_format($kickback->paid_amount) }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            {{ $kickback->status->label() }}
                            @include('partials.kickback-payment-diff-badge', ['invoice' => $kickback, 'class' => 'ms-1'])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-muted text-center py-4">発行済キックバックはありません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $billing['kickback_invoices']->links() }}
    @endif

    <dialog id="bpMoveDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
        <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.business-partners.move', $partner) }}" class="p-4" id="bpMoveForm">
            @csrf
            @method('PUT')
            <input type="hidden" name="parent_id" id="bpMoveParentId" value="">
            <h2 class="h5 mb-3">階層移動の確認</h2>
            <p class="mb-2">「{{ $partner->code }} / {{ $partner->name }}」の親子関係を変更します。</p>
            <p class="mb-2 small" id="bpMoveTargetLabel"></p>
            <p class="mb-3">下の確認コードを入力してください。</p>
            <p class="text-center mb-3">
                <span class="display-6 fw-bold" id="bpMoveCode">{{ $moveConfirmationCode }}</span>
            </p>
            <div class="mb-3">
                <label class="form-label" for="bp_move_confirmation_code">確認コード</label>
                <input
                    type="text"
                    name="confirmation_code"
                    id="bp_move_confirmation_code"
                    class="form-control text-center"
                    inputmode="numeric"
                    maxlength="4"
                    pattern="[0-9]{4}"
                    autocomplete="off"
                    required
                >
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary" id="bpMoveCancel">キャンセル</button>
                <button type="submit" class="btn btn-warning" id="bpMoveSubmit" disabled>移動する</button>
            </div>
        </form>
    </dialog>

    <dialog id="bpDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
        <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.business-partners.destroy', $partner) }}" class="p-4" id="bpDeleteForm">
            @csrf
            @method('DELETE')
            <h2 class="h5 mb-3">BP削除の確認</h2>
            <p class="mb-2">「{{ $partner->code }} / {{ $partner->name }}」を削除します。</p>
            <p class="mb-3">下の確認コードを入力してください。</p>
            <p class="text-center mb-3">
                <span class="display-6 fw-bold" id="bpDeleteCode">{{ $deleteConfirmationCode }}</span>
            </p>
            <div class="mb-3">
                <label class="form-label" for="bp_delete_confirmation_code">確認コード</label>
                <input
                    type="text"
                    name="confirmation_code"
                    id="bp_delete_confirmation_code"
                    class="form-control text-center"
                    inputmode="numeric"
                    maxlength="4"
                    pattern="[0-9]{4}"
                    autocomplete="off"
                    required
                >
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary" id="bpDeleteCancel">キャンセル</button>
                <button type="submit" class="btn btn-danger" id="bpDeleteSubmit" disabled>削除する</button>
            </div>
        </form>
    </dialog>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const bindCodeDialog = ({
            dialog,
            openButton,
            cancelButton,
            codeDisplay,
            input,
            submit,
            onOpen,
        }) => {
            if (!dialog || !openButton || !input || !submit || !codeDisplay) {
                return;
            }

            const expected = () => codeDisplay.textContent.trim();
            const syncSubmit = () => {
                submit.disabled = input.value.trim() !== expected();
            };

            openButton.addEventListener('click', () => {
                if (onOpen && onOpen() === false) {
                    return;
                }
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
        };

        const parentSelect = document.getElementById('move_parent_id');
        const moveParentId = document.getElementById('bpMoveParentId');
        const moveTargetLabel = document.getElementById('bpMoveTargetLabel');

        bindCodeDialog({
            dialog: document.getElementById('bpMoveDialog'),
            openButton: document.getElementById('bpMoveOpen'),
            cancelButton: document.getElementById('bpMoveCancel'),
            codeDisplay: document.getElementById('bpMoveCode'),
            input: document.getElementById('bp_move_confirmation_code'),
            submit: document.getElementById('bpMoveSubmit'),
            onOpen: () => {
                if (!parentSelect || !moveParentId) {
                    return false;
                }
                if (parentSelect.required && !parentSelect.value) {
                    parentSelect.reportValidity();
                    return false;
                }
                moveParentId.value = parentSelect.value;
                if (moveTargetLabel) {
                    const selected = parentSelect.options[parentSelect.selectedIndex];
                    const label = selected ? selected.textContent.trim() : '（ルート）';
                    moveTargetLabel.textContent = '新しい親: ' + label;
                }
                return true;
            },
        });

        bindCodeDialog({
            dialog: document.getElementById('bpDeleteDialog'),
            openButton: document.getElementById('bpDeleteOpen'),
            cancelButton: document.getElementById('bpDeleteCancel'),
            codeDisplay: document.getElementById('bpDeleteCode'),
            input: document.getElementById('bp_delete_confirmation_code'),
            submit: document.getElementById('bpDeleteSubmit'),
        });
    });
</script>
@endpush
