@extends('layouts.app')

@section('title', '品目詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $manageItem = $prefix === 'admin' || ($canManageItem ?? false);
        $manageDocs = $prefix === 'admin' || ($canManageDocuments ?? false);
        $actorBp = $actorBpId ?? null;
        $docCountForLimit = $prefix === 'bp'
            ? ($ownDocumentCount ?? 0)
            : $item->documents->count();
    @endphp
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => '品目', 'url' => route($prefix.'.items.index')]],
        'current' => '品目詳細',
    ])
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">品目詳細</h1>
        <div class="d-flex gap-2">
            @if ($manageItem)
                <a href="{{ route($prefix.'.items.edit', $item) }}" class="btn btn-primary btn-sm">編集</a>
            @endif
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
        @if ($prefix === 'bp')
            <dt class="col-sm-3">種別</dt>
            <dd class="col-sm-9">{{ $item->owning_bp_id === null ? '標準品目' : 'BP独自サービス' }}</dd>
        @endif
        <dt class="col-sm-3">説明</dt><dd class="col-sm-9">{{ $item->description ?: '—' }}</dd>
        <dt class="col-sm-3">課金区分</dt><dd class="col-sm-9">{{ $item->billing_type->label() }}</dd>
        <dt class="col-sm-3">品目種別</dt>
        <dd class="col-sm-9">
            @if ($item->itemType)
                {{ $item->itemType->name }}
                @if ($item->itemType->hasGuideMessage())
                    <div class="small text-muted mt-1" style="white-space:pre-wrap">{{ $item->itemType->message }}</div>
                @endif
            @else
                <span class="text-muted">未設定</span>
            @endif
        </dd>
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
        <dt class="col-sm-3">最低利用期間</dt>
        <dd class="col-sm-9">{{ $item->minimum_term_months ? $item->minimum_term_months.'か月' : '—' }}</dd>
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $item->is_active ? '有効' : '無効' }}</dd>
    </dl>

    @if ($prefix === 'bp' && ($canEditWholesale ?? false))
        <h2 class="h5 mt-4">子BP向け仕切り</h2>
        @if (($buyers ?? collect())->isEmpty())
            <p class="text-muted small">直接の子BPがいないため、仕切りは設定できません。</p>
        @else
            <div class="table-responsive mb-3" style="max-width:40rem">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>子BP</th>
                            <th>現在の仕切り</th>
                            <th>設定</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($buyers as $buyer)
                            @php
                                $existing = $wholesaleByBuyer[$buyer->id] ?? null;
                                $current = $existing?->amount ?? $item->partition_price;
                            @endphp
                            <tr>
                                <td>{{ $buyer->code }} / {{ $buyer->name }}</td>
                                <td>@include('partials.price-display', ['amount' => $current, 'taxRate' => $item->tax_rate])</td>
                                <td>
                                    <form method="POST" action="{{ route('bp.items.wholesale.store', $item) }}" class="d-flex gap-2 align-items-center">
                                        @csrf
                                        <input type="hidden" name="buyer_bp_id" value="{{ $buyer->id }}">
                                        <input type="number" step="1" min="0" name="amount" class="form-control form-control-sm" style="width:8rem" value="{{ $current }}" required>
                                        <button class="btn btn-outline-primary btn-sm" type="submit">保存</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="form-text">未設定時は標準仕切りが適用されます。</p>
        @endif
    @endif

    <h2 class="h5 mt-4">Documentテンプレート（最大{{ \App\Domains\Contract\Services\ContractService::MAX_ITEM_DOCUMENTS }}）</h2>
    <p class="text-muted small">
        テンプレート内の <code>&#123;&#123;code&#125;&#125;</code> は契約データ・予約語で置換され、PDF として保存されます。
        サービス提供後でもテンプレートは削除できます（発行済みPDFは契約に残ります）。再生成は「現在のテンプレート」だけで PDF を追加・更新します。
        @if ($prefix === 'bp')
            標準テンプレートの削除は管理者のみです。BP独自テンプレートはこちらから削除できます。
        @endif
    </p>
    <div class="table-responsive mb-3" style="max-width:40rem">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>タイトル</th>
                    @if ($prefix === 'bp')
                        <th>種別</th>
                    @endif
                    <th>ファイル</th>
                    <th class="text-end">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($item->documents as $doc)
                    @php
                        $isOwnDoc = $prefix === 'admin' || ($actorBp !== null && (int) $doc->owning_bp_id === (int) $actorBp);
                    @endphp
                    <tr>
                        <td>{{ $doc->title }}</td>
                        @if ($prefix === 'bp')
                            <td>{{ $doc->owning_bp_id === null ? '標準' : '独自' }}</td>
                        @endif
                        <td><code class="small">{{ $doc->original_name }}</code></td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route($prefix.'.items.documents.download', $doc) }}" class="btn btn-outline-secondary btn-sm">DL</a>
                            @if ($isOwnDoc && $manageDocs)
                                <button
                                    type="button"
                                    class="btn btn-outline-danger btn-sm item-document-delete-open"
                                    data-action="{{ route($prefix.'.items.documents.destroy', $doc) }}"
                                    data-title="{{ $doc->title }}"
                                    data-code="{{ $documentDeleteCodes[$doc->id] ?? '' }}"
                                >削除</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $prefix === 'bp' ? 4 : 3 }}" class="text-muted">未登録</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($manageDocs && $docCountForLimit < \App\Domains\Contract\Services\ContractService::MAX_ITEM_DOCUMENTS)
        <form method="POST" action="{{ route($prefix.'.items.documents.store', $item) }}" enctype="multipart/form-data" class="mb-4" style="max-width:36rem">
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

    @if ($manageItem)
        <button type="button" class="btn btn-outline-danger btn-sm" id="itemDeleteOpen">品目を削除</button>
    @endif

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

    @if ($manageItem)
        <dialog id="itemDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route($prefix.'.items.destroy', $item) }}" class="p-4">
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
    @endif
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
