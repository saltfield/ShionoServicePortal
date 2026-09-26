@extends('layouts.app')

@section('title', 'チケット詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'customer' ? 'カスタマー' : 'BP') }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <nav class="ssp-breadcrumb" aria-label="breadcrumb">
        <ol class="breadcrumb small mb-2">
            @php
                $listRoute = ($listMode ?? 'received') === 'issued'
                    ? ($routePrefix ?? 'admin').'.tickets.issued'
                    : ($routePrefix ?? 'admin').'.tickets.received';
                $listLabel = ($listMode ?? 'received') === 'issued' ? '発行チケット' : '受領チケット';
            @endphp
            <li class="breadcrumb-item"><a href="{{ route($listRoute) }}">{{ $listLabel }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">チケット詳細</li>
        </ol>
    </nav>
    <div class="mb-3">
        <div class="small text-muted font-monospace mb-1">{{ $inquiry->code }}</div>
        <h1 class="ssp-page-title mb-0">{{ $inquiry->subject }}</h1>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <dl class="row mb-3">
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $inquiry->status->label() }}</dd>
        <dt class="col-sm-3">公開範囲</dt><dd class="col-sm-9">{{ $inquiry->visibility->label() }}</dd>
        <dt class="col-sm-3">カスタマー</dt><dd class="col-sm-9">{{ $inquiry->customer ? $inquiry->customer->code.' / '.$inquiry->customer->name : '—' }}</dd>
        <dt class="col-sm-3">対応先</dt>
        <dd class="col-sm-9">
            @if ($inquiry->assignee_type?->value === 'admin')
                管理者
            @else
                {{ $inquiry->assigneeBp?->code }} / {{ $inquiry->assigneeBp?->name }}
            @endif
        </dd>
        <dt class="col-sm-3">発行BP</dt><dd class="col-sm-9">{{ $inquiry->issuerBp ? $inquiry->issuerBp->code.' / '.$inquiry->issuerBp->name : '—' }}</dd>
        <dt class="col-sm-3">起票者</dt><dd class="col-sm-9">{{ $inquiry->openedBy?->name ?: $inquiry->openedBy?->login_id }}</dd>
    </dl>

    <div class="mb-3 d-flex gap-2 flex-wrap">
        @if ($canStartProgress ?? false)
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.tickets.start-progress', $inquiry) }}">
                @csrf
                <button class="btn btn-outline-primary btn-sm" type="submit">受領対応開始</button>
            </form>
        @endif
        @if ($canWithdraw ?? false)
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.tickets.withdraw', $inquiry) }}">
                @csrf
                <button class="btn btn-outline-warning btn-sm" type="submit">取下</button>
            </form>
        @endif
        @if ($canClose ?? false)
            <button type="button" class="btn btn-outline-secondary btn-sm" id="ticketCloseOpen">クローズ</button>
        @endif
        @if ($canReopen ?? false)
            <button type="button" class="btn btn-outline-primary btn-sm" id="ticketReopenOpen">再オープン</button>
        @endif
        @if ($deleteConfirmationCode ?? null)
            <button type="button" class="btn btn-outline-danger btn-sm" id="inquiryDeleteOpen">削除</button>
        @endif
    </div>

    <h2 class="h5 mb-3">メッセージ</h2>
    <livewire:inquiry-chat :inquiry-id="$inquiry->id" :route-prefix="$routePrefix" :key="'inquiry-'.$inquiry->id" />

    @if ($canClose ?? false)
        <dialog id="ticketCloseDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.tickets.close', $inquiry) }}" class="p-4">
                @csrf
                <h2 class="h5 mb-3">チケットクローズの確認</h2>
                <p class="mb-3">「{{ $inquiry->subject }}」をクローズします。よろしいですか？</p>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="ticketCloseCancel">キャンセル</button>
                    <button type="submit" class="btn btn-secondary">クローズする</button>
                </div>
            </form>
        </dialog>
    @endif

    @if ($canReopen ?? false)
        <dialog id="ticketReopenDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.tickets.reopen', $inquiry) }}" class="p-4">
                @csrf
                <h2 class="h5 mb-3">チケット再オープンの確認</h2>
                <p class="mb-3">「{{ $inquiry->subject }}」を再オープンします。よろしいですか？</p>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="ticketReopenCancel">キャンセル</button>
                    <button type="submit" class="btn btn-primary">再オープンする</button>
                </div>
            </form>
        </dialog>
    @endif

    @if ($deleteConfirmationCode ?? null)
        <dialog id="inquiryDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.tickets.destroy', $inquiry) }}" class="p-4">
                @csrf
                @method('DELETE')
                <h2 class="h5 mb-3">チケット削除の確認</h2>
                <p class="mb-2">「{{ $inquiry->subject }}」を削除します。</p>
                <p class="mb-3">下の確認コードを入力してください。</p>
                <p class="text-center mb-3">
                    <span class="display-6 fw-bold" id="inquiryDeleteCode">{{ $deleteConfirmationCode }}</span>
                </p>
                <div class="mb-3">
                    <label class="form-label" for="inquiry_delete_confirmation_code">確認コード</label>
                    <input type="text" name="confirmation_code" id="inquiry_delete_confirmation_code" class="form-control text-center" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="off" required>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="inquiryDeleteCancel">キャンセル</button>
                    <button type="submit" class="btn btn-danger" id="inquiryDeleteSubmit" disabled>削除する</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const closeDialog = document.getElementById('ticketCloseDialog');
        const closeOpen = document.getElementById('ticketCloseOpen');
        const closeCancel = document.getElementById('ticketCloseCancel');
        if (closeDialog && closeOpen) {
            closeOpen.addEventListener('click', () => closeDialog.showModal());
            closeCancel?.addEventListener('click', () => closeDialog.close());
            closeDialog.addEventListener('click', (event) => {
                if (event.target === closeDialog) closeDialog.close();
            });
        }

        const reopenDialog = document.getElementById('ticketReopenDialog');
        const reopenOpen = document.getElementById('ticketReopenOpen');
        const reopenCancel = document.getElementById('ticketReopenCancel');
        if (reopenDialog && reopenOpen) {
            reopenOpen.addEventListener('click', () => reopenDialog.showModal());
            reopenCancel?.addEventListener('click', () => reopenDialog.close());
            reopenDialog.addEventListener('click', (event) => {
                if (event.target === reopenDialog) reopenDialog.close();
            });
        }

        const dialog = document.getElementById('inquiryDeleteDialog');
        const openButton = document.getElementById('inquiryDeleteOpen');
        const cancelButton = document.getElementById('inquiryDeleteCancel');
        const codeDisplay = document.getElementById('inquiryDeleteCode');
        const input = document.getElementById('inquiry_delete_confirmation_code');
        const submit = document.getElementById('inquiryDeleteSubmit');
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
