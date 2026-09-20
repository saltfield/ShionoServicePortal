@extends('layouts.app')

@section('title', 'ユーザー管理')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $hideAdminTab = $hideAdminTab ?? false;
        $hidePrivilegeActions = $hidePrivilegeActions ?? false;
        $tabs = [];
        if (! $hideAdminTab) {
            $tabs['admin'] = '管理者';
        }
        $tabs['bp'] = 'BP';
        $tabs['customer'] = 'カスタマー';
        $isBpTab = $tab === 'bp';
        $isCustomerTab = $tab === 'customer';
        $showCustomerList = ! $isCustomerTab || $filters['managing_bp_id'] || $prefix === 'bp';
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">ユーザー管理</h1>
        <div class="d-flex gap-2">
            @if ($isBpTab)
                <a href="{{ route($prefix.'.users.create', ['type' => 'bp']) }}" class="btn btn-primary btn-sm">新規作成</a>
            @elseif ($isCustomerTab)
                <a href="{{ route($prefix.'.users.create', ['type' => 'customer']) }}" class="btn btn-primary btn-sm">新規作成</a>
            @elseif ($tab === 'admin' && $prefix === 'admin')
                <a href="{{ route($prefix.'.users.create', ['type' => 'admin']) }}" class="btn btn-primary btn-sm">新規作成</a>
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
        @foreach ($tabs as $key => $label)
            <li class="nav-item">
                <a class="nav-link @if ($tab === $key) active @endif" href="{{ route($prefix.'.users.index', ['tab' => $key]) }}">
                    {{ $label }}
                    <span class="badge text-bg-secondary">{{ $counts[$key] ?? 0 }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    @if ($isBpTab && $prefix === 'admin')
        <form method="GET" action="{{ route($prefix.'.users.index') }}" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="tab" value="bp">
            <div class="col-md-3">
                <label for="bpn" class="form-label small mb-1">BPN</label>
                <input type="text" id="bpn" name="bpn" value="{{ $filters['bpn'] }}" class="form-control form-control-sm" placeholder="例: BPN202609">
            </div>
            <div class="col-md-4">
                <label for="bp_name" class="form-label small mb-1">BP名</label>
                <input type="text" id="bp_name" name="bp_name" value="{{ $filters['bp_name'] }}" class="form-control form-control-sm" placeholder="部分一致">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">検索</button>
                <a href="{{ route($prefix.'.users.index', ['tab' => 'bp']) }}" class="btn btn-outline-secondary btn-sm">クリア</a>
            </div>
        </form>
    @elseif ($isCustomerTab && $prefix === 'admin')
        <form method="GET" action="{{ route($prefix.'.users.index') }}" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="tab" value="customer">
            <div class="col-md-4">
                <label for="managing_bp_id" class="form-label small mb-1">管理BP <span class="text-danger">*</span></label>
                <select id="managing_bp_id" name="managing_bp_id" class="form-select form-select-sm" required>
                    <option value="">選択してください</option>
                    @foreach ($managingPartners as $partner)
                        <option value="{{ $partner->id }}" @selected((int) $filters['managing_bp_id'] === $partner->id)>
                            {{ $partner->code }} / {{ $partner->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="cn" class="form-label small mb-1">CN</label>
                <input type="text" id="cn" name="cn" value="{{ $filters['cn'] }}" class="form-control form-control-sm" placeholder="例: CN202609" @disabled(! $filters['managing_bp_id'])>
            </div>
            <div class="col-md-3">
                <label for="cn_name" class="form-label small mb-1">カスタマー名</label>
                <input type="text" id="cn_name" name="cn_name" value="{{ $filters['cn_name'] }}" class="form-control form-control-sm" placeholder="部分一致" @disabled(! $filters['managing_bp_id'])>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">表示</button>
                <a href="{{ route($prefix.'.users.index', ['tab' => 'customer']) }}" class="btn btn-outline-secondary btn-sm">クリア</a>
            </div>
        </form>
        @unless ($filters['managing_bp_id'])
            <p class="text-muted small mb-3">管理BPを選択すると、配下のカスタマーユーザーが表示されます。</p>
        @endunless
    @endif

    @if ($showCustomerList)
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>ログインID</th>
                <th>氏名</th>
                @if ($isBpTab)
                    <th>BPN</th>
                    <th>BP名</th>
                @elseif ($isCustomerTab)
                    <th>CN</th>
                    <th>カスタマー名</th>
                    <th>管理BP名</th>
                @endif
                <th>ロール</th>
                <th>状態</th>
                <th>2FA</th>
                <th>PW強制変更</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($users as $user)
                @php
                    $bp = $user->businessPartner ?? $user->customer?->managingBp;
                    $orgCode = $isCustomerTab ? ($user->customer?->code ?? '-') : ($bp?->code ?? '-');
                    $orgName = $isCustomerTab ? ($user->customer?->name ?? '-') : ($bp?->name ?? '-');
                    $managingBpName = $bp?->name ?? '-';
                    $orgCodeLabel = $isCustomerTab ? 'CN' : 'BPN';
                    $orgNameLabel = $isCustomerTab ? 'カスタマー名' : 'BP名';
                    $roleCode = $user->roles->first()?->code ?? '-';
                @endphp
                <tr>
                    <td><code>{{ $user->login_id }}</code></td>
                    <td>{{ $user->name }}</td>
                    @if ($isBpTab)
                        <td>{{ $orgCode }}</td>
                        <td>{{ $orgName }}</td>
                    @elseif ($isCustomerTab)
                        <td>{{ $orgCode }}</td>
                        <td>{{ $orgName }}</td>
                        <td>{{ $managingBpName }}</td>
                    @endif
                    <td><code>{{ $roleCode }}</code></td>
                    <td>
                        @if ($user->is_active)
                            <span class="badge text-bg-success">有効</span>
                        @else
                            <span class="badge text-bg-secondary">無効</span>
                        @endif
                    </td>
                    <td>
                        @if ($user->two_factor_forced_disabled)
                            <span class="badge text-bg-warning">緊急スキップ</span>
                        @elseif ($user->two_factor_confirmed_at)
                            <span class="badge text-bg-success">有効</span>
                        @else
                            <span class="badge text-bg-secondary">未設定</span>
                        @endif
                    </td>
                    <td>
                        @if ($user->must_change_password)
                            <span class="badge text-bg-danger">ON</span>
                        @else
                            <span class="text-muted">OFF</span>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        <a href="{{ route($prefix.'.users.edit', $user) }}" class="btn btn-outline-secondary btn-sm">編集</a>

                        @unless ($hidePrivilegeActions)
                            <button
                                type="button"
                                class="btn btn-outline-primary btn-sm js-privilege-confirm"
                                data-action="{{ route('admin.users.force-password', $user) }}"
                                data-title="PW強制変更の確認"
                                data-description="次のユーザーにパスワード強制変更を設定します。次回ログイン時にパスワード変更画面へ誘導されます。"
                                data-org-code-label="{{ $orgCodeLabel }}"
                                data-org-name-label="{{ $orgNameLabel }}"
                                data-org-code="{{ $orgCode }}"
                                data-org-name="{{ $orgName }}"
                                data-managing-bp-name="{{ $isCustomerTab ? $managingBpName : '' }}"
                                data-user-name="{{ $user->name }}"
                                data-confirm-label="PW強制変更を実行"
                                data-confirm-class="btn-primary"
                            >PW強制変更</button>

                            @if ($user->two_factor_forced_disabled)
                                <form method="POST" action="{{ route('admin.users.clear-reset-2fa', $user) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-secondary btn-sm" type="submit">2FA復帰</button>
                                </form>
                            @else
                                <button
                                    type="button"
                                    class="btn btn-outline-warning btn-sm js-privilege-confirm"
                                    data-action="{{ route('admin.users.reset-2fa', $user) }}"
                                    data-title="2FA緊急スキップの確認"
                                    data-description="次のユーザーの2FAを緊急スキップに設定します。次回ログインからTOTP入力が省略されます。"
                                    data-org-code-label="{{ $orgCodeLabel }}"
                                    data-org-name-label="{{ $orgNameLabel }}"
                                    data-org-code="{{ $orgCode }}"
                                    data-org-name="{{ $orgName }}"
                                    data-managing-bp-name="{{ $isCustomerTab ? $managingBpName : '' }}"
                                    data-user-name="{{ $user->name }}"
                                    data-confirm-label="2FA緊急スキップを実行"
                                    data-confirm-class="btn-warning"
                                >2FA緊急スキップ</button>
                            @endif
                        @endunless
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $isCustomerTab ? 10 : ($isBpTab ? 9 : 7) }}" class="text-muted text-center py-4">該当するユーザーがありません。</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}
    @endif

    @unless ($hidePrivilegeActions)
    <dialog id="privilegeConfirmDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 32rem; width: calc(100% - 2rem);">
        <div class="p-4">
            <h2 class="h5 mb-3" id="privilegeConfirmTitle">確認</h2>
            <p class="mb-2" id="privilegeConfirmDescription"></p>
            <ul class="mb-3" id="privilegeConfirmTargets"></ul>
            <p class="mb-4 text-muted small">実行してよろしいですか？</p>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary" id="privilegeConfirmCancel">キャンセル</button>
                <form method="POST" id="privilegeConfirmForm" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-primary" id="privilegeConfirmSubmit">実行</button>
                </form>
            </div>
        </div>
    </dialog>
    @endunless
@endsection

@unless ($hidePrivilegeActions ?? false)
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dialog = document.getElementById('privilegeConfirmDialog');
        const title = document.getElementById('privilegeConfirmTitle');
        const description = document.getElementById('privilegeConfirmDescription');
        const targets = document.getElementById('privilegeConfirmTargets');
        const form = document.getElementById('privilegeConfirmForm');
        const submit = document.getElementById('privilegeConfirmSubmit');
        const cancel = document.getElementById('privilegeConfirmCancel');

        if (!dialog) {
            return;
        }

        const appendItem = (label, value) => {
            const item = document.createElement('li');
            item.textContent = `${label}: ${value}`;
            targets.appendChild(item);
        };

        document.querySelectorAll('.js-privilege-confirm').forEach((button) => {
            button.addEventListener('click', () => {
                title.textContent = button.dataset.title;
                description.textContent = button.dataset.description;
                targets.replaceChildren();
                appendItem(button.dataset.orgCodeLabel, button.dataset.orgCode);
                appendItem(button.dataset.orgNameLabel, button.dataset.orgName);
                if (button.dataset.managingBpName) {
                    appendItem('管理BP名', button.dataset.managingBpName);
                }
                appendItem('ユーザー名', button.dataset.userName);
                form.action = button.dataset.action;
                submit.textContent = button.dataset.confirmLabel;
                submit.className = `btn ${button.dataset.confirmClass}`;
                dialog.showModal();
            });
        });

        cancel.addEventListener('click', () => dialog.close());

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });
</script>
@endpush
@endunless
