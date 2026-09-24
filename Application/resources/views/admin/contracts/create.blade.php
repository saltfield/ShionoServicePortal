@extends('layouts.app')

@section('title', 'オーダー作成')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $prefix = $routePrefix ?? 'admin';
        $returnCustomerId = $returnCustomerId ?? null;
        $returnQuery = array_filter(['return_customer_id' => $returnCustomerId]);
        if ($returnCustomerId) {
            $crumbs = [
                ['label' => 'カスタマー詳細', 'url' => route($prefix.'.customers.show', ['customer' => $returnCustomerId, 'tab' => 'contracts'])],
            ];
        } else {
            $crumbs = [
                ['label' => '契約一覧', 'url' => route($prefix.'.contracts.index')],
            ];
        }
    @endphp
    @include('partials.breadcrumb', ['crumbs' => $crumbs, 'current' => 'オーダー作成'])
    <h1 class="h3 mb-3">オーダー作成</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @php
        $wizardStep = 1;
        if ($selectedCustomer) {
            $wizardStep = 2;
        }
        if ($selectedSite) {
            $wizardStep = old('special_price_requested') || $errors->any() ? 4 : 3;
        }
        $wizardSteps = [
            1 => 'カスタマー選択',
            2 => '拠点選択',
            3 => '品目選択',
            4 => '価格承認申請',
        ];
    @endphp
    <nav class="order-wizard mb-4" aria-label="オーダー作成の手順">
        <ol class="order-wizard__list" id="order-wizard-steps" data-base-step="{{ $wizardStep }}">
            @foreach ($wizardSteps as $number => $label)
                @php
                    $state = $number < $wizardStep ? 'is-done' : ($number === $wizardStep ? 'is-current' : 'is-todo');
                @endphp
                <li class="order-wizard__step {{ $state }}" data-step="{{ $number }}">
                    <span class="order-wizard__num">{{ $number }}</span>
                    <span class="order-wizard__label">{{ $label }}</span>
                </li>
            @endforeach
        </ol>
    </nav>
    <style>
        .order-wizard__list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .order-wizard__step {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1 1 9rem;
            min-height: 2.75rem;
            padding: 0.55rem 1.75rem 0.55rem 0.9rem;
            background: #e9ecef;
            color: #6c757d;
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 50%, calc(100% - 14px) 100%, 0 100%, 14px 50%);
            margin-right: -0.35rem;
        }
        .order-wizard__step:first-child {
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 50%, calc(100% - 14px) 100%, 0 100%);
            padding-left: 0.9rem;
        }
        .order-wizard__step:last-child {
            clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%, 14px 50%);
            margin-right: 0;
            padding-right: 0.9rem;
        }
        .order-wizard__num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.75);
            font-size: 0.8rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .order-wizard__label {
            font-size: 0.875rem;
            line-height: 1.2;
        }
        .order-wizard__step.is-done {
            background: #198754;
            color: #fff;
        }
        .order-wizard__step.is-done .order-wizard__num {
            background: rgba(255, 255, 255, 0.25);
            color: #fff;
        }
        .order-wizard__step.is-current {
            background: #0d6efd;
            color: #fff;
            font-weight: 600;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.25);
            z-index: 1;
        }
        .order-wizard__step.is-current .order-wizard__num {
            background: #fff;
            color: #0d6efd;
        }
        .order-wizard__step.is-todo .order-wizard__num {
            background: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }
        @media (max-width: 767.98px) {
            .order-wizard__step {
                flex: 1 1 calc(50% - 0.35rem);
                margin-right: 0;
                clip-path: none;
                border-radius: 0.375rem;
                padding-right: 0.9rem;
            }
            .order-wizard__step:first-child,
            .order-wizard__step:last-child {
                clip-path: none;
            }
        }
    </style>

    @unless ($selectedCustomer)
        <form method="GET" action="{{ route($prefix.'.contracts.create') }}" class="row g-2 align-items-end mb-3">
            @if ($returnCustomerId)
                <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
            @endif
            <div class="col-md-3">
                <label class="form-label small mb-1" for="cn">CN</label>
                <input type="text" name="cn" id="cn" class="form-control form-control-sm" value="{{ $filters['cn'] ?? '' }}" placeholder="部分一致">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1" for="customer_name">カスタマー名</label>
                <input type="text" name="customer_name" id="customer_name" class="form-control form-control-sm" value="{{ $filters['customer_name'] ?? '' }}" placeholder="部分一致">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary btn-sm" type="submit">フィルター</button>
                <a href="{{ route($prefix.'.contracts.create', $returnQuery) }}" class="btn btn-outline-secondary btn-sm">クリア</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>CN</th>
                        <th>カスタマー名</th>
                        <th>管理BP</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td><code>{{ $customer->code }}</code></td>
                            <td>{{ $customer->name }}</td>
                            <td>{{ $customer->managingBp?->code }} / {{ $customer->managingBp?->name }}</td>
                            <td class="text-end">
                                <a class="btn btn-outline-primary btn-sm" href="{{ route($prefix.'.contracts.create', array_filter(['customer_id' => $customer->id, 'cn' => $filters['cn'] ?: null, 'customer_name' => $filters['customer_name'] ?: null, 'return_customer_id' => $returnCustomerId])) }}">選択</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted text-center">カスタマーがありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $customers->links() }}
    @else
        <div class="alert alert-light border mb-3" style="max-width:40rem">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="small text-muted">選択中カスタマー</div>
                    <strong><code>{{ $selectedCustomer->code }}</code> / {{ $selectedCustomer->name }}</strong>
                    <div class="small text-muted">管理BP: {{ $selectedCustomer->managingBp?->code }}</div>
                </div>
                @if ($returnCustomerId)
                    <a href="{{ route($prefix.'.customers.show', ['customer' => $returnCustomerId, 'tab' => 'contracts']) }}" class="btn btn-outline-secondary btn-sm">戻る</a>
                @else
                    <a href="{{ route($prefix.'.contracts.create') }}" class="btn btn-outline-secondary btn-sm">変更</a>
                @endif
            </div>
        </div>

        @unless ($selectedSite)
            <form method="GET" action="{{ route($prefix.'.contracts.create') }}" class="card card-body" style="max-width:40rem">
                <input type="hidden" name="customer_id" value="{{ $selectedCustomer->id }}">
                @if ($returnCustomerId)
                    <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
                @endif
                <div class="mb-3">
                    <label class="form-label" for="site_id">拠点</label>
                    <select name="site_id" id="site_id" class="form-select" required>
                        <option value="">選択してください</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }}@if ($site->is_primary)（主）@endif</option>
                        @endforeach
                    </select>
                </div>
                @if ($sites->isEmpty())
                    <p class="text-danger small mb-0">このカスタマーに拠点がありません。先に拠点を登録してください。</p>
                @else
                    <button class="btn btn-primary" type="submit">次へ（品目選択）</button>
                @endif
            </form>
        @else
            <div class="alert alert-light border mb-3" style="max-width:40rem">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="small text-muted">選択中拠点</div>
                        <strong>{{ $selectedSite->name }}</strong>
                    </div>
                    <a href="{{ route($prefix.'.contracts.create', array_merge(['customer_id' => $selectedCustomer->id], $returnQuery)) }}" class="btn btn-outline-secondary btn-sm">変更</a>
                </div>
            </div>

            <form method="GET" action="{{ route($prefix.'.contracts.create') }}" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="customer_id" value="{{ $selectedCustomer->id }}">
                <input type="hidden" name="site_id" value="{{ $selectedSite->id }}">
                @if ($returnCustomerId)
                    <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
                @endif
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="item_code">コード</label>
                    <input type="text" name="item_code" id="item_code" class="form-control form-control-sm" value="{{ $filters['item_code'] ?? '' }}" placeholder="部分一致">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="item_name">品目名</label>
                    <input type="text" name="item_name" id="item_name" class="form-control form-control-sm" value="{{ $filters['item_name'] ?? '' }}" placeholder="部分一致">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="item_type_id">品目種別</label>
                    <select name="item_type_id" id="item_type_id" class="form-select form-select-sm">
                        <option value="">すべて</option>
                        @foreach (($itemTypeOptions ?? []) as $itemTypeOption)
                            <option value="{{ $itemTypeOption->id }}" @selected((int) ($filters['item_type_id'] ?? 0) === $itemTypeOption->id)>
                                {{ $itemTypeOption->name }}@if ($itemTypeOption->owning_bp_id)（独自）@endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="billing_type">区分</label>
                    <select name="billing_type" id="billing_type" class="form-select form-select-sm">
                        <option value="">すべて</option>
                        @foreach (($billingTypes ?? []) as $billingTypeOption)
                            <option value="{{ $billingTypeOption->value }}" @selected(($filters['billing_type'] ?? '') === $billingTypeOption->value)>
                                {{ $billingTypeOption->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-primary btn-sm" type="submit">フィルター</button>
                    <a href="{{ route($prefix.'.contracts.create', array_merge(['customer_id' => $selectedCustomer->id, 'site_id' => $selectedSite->id], $returnQuery)) }}" class="btn btn-outline-secondary btn-sm">クリア</a>
                </div>
            </form>

            <form method="POST" action="{{ route($prefix.'.contracts.store') }}" id="order-create-form">
                @csrf
                <input type="hidden" name="site_id" value="{{ $selectedSite->id }}">
                @if ($returnCustomerId)
                    <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
                @endif
                @php
                    $standardPartitions = $standardPartitions ?? [];
                    $customerAmounts = $customerAmounts ?? [];
                @endphp

                <div id="item-type-notices" class="alert alert-info d-none mb-3" role="status" aria-live="polite">
                    <div class="fw-semibold mb-1">品目種別のご案内</div>
                    <ul id="item-type-notice-list" class="mb-0 ps-3"></ul>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th style="width:2.5rem"></th>
                                <th>コード</th>
                                <th>品目名</th>
                                <th>区分</th>
                                <th style="width:4.5rem">説明</th>
                                <th>必須セット</th>
                                <th>通常仕切り</th>
                                <th>ユーザー標準</th>
                                <th class="js-unit-price-col">エンドユーザー価格（税別）</th>
                                <th class="js-hope-partition-col d-none">希望仕切り（税別）</th>
                                <th>税率</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($items as $item)
                                @php
                                    $standard = (int) ($standardPartitions[$item->id] ?? $item->partition_price);
                                    $customerAmount = (int) ($customerAmounts[$item->id] ?? $item->user_price);
                                    $typeMessage = ($item->itemType && $item->itemType->hasGuideMessage())
                                        ? trim((string) $item->itemType->message)
                                        : '';
                                    $typeName = $item->itemType?->name ?? '';
                                    $itemDescription = trim((string) ($item->description ?? ''));
                                @endphp
                                <tr>
                                    <td>
                                        <input
                                            class="form-check-input js-item-check"
                                            type="checkbox"
                                            name="item_ids[]"
                                            value="{{ $item->id }}"
                                            id="item_{{ $item->id }}"
                                            data-type-name="{{ $typeName }}"
                                            data-type-message="{{ $typeMessage }}"
                                        >
                                    </td>
                                    <td><label for="item_{{ $item->id }}" class="mb-0"><code>{{ $item->code }}</code></label></td>
                                    <td>
                                        <label for="item_{{ $item->id }}" class="mb-0 d-inline-block">
                                            <span class="d-block">
                                                {{ $item->name }}
                                                @if ($item->owning_bp_id)
                                                    <span class="badge text-bg-info">BP独自</span>
                                                @endif
                                            </span>
                                            @if ($typeName !== '')
                                                <span class="d-block text-muted small">{{ $typeName }}</span>
                                            @endif
                                        </label>
                                    </td>
                                    <td>{{ $item->billing_type->label() }}</td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm js-item-description"
                                            data-item-code="{{ $item->code }}"
                                            data-item-name="{{ $item->name }}"
                                            data-item-description="{{ $itemDescription }}"
                                        >表示</button>
                                    </td>
                                    <td>{{ $item->requiredItem?->code ?? '—' }}</td>
                                    <td>@include('partials.price-display', ['amount' => $standard, 'taxRate' => $item->tax_rate, 'stacked' => true])</td>
                                    <td>@include('partials.price-display', ['amount' => $item->user_price, 'taxRate' => $item->tax_rate, 'stacked' => true])</td>
                                    <td class="js-unit-price-col">
                                        <input
                                            type="number"
                                            step="1"
                                            min="0"
                                            name="unit_prices[{{ $item->id }}]"
                                            class="form-control form-control-sm js-unit-price"
                                            value="{{ $customerAmount }}"
                                            disabled
                                            data-item-id="{{ $item->id }}"
                                        >
                                    </td>
                                    <td class="js-hope-partition-col d-none">
                                        <input
                                            type="number"
                                            step="1"
                                            min="0"
                                            name="partitions[{{ $item->id }}]"
                                            class="form-control form-control-sm js-hope-partition"
                                            value="{{ $standard }}"
                                            disabled
                                            data-item-id="{{ $item->id }}"
                                        >
                                    </td>
                                    <td>{{ $item->tax_rate }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="11" class="text-muted text-center">該当する品目がありません。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $items->links() }}
                <p class="small text-muted">必須セットがある品目は、セット先も同時に選択してください。選択した品目のエンドユーザー価格は作成時に設定できます。</p>

                <div id="order-price-step" class="border rounded p-3 mb-3 bg-white">
                    <h2 class="h5 mb-2">価格承認申請</h2>
                    <p class="small text-muted mb-3">選択した品目の価格を確認し、必要なら特価申請を行ってオーダーを作成します。</p>

                    <div class="card card-body mb-3" style="max-width:40rem">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" value="1" name="special_price_requested" id="special_price_requested" @checked(old('special_price_requested'))>
                            <label class="form-check-label" for="special_price_requested">特価申請する</label>
                        </div>
                        <p class="small text-muted mb-2">チェックすると希望仕切りを入力でき、作成と同時に親BPへ特価申請します。</p>
                        <div id="special-price-fields" class="d-none">
                            <label class="form-label" for="special_price_reason">特価申請理由 <span class="text-danger">*</span></label>
                            <textarea
                                name="special_price_reason"
                                id="special_price_reason"
                                class="form-control"
                                rows="3"
                                maxlength="2000"
                                placeholder="特価が必要な理由を入力してください"
                            >{{ old('special_price_reason') }}</textarea>
                        </div>
                    </div>

                    <button class="btn btn-primary" type="submit" id="order-create-submit">オーダー作成</button>
                </div>
            </form>

            <dialog id="itemDescriptionDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 32rem; width: calc(100% - 2rem);">
                <div class="p-4">
                    <h2 class="h5 mb-1" id="itemDescriptionTitle">品目の説明</h2>
                    <p class="small text-muted mb-3" id="itemDescriptionMeta"></p>
                    <div id="itemDescriptionBody" class="border rounded p-3 bg-light" style="white-space: pre-wrap; min-height: 4rem;"></div>
                    <div class="d-flex justify-content-end mt-3">
                        <button type="button" class="btn btn-outline-secondary" id="itemDescriptionClose">閉じる</button>
                    </div>
                </div>
            </dialog>

            <script>
                (function () {
                    const toggle = document.getElementById('special_price_requested');
                    const fields = document.getElementById('special-price-fields');
                    const reason = document.getElementById('special_price_reason');
                    const submit = document.getElementById('order-create-submit');
                    const hopeCols = document.querySelectorAll('.js-hope-partition-col');
                    const hopeInputs = document.querySelectorAll('.js-hope-partition');
                    const unitInputs = document.querySelectorAll('.js-unit-price');
                    const itemChecks = document.querySelectorAll('.js-item-check');
                    const noticeBox = document.getElementById('item-type-notices');
                    const noticeList = document.getElementById('item-type-notice-list');
                    const descriptionDialog = document.getElementById('itemDescriptionDialog');
                    const descriptionTitle = document.getElementById('itemDescriptionTitle');
                    const descriptionMeta = document.getElementById('itemDescriptionMeta');
                    const descriptionBody = document.getElementById('itemDescriptionBody');
                    const descriptionClose = document.getElementById('itemDescriptionClose');
                    const wizardRoot = document.getElementById('order-wizard-steps');
                    const priceStepPanel = document.getElementById('order-price-step');
                    let wizardStep = wizardRoot
                        ? parseInt(wizardRoot.getAttribute('data-base-step') || '3', 10)
                        : 3;

                    function setWizardStep(step) {
                        wizardStep = step;
                        if (!wizardRoot) {
                            return;
                        }
                        wizardRoot.querySelectorAll('.order-wizard__step').forEach(function (el) {
                            const n = parseInt(el.getAttribute('data-step') || '0', 10);
                            el.classList.remove('is-done', 'is-current', 'is-todo');
                            if (n < step) {
                                el.classList.add('is-done');
                            } else if (n === step) {
                                el.classList.add('is-current');
                            } else {
                                el.classList.add('is-todo');
                            }
                        });
                    }

                    function syncTypeNotices() {
                        if (!noticeBox || !noticeList) {
                            return;
                        }
                        const seen = new Set();
                        const entries = [];
                        itemChecks.forEach(function (check) {
                            if (!check.checked) {
                                return;
                            }
                            const message = (check.getAttribute('data-type-message') || '').trim();
                            if (!message || seen.has(message)) {
                                return;
                            }
                            seen.add(message);
                            entries.push({
                                name: (check.getAttribute('data-type-name') || '').trim(),
                                message: message,
                            });
                        });
                        noticeList.innerHTML = '';
                        if (entries.length === 0) {
                            noticeBox.classList.add('d-none');
                            return;
                        }
                        entries.forEach(function (entry) {
                            const li = document.createElement('li');
                            li.style.whiteSpace = 'pre-wrap';
                            if (entry.name) {
                                const strong = document.createElement('strong');
                                strong.textContent = entry.name + '：';
                                li.appendChild(strong);
                            }
                            li.appendChild(document.createTextNode(entry.message));
                            noticeList.appendChild(li);
                        });
                        noticeBox.classList.remove('d-none');
                    }

                    function syncRowInputs() {
                        const special = toggle && toggle.checked;
                        unitInputs.forEach(function (input) {
                            const itemId = input.getAttribute('data-item-id');
                            const checked = document.getElementById('item_' + itemId)?.checked;
                            input.disabled = !checked;
                            input.required = !!checked && special;
                        });
                        hopeInputs.forEach(function (input) {
                            const itemId = input.getAttribute('data-item-id');
                            const checked = document.getElementById('item_' + itemId)?.checked;
                            input.disabled = !special || !checked;
                            input.required = !!special && !!checked;
                        });
                        syncTypeNotices();
                    }

                    function syncSpecial() {
                        const on = toggle && toggle.checked;
                        if (fields) {
                            fields.classList.toggle('d-none', !on);
                        }
                        hopeCols.forEach(function (col) {
                            col.classList.toggle('d-none', !on);
                        });
                        if (reason) {
                            reason.required = on;
                            reason.disabled = !on;
                        }
                        if (submit) {
                            submit.textContent = on ? '特価申請してオーダー作成' : 'オーダー作成';
                        }
                        if (on) {
                            setWizardStep(4);
                        }
                        syncRowInputs();
                    }

                    if (toggle) {
                        toggle.addEventListener('change', syncSpecial);
                    }
                    itemChecks.forEach(function (check) {
                        check.addEventListener('change', syncRowInputs);
                    });

                    if (priceStepPanel) {
                        priceStepPanel.addEventListener('focusin', function () {
                            setWizardStep(4);
                        });
                    }

                    document.querySelectorAll('.js-item-description').forEach(function (button) {
                        button.addEventListener('click', function () {
                            if (!descriptionDialog || !descriptionBody) {
                                return;
                            }
                            const code = button.getAttribute('data-item-code') || '';
                            const name = button.getAttribute('data-item-name') || '';
                            const description = (button.getAttribute('data-item-description') || '').trim();
                            if (descriptionTitle) {
                                descriptionTitle.textContent = '品目の説明';
                            }
                            if (descriptionMeta) {
                                descriptionMeta.textContent = code ? (code + ' / ' + name) : name;
                            }
                            descriptionBody.textContent = description !== ''
                                ? description
                                : '説明は登録されていません。';
                            descriptionBody.classList.toggle('text-muted', description === '');
                            descriptionDialog.showModal();
                        });
                    });
                    if (descriptionClose && descriptionDialog) {
                        descriptionClose.addEventListener('click', function () {
                            descriptionDialog.close();
                        });
                    }
                    if (descriptionDialog) {
                        descriptionDialog.addEventListener('click', function (event) {
                            if (event.target === descriptionDialog) {
                                descriptionDialog.close();
                            }
                        });
                    }

                    setWizardStep(wizardStep);
                    syncSpecial();
                })();
            </script>
        @endunless
    @endunless
@endsection
