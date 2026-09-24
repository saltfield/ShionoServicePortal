@extends('layouts.app')

@section('title', '生成実行 #'.$run->id)
@section('area', '管理者')
@section('logout_action', route('admin.logout'))

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => '自動請求設定', 'url' => route('admin.billing-batch.edit')]],
        'current' => '生成実行 #'.$run->id,
    ])
    <h1 class="h3 mb-3">生成実行 #{{ $run->id }}</h1>

    <dl class="row mb-4" style="max-width:40rem">
        <dt class="col-sm-4">実行日時</dt>
        <dd class="col-sm-8">{{ $run->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</dd>
        <dt class="col-sm-4">請求月</dt>
        <dd class="col-sm-8">{{ $run->billing_year_month }}</dd>
        <dt class="col-sm-4">実行区分</dt>
        <dd class="col-sm-8">{{ $run->trigger->label() }}</dd>
        <dt class="col-sm-4">結果</dt>
        <dd class="col-sm-8"><span class="badge {{ $run->status->badgeClass() }}">{{ $run->status->label() }}</span></dd>
        <dt class="col-sm-4">実行者</dt>
        <dd class="col-sm-8">{{ $run->actor?->login_id ?? '—' }}</dd>
        <dt class="col-sm-4">件数</dt>
        <dd class="col-sm-8">
            請求 {{ $run->invoices_count }}
            / キックバック {{ $run->kickbacks_count }}
            / スキップ {{ $run->skipped_count }}
            / エラー {{ $run->errors_count }}
        </dd>
    </dl>

    @if ($run->errors->isNotEmpty())
        <h2 class="h5">エラー</h2>
        <ul class="mb-4">
            @foreach ($run->errors as $error)
                <li class="small">
                    <span class="badge text-bg-danger">失敗</span>
                    {{ $error->phaseLabel() }}
                    / 契約 <code>{{ $error->contract?->code ?? '—' }}</code>
                    : {{ $error->message }}
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="h5">作成された請求書</h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>請求番号</th>
                <th>請求月</th>
                <th>契約</th>
                <th>カスタマー</th>
                <th class="text-end">金額（税込）</th>
                <th>状態</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($invoices as $invoice)
                <tr>
                    <td><a href="{{ route('admin.invoices.show', $invoice) }}"><code>{{ $invoice->code }}</code></a></td>
                    <td>{{ $invoice->billing_year_month }}</td>
                    <td><code>{{ $invoice->contract?->code }}</code></td>
                    <td>{{ $invoice->customer?->name }}</td>
                    <td class="text-end">{{ number_format($invoice->total) }}</td>
                    <td>{{ $invoice->status->label() }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted text-center py-4">この実行で作成された請求書はありません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $invoices->links() }}

    <h2 class="h5">作成されたキックバック</h2>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>番号</th>
                <th>請求月</th>
                <th>契約</th>
                <th>支払BP</th>
                <th>受取BP</th>
                <th class="text-end">税込合計</th>
                <th>状態</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($kickbacks as $kickback)
                <tr>
                    <td><a href="{{ route('admin.kickbacks.show', $kickback) }}"><code>{{ $kickback->code }}</code></a></td>
                    <td>{{ $kickback->billing_year_month }}</td>
                    <td><code>{{ $kickback->contract?->code }}</code></td>
                    <td>{{ $kickback->fromBp?->code }} / {{ $kickback->fromBp?->name }}</td>
                    <td>{{ $kickback->toBp?->code }} / {{ $kickback->toBp?->name }}</td>
                    <td class="text-end">{{ number_format($kickback->total) }}</td>
                    <td>{{ $kickback->status->label() }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted text-center py-4">この実行で作成されたキックバックはありません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $kickbacks->links() }}
@endsection
