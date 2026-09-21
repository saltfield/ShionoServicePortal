@extends('layouts.app')

@section('title', '契約詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'customer' ? 'カスタマー' : 'BP') }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $isCustomer = $prefix === 'customer';
        $activeTab = $activeTab ?? 'overview';
        $canPostMessages = $canPostMessages ?? false;
        $returnCustomerId = $returnCustomerId ?? null;
        $tabLabels = [
            'overview' => '概要',
            'items' => '明細・価格',
            'data' => 'データ',
            'messages' => 'メッセージ',
            'history' => '履歴',
        ];
        if ($isCustomer) {
            $indexRoute = route('customer.contracts.index');
            $indexLabel = '一覧へ';
        } elseif ($returnCustomerId) {
            $indexRoute = route($prefix.'.customers.show', ['customer' => $returnCustomerId, 'tab' => 'contracts']);
            $indexLabel = 'カスタマー詳細へ';
        } else {
            $indexRoute = route($prefix.'.contracts.index');
            $indexLabel = '一覧へ';
        }
        $showQuery = array_filter([
            'return_customer_id' => $returnCustomerId,
        ]);
        $status = $contract->status->value;
        $documentDeleteCodes = $documentDeleteCodes ?? [];
        $documentFileMissing = $documentFileMissing ?? [];
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-0">契約詳細</h1>
            <p class="text-muted small mb-0 mt-1">
                <code>{{ $contract->code }}</code> / {{ $contract->status->label() }}
            </p>
        </div>
        <a href="{{ $indexRoute }}" class="btn btn-outline-secondary btn-sm">{{ $indexLabel }}</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <ul class="nav nav-tabs mb-3">
        @foreach ($tabLabels as $tabKey => $label)
            <li class="nav-item">
                <a
                    class="nav-link @if ($activeTab === $tabKey) active @endif"
                    href="{{ route($prefix.'.contracts.show', array_merge(['contract' => $contract, 'tab' => $tabKey], $showQuery)) }}"
                >{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    @if ($activeTab === 'overview')
        <dl class="row mb-0">
            <dt class="col-sm-3">契約番号</dt><dd class="col-sm-9"><code>{{ $contract->code }}</code></dd>
            <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $contract->status->label() }}</dd>
            <dt class="col-sm-3">カスタマー</dt><dd class="col-sm-9">{{ $contract->customer?->code }} / {{ $contract->customer?->name }}</dd>
            <dt class="col-sm-3">拠点</dt><dd class="col-sm-9">{{ $contract->site?->name }}</dd>
            <dt class="col-sm-3">管理BP</dt><dd class="col-sm-9">{{ $contract->owningBp?->code }} / {{ $contract->owningBp?->name }}</dd>
            <dt class="col-sm-3">初回請求月</dt>
            <dd class="col-sm-9">
                @if ($contract->first_billing_year_month)
                    {{ \Illuminate\Support\Carbon::createFromFormat('Ym', $contract->first_billing_year_month)->format('Y年n月') }}
                    <span class="text-muted small">({{ $contract->first_billing_year_month }})</span>
                @else
                    —
                @endif
            </dd>
            <dt class="col-sm-3">最終請求月</dt>
            <dd class="col-sm-9">
                @if ($contract->final_billing_year_month)
                    {{ \Illuminate\Support\Carbon::createFromFormat('Ym', $contract->final_billing_year_month)->format('Y年n月') }}
                    <span class="text-muted small">({{ $contract->final_billing_year_month }})</span>
                @else
                    —
                @endif
            </dd>
            <dt class="col-sm-3">解約金額（税別）</dt>
            <dd class="col-sm-9">
                @if ($contract->cancellation_amount !== null)
                    {{ number_format($contract->cancellation_amount) }} 円
                @else
                    —
                @endif
            </dd>
            <dt class="col-sm-3">最低利用期間（契約時）</dt>
            <dd class="col-sm-9">{{ $contract->minimum_term_months_snapshot ? $contract->minimum_term_months_snapshot.'か月' : '—' }}</dd>
        </dl>

        @if (! $isCustomer)
            <div class="d-flex flex-wrap gap-2 mt-4">
                @if ($status === 'approved')
                    <button type="button" class="btn btn-success btn-sm" id="serviceProvideOpen">サービス提供開始</button>
                @endif
                @if ($status === 'activated')
                    <form method="POST" action="{{ route($prefix.'.contracts.revert-service', $contract) }}" class="d-inline">
                        @csrf
                        <button class="btn btn-outline-warning btn-sm" type="submit" onclick="return confirm('承認済に戻します。よろしいですか？')">承認済に戻す</button>
                    </form>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="contractCancelOpen">解約</button>
                @endif
                @if ($status === 'draft' && ($deleteConfirmationCode ?? null))
                    <button type="button" class="btn btn-outline-danger btn-sm" id="contractDeleteOpen">下書き削除</button>
                @endif
            </div>
        @endif
    @endif

    @if ($activeTab === 'items')
        @if ($status === 'draft' && ! $isCustomer)
            <form method="POST" action="{{ route($prefix.'.contracts.prices', $contract) }}" class="mb-4">
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
                <button class="btn btn-outline-primary btn-sm" type="submit">価格保存</button>
            </form>
            <form method="POST" action="{{ route($prefix.'.contracts.submit-approval', $contract) }}" class="mb-3">
                @csrf
                <button class="btn btn-warning btn-sm" type="submit">親BPへ価格承認申請</button>
            </form>
        @else
            <div class="table-responsive mb-4">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>品目</th>
                            <th>区分</th>
                            <th>税率</th>
                            <th>請求額</th>
                            @unless ($isCustomer)
                                <th>仕切り</th>
                                <th>ロック</th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contract->items as $line)
                            <tr>
                                <td>{{ $line->item?->code }} / {{ $line->item?->name }}</td>
                                <td>{{ $line->item?->billing_type->label() }}</td>
                                <td>{{ $line->tax_rate }}%</td>
                                <td>@include('partials.price-display', ['amount' => $line->unit_price, 'taxRate' => $line->tax_rate])</td>
                                @unless ($isCustomer)
                                    <td>@include('partials.price-display', ['amount' => $line->partition_price, 'taxRate' => $line->tax_rate])</td>
                                    <td>{{ $line->price_locked ? '済' : '—' }}</td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @php
            $actorBpId = $prefix === 'bp' ? (int) (auth('bp')->user()?->bp_id ?? 0) : null;
            $isOwningBp = $actorBpId !== null && $actorBpId === (int) $contract->owning_bp_id;
        @endphp

        @if (in_array($status, ['approved', 'activated'], true) && $isOwningBp)
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

        @php
            $pendingApplications = ($contract->applications ?? collect())
                ->filter(fn ($application) => $application->status->value === 'pending')
                ->values();
        @endphp
        @if (! $isCustomer && $pendingApplications->isNotEmpty())
            @foreach ($pendingApplications as $application)
                @php
                    $canDecide = $prefix === 'admin'
                        || ($prefix === 'bp' && $actorBpId === (int) $application->to_bp_id);
                @endphp
                @if ($canDecide)
                    <div class="card card-body mb-3">
                        <h2 class="h6 mb-1">{{ $application->type->label() }}（承認待ち）</h2>
                        <p class="small text-muted mb-3">
                            from {{ $application->fromBp?->code ?? '—' }}
                            → to {{ $application->toBp?->code ?? '—' }}
                            ／ 金額 {{ number_format($application->amount ?? 0, 0) }} 円
                        </p>
                        <form method="POST" action="{{ route($prefix.'.applications.decide', $application) }}" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-md-6">
                                <label class="form-label small mb-1" for="application-note-{{ $application->id }}">メモ（任意）</label>
                                <input
                                    type="text"
                                    id="application-note-{{ $application->id }}"
                                    name="note"
                                    class="form-control form-control-sm"
                                    maxlength="500"
                                    placeholder="承認・却下時のメモ"
                                >
                            </div>
                            <div class="col-auto d-flex gap-2">
                                <button class="btn btn-success btn-sm" type="submit" name="approve" value="1">承認</button>
                                <button class="btn btn-outline-danger btn-sm" type="submit" name="approve" value="0">却下</button>
                            </div>
                        </form>
                    </div>
                @endif
            @endforeach
        @endif
    @endif

    @if ($activeTab === 'data')
        @if (in_array($status, ['approved', 'activated', 'cancelled'], true))
            @if (! $isCustomer && in_array($status, ['approved', 'activated'], true))
                <div class="d-flex justify-content-between align-items-center mb-3 gap-3">
                    <p class="text-muted small mb-0">
                        @if ($status === 'approved')
                            現在の契約データで Document のサンプル PDF を生成できます。カスタマーへの公開はサービス提供開始後です。
                        @else
                            契約データから Document PDF を再生成できます。
                        @endif
                    </p>
                    <form method="POST" action="{{ route($prefix.'.contracts.regenerate-documents', $contract) }}">
                        @csrf
                        <button class="btn btn-outline-primary btn-sm" type="submit">
                            {{ $status === 'approved' ? 'サンプル生成' : 'ドキュメント再生成' }}
                        </button>
                    </form>
                </div>
                @php
                    $templateTotal = $contract->items->sum(function ($line) use ($contract) {
                        return $line->item?->documents
                            ?->filter(fn ($doc) => $doc->owning_bp_id === null || (int) $doc->owning_bp_id === (int) $contract->owning_bp_id)
                            ->count() ?? 0;
                    });
                @endphp
                @if ($templateTotal === 0)
                    <div class="alert alert-warning">
                        この契約の品目に Document テンプレートがありません。品目一覧から対象品目を開き、テンプレートを登録してください。
                    </div>
                @endif
            @endif

            @foreach ($contract->items as $line)
                <div class="card mb-3">
                    <div class="card-body">
                        <h3 class="h6">{{ $line->item?->code }} / {{ $line->item?->name }}</h3>
                        @php
                            $lineTemplateCount = $line->item?->documents
                                ?->filter(fn ($doc) => $doc->owning_bp_id === null || (int) $doc->owning_bp_id === (int) $contract->owning_bp_id)
                                ->count() ?? 0;
                        @endphp
                        @if (! $isCustomer && $lineTemplateCount === 0)
                            <p class="text-warning small">この品目にはテンプレートが未登録のため、PDF は生成されません。</p>
                        @endif
                        @if (! $isCustomer && in_array($status, ['approved', 'activated'], true))
                            <form method="POST" action="{{ route($prefix.'.contracts.items.data', $line) }}">
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

                        <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                            <h4 class="h6 mb-0">ドキュメント（PDF）</h4>
                        </div>
                        @if ($isCustomer && $status !== 'activated')
                            <p class="text-muted small mb-0">資料のダウンロードはサービス提供開始後に利用できます。</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>タイトル</th>
                                            <th>ファイル</th>
                                            <th>区分</th>
                                            <th>生成日時</th>
                                            <th class="text-end">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($line->documents as $doc)
                                            @php $fileMissing = (bool) ($documentFileMissing[$doc->id] ?? false); @endphp
                                            <tr>
                                                <td>{{ $doc->title }}</td>
                                                <td><code class="small">{{ $doc->original_name ?: '—' }}</code></td>
                                                <td>
                                                    @if ($fileMissing)
                                                        <span class="badge text-bg-danger">ファイル欠落</span>
                                                    @elseif ($status === 'activated')
                                                        <span class="badge text-bg-success">カスタマー公開</span>
                                                    @elseif ($status === 'approved')
                                                        <span class="badge text-bg-info">サンプル</span>
                                                        <span class="badge text-bg-secondary">カスタマー非公開</span>
                                                    @else
                                                        <span class="badge text-bg-secondary">カスタマー非公開</span>
                                                    @endif
                                                    @if ($doc->item_document_id === null)
                                                        <span class="badge text-bg-light text-dark border">テンプレ削除済</span>
                                                    @endif
                                                </td>
                                                <td class="small text-nowrap">{{ $doc->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                                <td class="text-end text-nowrap">
                                                    @if ($fileMissing)
                                                        <span class="text-muted small">DL不可</span>
                                                    @else
                                                        <a
                                                            href="{{ route($prefix.'.contracts.items.documents.download', [$line, $doc->id]) }}"
                                                            class="btn btn-outline-secondary btn-sm"
                                                        >DL</a>
                                                    @endif
                                                    @if (! $isCustomer)
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-danger btn-sm js-contract-document-delete-open"
                                                            data-action="{{ route($prefix.'.contracts.items.documents.destroy', [$line, $doc->id]) }}"
                                                            data-title="{{ $doc->title }}"
                                                            data-code="{{ ($documentDeleteCodes[$doc->id] ?? '') }}"
                                                        >削除</button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="5" class="text-muted">なし</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        @else
            <p class="text-muted">承認後にデータ・ドキュメントを登録できます。</p>
        @endif
    @endif

    @if ($activeTab === 'messages')
        <div class="mb-3" style="max-width:40rem">
            @forelse ($contract->messages as $message)
                <div class="border rounded p-2 mb-2">
                    <div class="small text-muted">
                        {{ $message->user?->name ?? '不明' }}
                        <span class="ms-2">{{ $message->created_at }}</span>
                    </div>
                    <div>{{ $message->body }}</div>
                </div>
            @empty
                <p class="text-muted">メッセージはまだありません。</p>
            @endforelse
        </div>

        @if ($canPostMessages)
            <form method="POST" action="{{ route($prefix.'.contracts.messages.store', $contract) }}" style="max-width:40rem">
                @csrf
                <label class="form-label" for="message_body">メッセージ</label>
                <textarea name="body" id="message_body" class="form-control mb-2" rows="3" maxlength="2000" required>{{ old('body') }}</textarea>
                <button class="btn btn-primary btn-sm" type="submit">投稿</button>
            </form>
        @else
            <p class="text-muted small">サービス提供開始後はメッセージを投稿できません（閲覧のみ）。</p>
        @endif
    @endif

    @if ($activeTab === 'history')
        <ul class="mb-0">
            @forelse ($contract->statusHistories as $history)
                <li class="small">{{ $history->created_at }} : {{ $history->from_status ?? '—' }} → {{ $history->to_status }} {{ $history->note }}</li>
            @empty
                <li class="text-muted">履歴なし</li>
            @endforelse
        </ul>
    @endif

    @if (! $isCustomer && $status === 'approved')
        @php
            $thisMonthYm = now()->timezone(config('app.timezone'))->format('Ym');
            $nextMonthYm = now()->timezone(config('app.timezone'))->copy()->addMonthNoOverflow()->format('Ym');
            $thisMonthLabel = now()->timezone(config('app.timezone'))->format('Y年n月');
            $nextMonthLabel = now()->timezone(config('app.timezone'))->copy()->addMonthNoOverflow()->format('Y年n月');
            $oldMode = old('first_billing_mode', 'this_month');
        @endphp
        <dialog id="serviceProvideDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route($prefix.'.contracts.activate', $contract) }}" class="p-4" id="serviceProvideForm">
                @csrf
                <h2 class="h5 mb-3">サービス提供開始の確認</h2>
                <p class="mb-3">「{{ $contract->code }}」をサービス提供開始にします。初回請求月を選択してください。</p>
                <fieldset class="mb-3">
                    <legend class="form-label">初回請求月</legend>
                    <div class="form-check">
                        <input class="form-check-input js-first-billing-mode" type="radio" name="first_billing_mode" id="first_billing_this_month" value="this_month" @checked($oldMode === 'this_month')>
                        <label class="form-check-label" for="first_billing_this_month">今月（{{ $thisMonthLabel }}）</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input js-first-billing-mode" type="radio" name="first_billing_mode" id="first_billing_next_month" value="next_month" @checked($oldMode === 'next_month')>
                        <label class="form-check-label" for="first_billing_next_month">来月（{{ $nextMonthLabel }}）</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input js-first-billing-mode" type="radio" name="first_billing_mode" id="first_billing_custom" value="custom" @checked($oldMode === 'custom')>
                        <label class="form-check-label" for="first_billing_custom">任意指定</label>
                    </div>
                </fieldset>
                <div class="mb-3 @if ($oldMode !== 'custom') d-none @endif" id="firstBillingCustomWrap">
                    <label class="form-label" for="first_billing_year_month">請求月（YYYYMM）</label>
                    <input type="text" name="first_billing_year_month" id="first_billing_year_month" class="form-control" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" value="{{ old('first_billing_year_month', $thisMonthYm) }}" @if ($oldMode === 'custom') required @endif>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="serviceProvideCancel">キャンセル</button>
                    <button type="submit" class="btn btn-success">提供開始</button>
                </div>
            </form>
        </dialog>
    @endif

    @if (! $isCustomer && $status === 'activated')
        <dialog id="contractCancelDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 32rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route($prefix.'.contracts.cancel', $contract) }}" class="p-4" id="contractCancelForm">
                @csrf
                <h2 class="h5 mb-3">解約の確認</h2>
                <p class="mb-3">最終請求月と残期間一括の金額を設定します。金額は自動提案後に手修正できます。</p>
                <div class="mb-3">
                    <label class="form-label" for="final_billing_year_month">最終請求月（YYYYMM）</label>
                    <input type="text" name="final_billing_year_month" id="final_billing_year_month" class="form-control" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" value="{{ old('final_billing_year_month', now()->timezone(config('app.timezone'))->format('Ym')) }}" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="cancellation_amount">解約金額（税別・円）</label>
                    <div class="input-group">
                        <input type="number" step="1" min="0" name="cancellation_amount" id="cancellation_amount" class="form-control" value="{{ old('cancellation_amount', $cancellationSuggestion['suggested_amount'] ?? 0) }}" required>
                        <button type="button" class="btn btn-outline-secondary" id="cancellationSuggestBtn">提案を反映</button>
                    </div>
                    <div class="form-text" id="cancellationSuggestHint">
                        @if ($cancellationSuggestion ?? null)
                            残月数見込み {{ $cancellationSuggestion['remaining_months'] }} / 最低 {{ $cancellationSuggestion['minimum_term_months'] }} か月（経過 {{ $cancellationSuggestion['elapsed_months'] }}）
                        @endif
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="cancellation_note">メモ</label>
                    <textarea name="cancellation_note" id="cancellation_note" class="form-control" rows="2" maxlength="500">{{ old('cancellation_note') }}</textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="contractCancelCancel">閉じる</button>
                    <button type="submit" class="btn btn-danger">解約する</button>
                </div>
            </form>
        </dialog>
    @endif

    @if (! $isCustomer && ($deleteConfirmationCode ?? null) && $status === 'draft')
        <dialog id="contractDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" action="{{ route($prefix.'.contracts.destroy', $contract) }}" class="p-4">
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
    @endif

    @unless ($isCustomer)
        <dialog id="contractDocumentDeleteDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
            <form method="POST" id="contractDocumentDeleteForm" class="p-4">
                @csrf
                @method('DELETE')
                <h2 class="h5 mb-3">ドキュメント削除の確認</h2>
                <p class="mb-2">「<span id="contractDocumentDeleteTitle"></span>」を削除します。</p>
                <p class="mb-3">下の確認コードを入力してください。</p>
                <p class="text-center mb-3">
                    <span class="display-6 fw-bold" id="contractDocumentDeleteCode"></span>
                </p>
                <div class="mb-3">
                    <label class="form-label" for="contract_document_delete_confirmation_code">確認コード</label>
                    <input type="text" name="confirmation_code" id="contract_document_delete_confirmation_code" class="form-control text-center" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="off" required>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="contractDocumentDeleteCancel">キャンセル</button>
                    <button type="submit" class="btn btn-danger" id="contractDocumentDeleteSubmit" disabled>削除する</button>
                </div>
            </form>
        </dialog>
    @endunless
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const returnCustomerId = @json($returnCustomerId ? (string) $returnCustomerId : null);
        if (returnCustomerId) {
            document.querySelectorAll('form').forEach((form) => {
                const method = (form.getAttribute('method') || 'get').toLowerCase();
                if (method !== 'post') return;
                if (form.querySelector('input[name="return_customer_id"]')) return;
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'return_customer_id';
                input.value = returnCustomerId;
                form.appendChild(input);
            });
        }

        const bindDialog = (openId, dialogId, cancelId) => {
            const openButton = document.getElementById(openId);
            const dialog = document.getElementById(dialogId);
            const cancelButton = document.getElementById(cancelId);
            if (!openButton || !dialog) return;
            openButton.addEventListener('click', () => dialog.showModal());
            cancelButton?.addEventListener('click', () => dialog.close());
            dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
        };

        bindDialog('serviceProvideOpen', 'serviceProvideDialog', 'serviceProvideCancel');
        bindDialog('contractCancelOpen', 'contractCancelDialog', 'contractCancelCancel');

        const firstBillingModes = document.querySelectorAll('.js-first-billing-mode');
        const customWrap = document.getElementById('firstBillingCustomWrap');
        const customInput = document.getElementById('first_billing_year_month');
        const syncFirstBillingMode = () => {
            const selected = document.querySelector('.js-first-billing-mode:checked')?.value;
            const isCustom = selected === 'custom';
            if (customWrap) {
                customWrap.classList.toggle('d-none', ! isCustom);
            }
            if (customInput) {
                customInput.required = isCustom;
            }
        };
        firstBillingModes.forEach((input) => input.addEventListener('change', syncFirstBillingMode));
        syncFirstBillingMode();

        const deleteDialog = document.getElementById('contractDeleteDialog');
        const deleteOpen = document.getElementById('contractDeleteOpen');
        const deleteCancel = document.getElementById('contractDeleteCancel');
        const deleteCode = document.getElementById('contractDeleteCode');
        const deleteInput = document.getElementById('contract_delete_confirmation_code');
        const deleteSubmit = document.getElementById('contractDeleteSubmit');
        if (deleteDialog && deleteOpen && deleteInput && deleteSubmit && deleteCode) {
            const expected = () => deleteCode.textContent.trim();
            const sync = () => { deleteSubmit.disabled = deleteInput.value.trim() !== expected(); };
            deleteOpen.addEventListener('click', () => { deleteInput.value = ''; sync(); deleteDialog.showModal(); deleteInput.focus(); });
            deleteCancel?.addEventListener('click', () => deleteDialog.close());
            deleteDialog.addEventListener('click', (event) => { if (event.target === deleteDialog) deleteDialog.close(); });
            deleteInput.addEventListener('input', () => {
                deleteInput.value = deleteInput.value.replace(/\D/g, '').slice(0, 4);
                sync();
            });
        }

        const docDeleteDialog = document.getElementById('contractDocumentDeleteDialog');
        const docDeleteForm = document.getElementById('contractDocumentDeleteForm');
        const docDeleteTitle = document.getElementById('contractDocumentDeleteTitle');
        const docDeleteCode = document.getElementById('contractDocumentDeleteCode');
        const docDeleteInput = document.getElementById('contract_document_delete_confirmation_code');
        const docDeleteSubmit = document.getElementById('contractDocumentDeleteSubmit');
        const docDeleteCancel = document.getElementById('contractDocumentDeleteCancel');
        if (docDeleteDialog && docDeleteForm && docDeleteCode && docDeleteInput && docDeleteSubmit) {
            const expectedDoc = () => docDeleteCode.textContent.trim();
            const syncDoc = () => { docDeleteSubmit.disabled = docDeleteInput.value.trim() !== expectedDoc(); };
            document.querySelectorAll('.js-contract-document-delete-open').forEach((button) => {
                button.addEventListener('click', () => {
                    if (button.dataset.action) {
                        docDeleteForm.setAttribute('action', button.dataset.action);
                    }
                    if (docDeleteTitle) {
                        docDeleteTitle.textContent = button.dataset.title ?? '';
                    }
                    docDeleteCode.textContent = button.dataset.code ?? '';
                    docDeleteInput.value = '';
                    syncDoc();
                    docDeleteDialog.showModal();
                    docDeleteInput.focus();
                });
            });
            docDeleteCancel?.addEventListener('click', () => docDeleteDialog.close());
            docDeleteDialog.addEventListener('click', (event) => { if (event.target === docDeleteDialog) docDeleteDialog.close(); });
            docDeleteInput.addEventListener('input', () => {
                docDeleteInput.value = docDeleteInput.value.replace(/\D/g, '').slice(0, 4);
                syncDoc();
            });
        }

        const suggestBtn = document.getElementById('cancellationSuggestBtn');
        const finalMonth = document.getElementById('final_billing_year_month');
        const amountInput = document.getElementById('cancellation_amount');
        const hint = document.getElementById('cancellationSuggestHint');
        if (suggestBtn && finalMonth && amountInput) {
            suggestBtn.addEventListener('click', async () => {
                const ym = finalMonth.value.trim();
                if (!/^\d{6}$/.test(ym)) {
                    alert('最終請求月は YYYYMM で入力してください。');
                    return;
                }
                @unless ($isCustomer)
                const url = @json(route($prefix.'.contracts.cancellation-suggestion', $contract)) + '?final_billing_year_month=' + encodeURIComponent(ym);
                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!response.ok) {
                    alert('提案の取得に失敗しました。');
                    return;
                }
                const data = await response.json();
                amountInput.value = data.suggested_amount;
                if (hint) {
                    hint.textContent = '残月数見込み ' + data.remaining_months + ' / 最低 ' + data.minimum_term_months + ' か月（経過 ' + data.elapsed_months + '）';
                }
                @endunless
            });
        }

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
