@extends('layouts.app')

@section('title', '拠点編集')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <h1 class="h3 mb-3">拠点編集</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.sites.update', $site) }}" class="card card-body" style="max-width:42rem">
        @csrf
        @method('PUT')
        @include('admin.sites._form', ['site' => $site])
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">保存</button>
            <button type="button" class="btn btn-outline-danger" id="siteDeleteOpen">削除</button>
        </div>
    </form>

    <dialog id="siteDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
        <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.sites.destroy', $site) }}" class="p-4" id="siteDeleteForm">
            @csrf
            @method('DELETE')
            <h2 class="h5 mb-3">拠点削除の確認</h2>
            <p class="mb-2">「{{ $site->name }}」を削除します。</p>
            <p class="mb-3">下の確認コードを入力してください。</p>
            <p class="text-center mb-3">
                <span class="display-6 fw-bold" id="siteDeleteCode">{{ $deleteConfirmationCode }}</span>
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
                <button type="button" class="btn btn-outline-secondary" id="siteDeleteCancel">キャンセル</button>
                <button type="submit" class="btn btn-danger" id="siteDeleteSubmit" disabled>削除する</button>
            </div>
        </form>
    </dialog>
@endsection

@push('scripts')
    @include('partials.postal-lookup-script')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dialog = document.getElementById('siteDeleteDialog');
        const openButton = document.getElementById('siteDeleteOpen');
        const cancelButton = document.getElementById('siteDeleteCancel');
        const codeDisplay = document.getElementById('siteDeleteCode');
        const input = document.getElementById('confirmation_code');
        const submit = document.getElementById('siteDeleteSubmit');

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
