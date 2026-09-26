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

    <div class="alert alert-light border mb-4" style="max-width:36rem">
        <div class="small text-muted mb-1">次回の自動実行予定</div>
        <div class="fw-semibold">{{ $schedule['next_run_label'] }}</div>
        @if (! empty($schedule['is_due_today']))
            <div class="small text-warning mt-1">本日の予定時刻を過ぎています。スケジューラ稼働中ならまもなく実行されます。</div>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.billing-batch.update') }}" class="card card-body mb-4" style="max-width:36rem">
        @csrf
        @method('PUT')
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="enabled" value="1" id="enabled" @checked(old('enabled', $schedule['enabled']))>
            <label class="form-check-label" for="enabled">自動生成を有効にする</label>
        </div>
        <div class="mb-3">
            <label class="form-label" for="day_mode">実行日</label>
            <select name="day_mode" id="day_mode" class="form-select" required>
                @foreach ($dayModes as $mode)
                    <option value="{{ $mode->value }}" @selected(old('day_mode', $schedule['day_mode']) === $mode->value)>{{ $mode->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="day_of_month">毎月N日（1–31）</label>
            <input type="number" min="1" max="31" name="day_of_month" id="day_of_month" class="form-control" value="{{ old('day_of_month', $schedule['day_of_month']) }}">
            <div class="form-text">存在しない日はその月の末日に実行します。</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="time">時刻（Asia/Tokyo）</label>
            <input type="time" name="time" id="time" class="form-control" value="{{ old('time', $schedule['time']) }}" required>
        </div>
        <button class="btn btn-primary" type="submit">保存</button>
    </form>

    <form method="POST" action="{{ route('admin.billing-batch.run') }}" class="card card-body mb-4" style="max-width:36rem">
        @csrf
        <h2 class="h6">今すぐ生成</h2>
        <div class="mb-3">
            <label class="form-label" for="billing_year_month">対象請求月（YYYYMM）</label>
            <input type="text" name="billing_year_month" id="billing_year_month" class="form-control" pattern="\d{6}" value="{{ now()->format('Ym') }}" required>
        </div>
        <button class="btn btn-warning" type="submit">手動実行</button>
    </form>

    <h2 class="h5">生成履歴</h2>
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
