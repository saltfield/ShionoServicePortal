@extends('layouts.app')

@section('title', 'カスタマー詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">カスタマー詳細</h1>
        <div class="d-flex gap-2">
            <a href="{{ route(($routePrefix ?? 'admin').'.customers.edit', $customer) }}" class="btn btn-primary btn-sm">編集</a>
            <a href="{{ route(($routePrefix ?? 'admin').'.sites.create', $customer) }}" class="btn btn-outline-primary btn-sm">拠点追加</a>
            <a href="{{ route(($routePrefix ?? 'admin').'.customers.index', ['managing_bp_id' => $customer->managing_bp_id]) }}" class="btn btn-outline-secondary btn-sm">一覧へ</a>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <dl class="row">
        <dt class="col-sm-3">CN</dt><dd class="col-sm-9"><code>{{ $customer->code }}</code></dd>
        <dt class="col-sm-3">カスタマー名</dt><dd class="col-sm-9">{{ $customer->name }}</dd>
        <dt class="col-sm-3">管理BP</dt><dd class="col-sm-9">{{ $customer->managingBp?->code }} / {{ $customer->managingBp?->name }}</dd>
        <dt class="col-sm-3">区分</dt><dd class="col-sm-9">{{ $customer->entity_type?->value }}</dd>
        <dt class="col-sm-3">2FA</dt><dd class="col-sm-9">{{ $customer->two_factor_mode?->value }}</dd>
        <dt class="col-sm-3">住所</dt><dd class="col-sm-9">{{ $customer->postal_code }} {{ $customer->address }} {{ $customer->building_name }}</dd>
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $customer->is_active ? '有効' : '無効' }}</dd>
    </dl>

    <h2 class="h5 mt-4">拠点</h2>
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
                    <td><a href="{{ route(($routePrefix ?? 'admin').'.sites.edit', $site) }}" class="btn btn-outline-secondary btn-sm">編集</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted text-center">拠点なし</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="d-flex gap-2 mb-3">
        <a href="{{ route(($routePrefix ?? 'admin').'.customers.prices.edit', $customer) }}" class="btn btn-outline-primary btn-sm">カスタマー価格</a>
        <button type="button" class="btn btn-outline-danger btn-sm" id="customerDeleteOpen">カスタマー削除</button>
    </div>

    <dialog id="customerDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
        <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.customers.destroy', $customer) }}" class="p-4" id="customerDeleteForm">
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
@endsection

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
