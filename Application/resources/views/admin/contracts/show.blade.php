@extends('layouts.app')

@section('title', '契約詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'customer' ? 'カスタマー' : 'BP') }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">契約詳細</h1>
        <a href="{{ route(($routePrefix ?? 'admin').'.contracts.index') }}" class="btn btn-outline-secondary btn-sm">一覧へ</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <dl class="row">
        <dt class="col-sm-3">契約番号</dt><dd class="col-sm-9"><code>{{ $contract->code }}</code></dd>
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $contract->status->label() }}</dd>
        <dt class="col-sm-3">カスタマー</dt><dd class="col-sm-9">{{ $contract->customer?->code }} / {{ $contract->customer?->name }}</dd>
        <dt class="col-sm-3">拠点</dt><dd class="col-sm-9">{{ $contract->site?->name }}</dd>
        <dt class="col-sm-3">管理BP</dt><dd class="col-sm-9">{{ $contract->owningBp?->code }} / {{ $contract->owningBp?->name }}</dd>
    </dl>

    @if ($contract->status->value === 'draft' && ($routePrefix ?? 'admin') !== 'customer')
        <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.contracts.prices', $contract) }}" class="mb-4">
            @csrf
            @method('PUT')
            <h2 class="h5">明細・価格（承認前は変更可）</h2>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>品目</th>
                            <th>区分</th>
                            <th>税率</th>
                            <th>請求額（税別）</th>
                            <th>仕切り（税別）</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contract->items as $line)
                            <tr>
                                <td>{{ $line->item?->code }} / {{ $line->item?->name }}</td>
                                <td>{{ $line->item?->billing_type->label() }}</td>
                                <td>{{ $line->tax_rate }}%</td>
                                <td>
                                    <input type="number" step="1" min="0" name="prices[{{ $line->id }}][unit_price]" class="form-control form-control-sm js-tax-exclusive" data-tax-rate="{{ $line->tax_rate }}" data-inclusive-target="unit-inc-{{ $line->id }}" value="{{ (int) $line->unit_price }}">
                                    <div class="small text-muted" id="unit-inc-{{ $line->id }}"></div>
                                </td>
                                <td>
                                    <input type="number" step="1" min="0" name="prices[{{ $line->id }}][partition_price]" class="form-control form-control-sm js-tax-exclusive" data-tax-rate="{{ $line->tax_rate }}" data-inclusive-target="part-inc-{{ $line->id }}" value="{{ (int) $line->partition_price }}">
                                    <div class="small text-muted" id="part-inc-{{ $line->id }}"></div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary btn-sm" type="submit">価格保存</button>
            </div>
        </form>
        <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.contracts.submit-approval', $contract) }}" class="mb-3">
            @csrf
            <button class="btn btn-warning btn-sm" type="submit">親BPへ価格承認申請</button>
        </form>

        <button type="button" class="btn btn-outline-danger btn-sm mb-4" id="contractDeleteOpen">下書き削除</button>

        <dialog id="contractDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.contracts.destroy', $contract) }}" class="p-4">
                @csrf
                @method('DELETE')
                <h2 class="h5 mb-3">下書き削除の確認</h2>
                <p class="mb-2">「{{ $contract->code }}」を削除します。</p>
                <p class="mb-3">下の確認コードを入力してください。</p>
                <p class="text-center mb-3">
                    <span class="display-6 fw-bold" id="contractDeleteCode">{{ $deleteConfirmationCode }}</span>
                </p>
                <div class="mb-3">
                    <label class="form-label" for="contract_delete_confirmation_code">確認コード</label>
                    <input type="text" name="confirmation_code" id="contract_delete_confirmation_code" class="form-control text-center" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="off" required>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="contractDeleteCancel">キャンセル</button>
                    <button type="submit" class="btn btn-danger" id="contractDeleteSubmit" disabled>削除する</button>
                </div>
            </form>
        </dialog>
    @else
        <h2 class="h5">明細</h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>品目</th>
                        <th>区分</th>
                        <th>税率</th>
                        <th>請求額</th>
                        <th>仕切り</th>
                        <th>ロック</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($contract->items as $line)
                        <tr>
                            <td>{{ $line->item?->code }} / {{ $line->item?->name }}</td>
                            <td>{{ $line->item?->billing_type->label() }}</td>
                            <td>{{ $line->tax_rate }}%</td>
                            <td>@include('partials.price-display', ['amount' => $line->unit_price, 'taxRate' => $line->tax_rate])</td>
                            <td>@include('partials.price-display', ['amount' => $line->partition_price, 'taxRate' => $line->tax_rate])</td>
                            <td>{{ $line->price_locked ? '済' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (in_array($contract->status->value, ['approved', 'activated'], true) && ($routePrefix ?? '') === 'bp')
        <form method="POST" action="{{ route('bp.contracts.price-change', $contract) }}" class="card card-body mb-4">
            @csrf
            <h2 class="h6">価格変更申請（ロック後・税別）</h2>
            @foreach ($contract->items as $line)
                <div class="row g-2 mb-2 align-items-end">
                    <div class="col-md-3 small">{{ $line->item?->code }}（{{ $line->tax_rate }}%）</div>
                    <div class="col-md-3">
                        <input type="number" step="1" min="0" name="prices[{{ $line->id }}][unit_price]" class="form-control form-control-sm" value="{{ (int) $line->unit_price }}" placeholder="請求額（税別）">
                    </div>
                    <div class="col-md-3">
                        <input type="number" step="1" min="0" name="prices[{{ $line->id }}][partition_price]" class="form-control form-control-sm" value="{{ (int) $line->partition_price }}" placeholder="仕切り（税別）">
                    </div>
                </div>
            @endforeach
            <button class="btn btn-outline-warning btn-sm" type="submit">変更申請</button>
        </form>
    @endif

    @if ($contract->status->value === 'approved' && ($routePrefix ?? 'admin') !== 'customer')
        <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.contracts.activate', $contract) }}" class="mb-4">
            @csrf
            <button class="btn btn-success btn-sm" type="submit">開通する</button>
        </form>
    @endif

    @if (in_array($contract->status->value, ['approved', 'activated'], true) && ($routePrefix ?? 'admin') !== 'customer')
        <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.contracts.regenerate-documents', $contract) }}" class="mb-4">
            @csrf
            <button class="btn btn-outline-secondary btn-sm" type="submit">ドキュメント再生成（PDF上書き）</button>
        </form>
    @endif

    @if (in_array($contract->status->value, ['approved', 'activated'], true))
        <h2 class="h5 mt-4">データ</h2>
        @foreach ($contract->items as $line)
            <div class="card mb-3">
                <div class="card-body">
                    <h3 class="h6">{{ $line->item?->code }} / {{ $line->item?->name }}</h3>
                    @if (($routePrefix ?? '') !== 'customer')
                        <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.contracts.items.data', $line) }}">
                            @csrf
                            @method('PUT')
                            @php $existing = $line->dataRows; @endphp
                            @for ($i = 0; $i < max(3, $existing->count() + 1); $i++)
                                @php $row = $existing[$i] ?? null; @endphp
                                <div class="row g-2 mb-2">
                                    <div class="col-md-3">
                                        <select name="rows[{{ $i }}][data_field_name_id]" class="form-select form-select-sm js-data-field-select">
                                            <option value="">（直接入力）</option>
                                            @foreach ($dataFieldNames as $field)
                                                <option
                                                    value="{{ $field->id }}"
                                                    data-replace-code="{{ $field->replace_code }}"
                                                    data-name="{{ $field->name }}"
                                                    @selected((int) ($row?->data_field_name_id) === $field->id)
                                                >{{ $field->name }} ({{ $field->replace_code }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="text" name="rows[{{ $i }}][name]" class="form-control form-control-sm js-data-field-name" placeholder="名称" value="{{ $row?->name }}">
                                    </div>
                                    <div class="col-md-2">
                                        <input type="text" name="rows[{{ $i }}][replace_code]" class="form-control form-control-sm js-data-field-code" placeholder="置換コード" pattern="[a-z][a-z0-9_]*" value="{{ $row?->replace_code }}">
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" name="rows[{{ $i }}][value]" class="form-control form-control-sm" placeholder="値" value="{{ $row?->value }}">
                                    </div>
                                </div>
                            @endfor
                            <button class="btn btn-primary btn-sm" type="submit">データ保存</button>
                        </form>
                    @else
                        <ul class="mb-0">
                            @forelse ($line->dataRows as $row)
                                <li><strong>{{ $row->name }}</strong> <code>{{ $row->replace_code }}</code>: {{ $row->value }}</li>
                            @empty
                                <li class="text-muted">未登録</li>
                            @endforelse
                        </ul>
                    @endif

                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <h4 class="h6 mb-0">ドキュメント（PDF）</h4>
                    </div>
                    <ul class="mb-0">
                        @forelse ($line->documents as $doc)
                            <li>
                                <a href="{{ route(($routePrefix ?? 'admin').'.contracts.items.documents.download', [$line, $doc->id]) }}">
                                    {{ $doc->title }}
                                </a>
                                <span class="text-muted small">PDF</span>
                            </li>
                        @empty
                            <li class="text-muted">なし</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endforeach
    @endif

    <h2 class="h5 mt-4">ステータス履歴</h2>
    <ul>
        @foreach ($contract->statusHistories as $history)
            <li class="small">{{ $history->created_at }} : {{ $history->from_status ?? '—' }} → {{ $history->to_status }} {{ $history->note }}</li>
        @endforeach
    </ul>
@endsection

@push('scripts')
@if (($deleteConfirmationCode ?? null) && ($routePrefix ?? 'admin') !== 'customer')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dialog = document.getElementById('contractDeleteDialog');
        const openButton = document.getElementById('contractDeleteOpen');
        const cancelButton = document.getElementById('contractDeleteCancel');
        const codeDisplay = document.getElementById('contractDeleteCode');
        const input = document.getElementById('contract_delete_confirmation_code');
        const submit = document.getElementById('contractDeleteSubmit');
        if (!dialog || !openButton || !input || !submit || !codeDisplay) return;

        const expected = () => codeDisplay.textContent.trim();
        const syncSubmit = () => { submit.disabled = input.value.trim() !== expected(); };

        openButton.addEventListener('click', () => {
            input.value = '';
            syncSubmit();
            dialog.showModal();
            input.focus();
        });
        cancelButton?.addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
        input.addEventListener('input', () => {
            input.value = input.value.replace(/\D/g, '').slice(0, 4);
            syncSubmit();
        });
    });
</script>
@endif
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.js-tax-exclusive').forEach((input) => {
            const sync = () => {
                const rate = Number(input.dataset.taxRate || 10);
                const exclusive = Number(input.value || 0);
                const inclusive = Math.round(exclusive * (100 + rate) / 100);
                const target = document.getElementById(input.dataset.inclusiveTarget);
                if (target) {
                    target.textContent = '税込 ' + inclusive.toLocaleString('ja-JP');
                }
            };
            input.addEventListener('input', sync);
            sync();
        });

        document.querySelectorAll('.js-data-field-select').forEach((select) => {
            const row = select.closest('.row');
            const nameInput = row?.querySelector('.js-data-field-name');
            const codeInput = row?.querySelector('.js-data-field-code');
            const apply = () => {
                const option = select.selectedOptions[0];
                if (!option || !option.value) {
                    if (codeInput) codeInput.readOnly = false;
                    return;
                }
                if (nameInput) nameInput.value = option.dataset.name || '';
                if (codeInput) {
                    codeInput.value = option.dataset.replaceCode || '';
                    codeInput.readOnly = true;
                }
            };
            select.addEventListener('change', apply);
            apply();
        });
    });
</script>
@endpush
