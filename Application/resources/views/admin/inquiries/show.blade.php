@extends('layouts.app')

@section('title', '問い合わせ詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'customer' ? 'カスタマー' : 'BP') }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">{{ $inquiry->subject }}</h1>
        <a href="{{ route(($routePrefix ?? 'admin').'.inquiries.index') }}" class="btn btn-outline-secondary btn-sm">一覧へ</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <dl class="row mb-3">
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $inquiry->status->label() }}</dd>
        <dt class="col-sm-3">カスタマー</dt><dd class="col-sm-9">{{ $inquiry->customer ? $inquiry->customer->code.' / '.$inquiry->customer->name : '—' }}</dd>
        <dt class="col-sm-3">対応BP</dt><dd class="col-sm-9">{{ $inquiry->owningBp?->code }} / {{ $inquiry->owningBp?->name }}</dd>
        <dt class="col-sm-3">起票者</dt><dd class="col-sm-9">{{ $inquiry->openedBy?->name ?: $inquiry->openedBy?->login_id }}</dd>
    </dl>

    <div class="mb-3 d-flex gap-2 flex-wrap">
        @if (($canClose ?? false) && $inquiry->status->value !== 'closed')
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.inquiries.close', $inquiry) }}">
                @csrf
                <button class="btn btn-outline-secondary btn-sm" type="submit">クローズ</button>
            </form>
        @endif
        @if (($canReopen ?? false) && $inquiry->status->value === 'closed')
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.inquiries.reopen', $inquiry) }}">
                @csrf
                <button class="btn btn-outline-primary btn-sm" type="submit">再オープン</button>
            </form>
        @endif
        @if ($deleteConfirmationCode ?? null)
            <button type="button" class="btn btn-outline-danger btn-sm" id="inquiryDeleteOpen">削除</button>
        @endif
    </div>

    <h2 class="h5 mb-3">メッセージ</h2>
    <livewire:inquiry-chat :inquiry-id="$inquiry->id" :route-prefix="$routePrefix" :key="'inquiry-'.$inquiry->id" />

    @if ($deleteConfirmationCode ?? null)
        <dialog id="inquiryDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.inquiries.destroy', $inquiry) }}" class="p-4">
                @csrf
                @method('DELETE')
                <h2 class="h5 mb-3">問い合わせ削除の確認</h2>
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

@if ($deleteConfirmationCode ?? null)
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
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
@endif
