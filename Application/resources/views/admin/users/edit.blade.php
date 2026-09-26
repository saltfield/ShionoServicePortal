@extends('layouts.app')

@section('title', 'ユーザー編集')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        if ($returnCustomerId ?? null) {
            $backUrl = route($prefix.'.customers.show', ['customer' => $returnCustomerId, 'tab' => 'users']);
            $crumbs = [
                ['label' => 'カスタマー詳細', 'url' => route($prefix.'.customers.show', $returnCustomerId)],
                ['label' => 'ユーザー管理', 'url' => $backUrl],
            ];
        } elseif ($returnBpId ?? null) {
            $backUrl = route($prefix.'.business-partners.show', ['businessPartner' => $returnBpId, 'tab' => 'users']);
            $crumbs = [
                ['label' => 'BP詳細', 'url' => route($prefix.'.business-partners.show', $returnBpId)],
                ['label' => 'ユーザー管理', 'url' => $backUrl],
            ];
        } else {
            $backUrl = route($prefix.'.users.index', $prefix === 'bp' ? [] : ['tab' => $managedUser->user_type->value]);
            $crumbs = [
                ['label' => 'ユーザー管理', 'url' => $backUrl],
            ];
        }
    @endphp
    <nav class="ssp-breadcrumb" aria-label="breadcrumb">
        <ol class="breadcrumb small mb-2">
            @foreach ($crumbs as $crumb)
                <li class="breadcrumb-item"><a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a></li>
            @endforeach
            <li class="breadcrumb-item active" aria-current="page">ユーザー編集</li>
        </ol>
    </nav>
    <h1 class="ssp-page-title mb-3">ユーザー編集</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route($prefix.'.users.update', $managedUser) }}" class="card card-body mb-4" style="max-width:40rem">
        @csrf
        @method('PUT')
        @if ($returnCustomerId ?? null)
            <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
        @endif
        @if ($returnBpId ?? null)
            <input type="hidden" name="return_bp_id" value="{{ $returnBpId }}">
        @endif
        <div class="mb-3">
            <label class="form-label">ログインID</label>
            <input type="text" class="form-control" value="{{ $managedUser->login_id }}" disabled>
        </div>
        <div class="mb-3">
            <label class="form-label">種別</label>
            <input type="text" class="form-control" value="{{ $managedUser->user_type->value }}" disabled>
        </div>
        <div class="mb-3">
            <label class="form-label" for="name">氏名</label>
            <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $managedUser->name) }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="email">メール</label>
            <input type="email" name="email" id="email" class="form-control" value="{{ old('email', $managedUser->email) }}">
        </div>
        <div class="mb-3">
            <label class="form-label" for="role_code">ロール</label>
            <select name="role_code" id="role_code" class="form-select" required>
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected(old('role_code', $managedUser->roles->first()?->code) === $role)>{{ $role }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">パスワード（変更時のみ）</label>
            <input type="password" name="password" id="password" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label" for="password_confirmation">パスワード（確認）</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control">
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="must_change_password" @checked(old('must_change_password', false))>
            <label class="form-check-label" for="must_change_password">次回ログインでパスワード変更を要求</label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $managedUser->is_active))>
            <label class="form-check-label" for="is_active">有効</label>
        </div>
        <button class="btn btn-primary" type="submit">保存</button>
    </form>

    <div class="d-flex gap-2">
        @if ($deleteConfirmationCode ?? null)
            <button type="button" class="btn btn-outline-danger btn-sm" id="userDeleteOpen">削除</button>
        @endif
        <a href="{{ $backUrl }}" class="btn btn-outline-secondary btn-sm">キャンセル</a>
    </div>
    @if ($deleteConfirmationCode ?? null)
        <dialog id="userDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route($prefix.'.users.destroy', $managedUser) }}" class="p-4">
                @csrf
                @method('DELETE')
                @if ($returnCustomerId ?? null)
                    <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
                @endif
                @if ($returnBpId ?? null)
                    <input type="hidden" name="return_bp_id" value="{{ $returnBpId }}">
                @endif
                <h2 class="h5 mb-3">ユーザー削除の確認</h2>
                <p class="mb-2">「{{ $managedUser->login_id }}」を削除します。</p>
                <p class="mb-3">下の確認コードを入力してください。</p>
                <p class="text-center mb-3"><span class="display-6 fw-bold" id="userDeleteCode">{{ $deleteConfirmationCode }}</span></p>
                <div class="mb-3">
                    <label class="form-label" for="user_delete_confirmation_code">確認コード</label>
                    <input type="text" name="confirmation_code" id="user_delete_confirmation_code" class="form-control text-center" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="off" required>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="userDeleteCancel">キャンセル</button>
                    <button type="submit" class="btn btn-danger" id="userDeleteSubmit" disabled>削除する</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection

@if ($deleteConfirmationCode ?? null)
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dialog = document.getElementById('userDeleteDialog');
        const openButton = document.getElementById('userDeleteOpen');
        const cancelButton = document.getElementById('userDeleteCancel');
        const codeDisplay = document.getElementById('userDeleteCode');
        const input = document.getElementById('user_delete_confirmation_code');
        const submit = document.getElementById('userDeleteSubmit');
        if (!dialog || !openButton || !input || !submit || !codeDisplay) return;
        const expected = () => codeDisplay.textContent.trim();
        const syncSubmit = () => { submit.disabled = input.value.trim() !== expected(); };
        openButton.addEventListener('click', () => { input.value = ''; syncSubmit(); dialog.showModal(); input.focus(); });
        cancelButton?.addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
        input.addEventListener('input', () => {
            input.value = input.value.replace(/\D/g, '').slice(0, 4);
            syncSubmit();
        });
    });
</script>
@endpush
@endif
