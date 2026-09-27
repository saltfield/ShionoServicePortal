@extends('layouts.app')

@section('title', '自動請求設定')
@section('area', '管理者')
@section('logout_action', route('admin.logout'))

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => '請求一覧', 'url' => route('admin.invoices.index')]],
        'current' => '自動請求設定',
    ])
    <h1 class="ssp-page-title mb-3">自動請求設定</h1>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="alert alert-light border mb-3 py-2">
        <div class="d-flex flex-wrap align-items-baseline gap-2">
            <span class="small text-muted">次回の自動実行予定</span>
            <span class="fw-semibold">{{ $schedule['next_run_label'] }}</span>
        </div>
        @if (! empty($schedule['is_due_today']))
            <div class="small text-warning mt-1">本日の予定時刻を過ぎています。スケジューラ稼働中ならまもなく実行されます。</div>
        @endif
    </div>

    <div class="row g-3 mb-3 align-items-stretch">
        <div class="col-lg-6">
            <form method="POST" action="{{ route('admin.billing-batch.update') }}" class="card h-100">
                @csrf
                @method('PUT')
                <div class="card-body">
                    <h2 class="h6 mb-3">スケジュール</h2>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="enabled" value="1" id="enabled" @checked(old('enabled', $schedule['enabled']))>
                        <label class="form-check-label" for="enabled">自動生成を有効にする</label>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-2">
                            <label class="form-label" for="day_mode">実行日</label>
                            <select name="day_mode" id="day_mode" class="form-select" required>
                                @foreach ($dayModes as $mode)
                                    <option value="{{ $mode->value }}" @selected(old('day_mode', $schedule['day_mode']) === $mode->value)>{{ $mode->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label" for="day_of_month">毎月N日（1–31）</label>
                            <input type="number" min="1" max="31" name="day_of_month" id="day_of_month" class="form-control" value="{{ old('day_of_month', $schedule['day_of_month']) }}">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label" for="time">時刻（Asia/Tokyo）</label>
                            <input type="time" name="time" id="time" class="form-control" value="{{ old('time', $schedule['time']) }}" required>
                        </div>
                    </div>
                    <div class="form-text mb-3">存在しない日はその月の末日に実行します。</div>
                    <button class="btn btn-primary" type="submit">保存</button>
                </div>
            </form>
        </div>

        <div class="col-lg-6">
            <form method="POST" action="{{ route('admin.billing-batch.run') }}" class="card h-100">
                @csrf
                <div class="card-body d-flex flex-column">
                    <h2 class="h6 mb-3">今すぐ生成（単月）</h2>
                    <p class="small text-muted mb-3">全契約を対象に、指定月の請求・キックバックを生成します。</p>
                    <div class="mb-3">
                        <label class="form-label" for="billing_year_month">対象請求月（YYYYMM）</label>
                        <input type="text" name="billing_year_month" id="billing_year_month" class="form-control" pattern="\d{6}" value="{{ old('billing_year_month', now()->format('Ym')) }}" required>
                    </div>
                    <div class="mt-auto">
                        <button class="btn btn-warning" type="submit">手動実行</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <form method="POST" action="{{ route('admin.billing-batch.run-range') }}" class="card" onsubmit="return confirm('指定した対象（BP配下／BP単体／カスタマー）の契約について、期間の請求を連続生成します。既に発行済みの月はエラーになります。実行しますか？');">
                @csrf
                <div class="card-body">
                    <h2 class="h6 mb-2">過去月の請求を生成（範囲）</h2>
                    <p class="small text-muted mb-3">
                        導入前から稼働していた契約向け。対象は <strong>BP（配下含む）</strong>・<strong>BP単体</strong>・<strong>カスタマー</strong>のいずれか。
                        <strong>カスタマー請求のみ</strong>生成します。キックバックは通常の単月バッチで、対象請求の <strong>6ヶ月後</strong>に生成されます。
                    </p>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label" for="scope_type">対象区分</label>
                            <select name="scope_type" id="scope_type" class="form-select" required>
                                <option value="bp_tree" @selected(old('scope_type', 'bp_tree') === 'bp_tree')>BP（配下含む）</option>
                                <option value="bp" @selected(old('scope_type') === 'bp')>BP単体</option>
                                <option value="customer" @selected(old('scope_type') === 'customer')>カスタマー</option>
                            </select>
                        </div>
                        <div class="col-md-5" id="scope-bp-wrap">
                            <label class="form-label" for="business_partner_id">BP</label>
                            <select name="business_partner_id" id="business_partner_id" class="form-select">
                                <option value="">選択してください</option>
                                @foreach ($businessPartners as $partner)
                                    <option value="{{ $partner->id }}" @selected((string) old('business_partner_id') === (string) $partner->id)>
                                        {{ str_repeat('— ', max(0, (int) $partner->depth - 1)) }}{{ $partner->code }} / {{ $partner->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-5 d-none" id="scope-customer-wrap">
                            <label class="form-label" for="customer_id">カスタマー</label>
                            <select name="customer_id" id="customer_id" class="form-select">
                                <option value="">選択してください</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>
                                        {{ $customer->code }} / {{ $customer->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="from_year_month">開始月</label>
                            <input type="text" name="from_year_month" id="from_year_month" class="form-control" pattern="\d{6}" value="{{ old('from_year_month') }}" placeholder="202506" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="to_year_month">終了月</label>
                            <input type="text" name="to_year_month" id="to_year_month" class="form-control" pattern="\d{6}" value="{{ old('to_year_month') }}" placeholder="202508" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button class="btn btn-outline-warning" type="submit">範囲を一括生成</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <script>
        (function () {
            const type = document.getElementById('scope_type');
            const bpWrap = document.getElementById('scope-bp-wrap');
            const customerWrap = document.getElementById('scope-customer-wrap');
            const bpSelect = document.getElementById('business_partner_id');
            const customerSelect = document.getElementById('customer_id');
            function syncScope() {
                const isCustomer = type && type.value === 'customer';
                if (bpWrap) bpWrap.classList.toggle('d-none', !!isCustomer);
                if (customerWrap) customerWrap.classList.toggle('d-none', !isCustomer);
                if (bpSelect) bpSelect.required = !isCustomer;
                if (customerSelect) customerSelect.required = !!isCustomer;
            }
            if (type) {
                type.addEventListener('change', syncScope);
                syncScope();
            }
        })();
    </script>

    <h2 class="h5 mb-2">生成履歴</h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm align-middle">
            <thead>
            <tr>
                <th>実行日時</th>
                <th>請求月</th>
                <th>実行区分</th>
                <th>結果</th>
                <th class="text-end">請求</th>
                <th class="text-end">キックバック</th>
                <th class="text-end">スキップ</th>
                <th class="text-end">エラー</th>
                <th>実行者</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($recentRuns as $run)
                <tr>
                    <td class="small">{{ $run->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $run->billing_year_month }}</td>
                    <td>{{ $run->trigger->label() }}</td>
                    <td>
                        <span class="badge {{ $run->status->badgeClass() }}">{{ $run->status->label() }}</span>
                    </td>
                    <td class="text-end">{{ $run->invoices_count }}</td>
                    <td class="text-end">{{ $run->kickbacks_count }}</td>
                    <td class="text-end">{{ $run->skipped_count }}</td>
                    <td class="text-end">
                        @if ($run->errors_count > 0)
                            <span class="text-danger fw-semibold">{{ $run->errors_count }}</span>
                        @else
                            0
                        @endif
                    </td>
                    <td class="small">{{ $run->actor?->login_id ?? '—' }}</td>
                    <td class="text-nowrap">
                        <a href="{{ route('admin.billing-batch.runs.show', $run) }}" class="btn btn-outline-primary btn-sm">請求一覧</a>
                    </td>
                </tr>
                @if ($run->errors->isNotEmpty())
                    <tr class="table-light">
                        <td colspan="10" class="small py-2">
                            <div class="fw-semibold mb-1">エラー詳細（実行 #{{ $run->id }}）</div>
                            <ul class="mb-0 ps-3">
                                @foreach ($run->errors as $error)
                                    <li>
                                        <span class="badge text-bg-danger">失敗</span>
                                        {{ $error->phaseLabel() }}
                                        / 契約 <code>{{ $error->contract?->code ?? '—' }}</code>
                                        : {{ $error->message }}
                                    </li>
                                @endforeach
                            </ul>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="10" class="text-muted">生成履歴はまだありません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
