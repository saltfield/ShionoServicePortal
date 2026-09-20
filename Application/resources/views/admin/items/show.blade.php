@extends('layouts.app')

@section('title', '品目詳細')
@section('area', '管理者')
@section('logout_action', route('admin.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">品目詳細</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.items.edit', $item) }}" class="btn btn-primary btn-sm">編集</a>
            <a href="{{ route('admin.items.index') }}" class="btn btn-outline-secondary btn-sm">一覧へ</a>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <dl class="row">
        <dt class="col-sm-3">コード</dt><dd class="col-sm-9"><code>{{ $item->code }}</code></dd>
        <dt class="col-sm-3">名称</dt><dd class="col-sm-9">{{ $item->name }}</dd>
        <dt class="col-sm-3">説明</dt><dd class="col-sm-9">{{ $item->description ?: '—' }}</dd>
        <dt class="col-sm-3">課金区分</dt><dd class="col-sm-9">{{ $item->billing_type->label() }}</dd>
        <dt class="col-sm-3">必須セット</dt>
        <dd class="col-sm-9">
            @if ($item->requiredItem)
                {{ $item->requiredItem->code }} / {{ $item->requiredItem->name }}
            @else
                —
            @endif
        </dd>
        <dt class="col-sm-3">標準仕切り</dt>
        <dd class="col-sm-9">@include('partials.price-display', ['amount' => $item->partition_price, 'taxRate' => $item->tax_rate])</dd>
        <dt class="col-sm-3">推奨価格</dt>
        <dd class="col-sm-9">@include('partials.price-display', ['amount' => $item->recommended_price, 'taxRate' => $item->tax_rate])</dd>
        <dt class="col-sm-3">ユーザー標準</dt>
        <dd class="col-sm-9">@include('partials.price-display', ['amount' => $item->user_price, 'taxRate' => $item->tax_rate])</dd>
        <dt class="col-sm-3">消費税率</dt><dd class="col-sm-9">{{ $item->tax_rate }}%</dd>
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $item->is_active ? '有効' : '無効' }}</dd>
    </dl>

    <h2 class="h5 mt-4">Documentテンプレート（最大{{ \App\Domains\Contract\Services\ContractService::MAX_ITEM_DOCUMENTS }}）</h2>
    <p class="text-muted small">テンプレート内の <code>&#123;&#123;code&#125;&#125;</code> は契約データ・予約語で置換され、PDF として保存されます。予約語例: <code>bp_name</code>, <code>c_name</code>, <code>price</code>, <code>tax</code>, <code>rate</code> など。</p>
    <div class="table-responsive mb-3" style="max-width:40rem">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>タイトル</th>
                    <th>ファイル</th>
                    <th class="text-end">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($item->documents as $doc)
                    <tr>
                        <td>{{ $doc->title }}</td>
                        <td><code class="small">{{ $doc->original_name }}</code></td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('admin.items.documents.download', $doc) }}" class="btn btn-outline-secondary btn-sm">DL</a>
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm item-document-delete-open"
                                data-action="{{ route('admin.items.documents.destroy', $doc) }}"
                                data-title="{{ $doc->title }}"
                                data-code="{{ $documentDeleteCodes[$doc->id] ?? '' }}"
                            >削除</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-muted">未登録</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($item->documents->count() < \App\Domains\Contract\Services\ContractService::MAX_ITEM_DOCUMENTS)
        <form method="POST" action="{{ route('admin.items.documents.store', $item) }}" enctype="multipart/form-data" class="mb-4" style="max-width:36rem">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label" for="title">タイトル</label>
                    <input type="text" name="title" id="title" class="form-control form-control-sm" required placeholder="開通案内 / 保証書 など">
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="file">ファイル</label>
                    <input
                        type="file"
                        name="file"
                        id="file"
                        class="form-control form-control-sm"
                        accept=".xls,.xlsx,.xml,.html,.htm,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/xml,application/xml,text/html"
                        required
                    >
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-primary btn-sm" type="submit">追加</button>
                </div>
            </div>
            <div class="form-text mt-1">Excel（.xls / .xlsx）、XML、HTML のみ</div>
        </form>
    @endif

    <button type="button" class="btn btn-outline-danger btn-sm" id="itemDeleteOpen">品目を削除</button>

    <dialog id="itemDocumentDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
        <form method="POST" id="itemDocumentDeleteForm" class="p-4">
            @csrf
            @method('DELETE')
            <h2 class="h5 mb-3">Documentテンプレート削除の確認</h2>
            <p class="mb-2">「<span id="itemDocumentDeleteTitle"></span>」を削除します。</p>
            <p class="mb-3">下の確認コードを入力してください。</p>
            <p class="text-center mb-3">
                <span class="display-6 fw-bold" id="itemDocumentDeleteCode"></span>
            </p>
            <div class="mb-3">
                <label class="form-label" for="item_document_delete_confirmation_code">確認コード</label>
                <input type="text" name="confirmation_code" id="item_document_delete_confirmation_code" class="form-control text-center" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="off" required>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary" id="itemDocumentDeleteCancel">キャンセル</button>
                <button type="submit" class="btn btn-danger" id="itemDocumentDeleteSubmit" disabled>削除する</button>
            </div>
        </form>
    </dialog>

    <dialog id="itemDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
        <form method="POST" action="{{ route('admin.items.destroy', $item) }}" class="p-4">
            @csrf
            @method('DELETE')
            <h2 class="h5 mb-3">品目削除の確認</h2>
            <p class="mb-2">「{{ $item->code }} / {{ $item->name }}」を削除します。</p>
            <p class="mb-3">下の確認コードを入力してください。</p>
            <p class="text-center mb-3">
                <span class="display-6 fw-bold" id="itemDeleteCode">{{ $deleteConfirmationCode }}</span>
            </p>
            <div class="mb-3">
                <label class="form-label" for="item_delete_confirmation_code">確認コード</label>
                <input type="text" name="confirmation_code" id="item_delete_confirmation_code" class="form-control text-center" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="off" required>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary" id="itemDeleteCancel">キャンセル</button>
                <button type="submit" class="btn btn-danger" id="itemDeleteSubmit" disabled>削除する</button>
            </div>
        </form>
    </dialog>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const bindCodeDialog = ({ dialog, openers, cancelButton, form, codeDisplay, titleDisplay, input, submit, useDataset }) => {
            if (!dialog || !input || !submit || !codeDisplay) return;

            const expected = () => codeDisplay.textContent.trim();
            const syncSubmit = () => { submit.disabled = input.value.trim() !== expected(); };

            openers.forEach((button) => {
                button.addEventListener('click', () => {
                    if (useDataset) {
                        if (form && button.dataset.action) {
                            form.setAttribute('action', button.dataset.action);
                        }
                        if (titleDisplay) {
                            titleDisplay.textContent = button.dataset.title ?? '';
                        }
                        codeDisplay.textContent = button.dataset.code ?? '';
                    }
                    input.value = '';
                    syncSubmit();
                    dialog.showModal();
                    input.focus();
                });
            });

            cancelButton?.addEventListener('click', () => dialog.close());
            dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
            input.addEventListener('input', () => {
                input.value = input.value.replace(/\D/g, '').slice(0, 4);
                syncSubmit();
            });
        };

        bindCodeDialog({
            dialog: document.getElementById('itemDocumentDeleteDialog'),
            openers: Array.from(document.querySelectorAll('.item-document-delete-open')),
            cancelButton: document.getElementById('itemDocumentDeleteCancel'),
            form: document.getElementById('itemDocumentDeleteForm'),
            codeDisplay: document.getElementById('itemDocumentDeleteCode'),
            titleDisplay: document.getElementById('itemDocumentDeleteTitle'),
            input: document.getElementById('item_document_delete_confirmation_code'),
            submit: document.getElementById('itemDocumentDeleteSubmit'),
            useDataset: true,
        });

        bindCodeDialog({
            dialog: document.getElementById('itemDeleteDialog'),
            openers: [document.getElementById('itemDeleteOpen')].filter(Boolean),
            cancelButton: document.getElementById('itemDeleteCancel'),
            form: null,
            codeDisplay: document.getElementById('itemDeleteCode'),
            titleDisplay: null,
            input: document.getElementById('item_delete_confirmation_code'),
            submit: document.getElementById('itemDeleteSubmit'),
            useDataset: false,
        });
    });
</script>
@endpush
