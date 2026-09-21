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
        $bpUsers = $bpUsers ?? collect();
        $bpCustomers = $bpCustomers ?? collect();
        $bpTickets = $bpTickets ?? collect();
        $allowedTabs = ['overview'];
        if ($canViewCustomers) {
            $allowedTabs[] = 'customers';
        }
        if ($canManageUsers) {
            $allowedTabs[] = 'users';
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
            'users' => 'ユーザー管理',
            'tickets' => 'チケット',
        ];
    @endphp
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">BP詳細</h1>
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
            <a href="{{ route($prefix.'.business-partners.index') }}" class="btn btn-outline-secondary btn-sm">一覧へ</a>
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
        <dt class="col-sm-3">2FA</dt><dd class="col-sm-9">{{ $partner->two_factor_mode?->value }}</dd>
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
                        <td>{{ $customer->two_factor_mode?->value }}</td>
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
