@extends('layouts.app')

@section('title', 'お知らせ詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'customer' ? 'カスタマー' : 'BP') }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'お知らせ', 'url' => route(($routePrefix ?? 'admin').'.announcements.index')]],
        'current' => 'お知らせ詳細',
    ])
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="ssp-page-title mb-0">{{ $announcement->title }}</h1>
        <div class="d-flex gap-2">
            @if ($canManage ?? false)
                <a href="{{ route(($routePrefix ?? 'admin').'.announcements.edit', $announcement) }}" class="btn btn-primary btn-sm">編集</a>
            @endif
        </div>
    </div>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <p class="text-muted small mb-3">
        公開開始: {{ $announcement->published_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
        ／ 公開終了:
        {{ $announcement->expires_at ? $announcement->expires_at->timezone(config('app.timezone'))->format('Y-m-d H:i') : '無期限' }}
        ／ 新規登録への配信: {{ $announcement->include_new_registrations ? 'する' : 'しない' }}
    </p>
    <p class="text-muted small mb-3">
        配信先:
        @foreach ($announcement->targets as $target)
            {{ $target->target_type->label() }}@if ($target->target_id)#{{ $target->target_id }}@endif
            @if (! $loop->last)、@endif
        @endforeach
    </p>
    <div class="mb-4" style="white-space: pre-wrap;">{{ $announcement->body }}</div>

    @if ($deleteConfirmationCode ?? null)
        <button type="button" class="btn btn-outline-danger btn-sm" id="announcementDeleteOpen">削除</button>
        <dialog id="announcementDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.announcements.destroy', $announcement) }}" class="p-4">
                @csrf
                @method('DELETE')
                <h2 class="h5 mb-3">お知らせ削除の確認</h2>
                <p class="mb-2">「{{ $announcement->title }}」を削除します。</p>
                <p class="mb-3">下の確認コードを入力してください。</p>
                <p class="text-center mb-3">
                    <span class="display-6 fw-bold" id="announcementDeleteCode">{{ $deleteConfirmationCode }}</span>
                </p>
                <div class="mb-3">
                    <label class="form-label" for="announcement_delete_confirmation_code">確認コード</label>
                    <input type="text" name="confirmation_code" id="announcement_delete_confirmation_code" class="form-control text-center" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="off" required>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="announcementDeleteCancel">キャンセル</button>
                    <button type="submit" class="btn btn-danger" id="announcementDeleteSubmit" disabled>削除する</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection

@if ($deleteConfirmationCode ?? null)
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dialog = document.getElementById('announcementDeleteDialog');
        const openButton = document.getElementById('announcementDeleteOpen');
        const cancelButton = document.getElementById('announcementDeleteCancel');
        const codeDisplay = document.getElementById('announcementDeleteCode');
        const input = document.getElementById('announcement_delete_confirmation_code');
        const submit = document.getElementById('announcementDeleteSubmit');
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
