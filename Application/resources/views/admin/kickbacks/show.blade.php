@extends('layouts.app')

@section('title', 'キックバック詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => 'キックバック', 'url' => route(($routePrefix ?? 'admin').'.kickbacks.index')]],
        'current' => 'キックバック詳細',
    ])
    <h1 class="h3 mb-3">キックバック詳細</h1>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <dl class="row">
        <dt class="col-sm-3">番号</dt><dd class="col-sm-9"><code>{{ $invoice->code }}</code></dd>
        <dt class="col-sm-3">請求月</dt><dd class="col-sm-9">{{ $invoice->billing_year_month }}</dd>
        <dt class="col-sm-3">支払期月</dt><dd class="col-sm-9">{{ $invoice->due_year_month ?? '—' }}</dd>
        <dt class="col-sm-3">契約</dt><dd class="col-sm-9"><code>{{ $invoice->contract?->code }}</code></dd>
        <dt class="col-sm-3">支払BP</dt><dd class="col-sm-9">{{ $invoice->fromBp?->code }} / {{ $invoice->fromBp?->name }}</dd>
        <dt class="col-sm-3">受取BP</dt><dd class="col-sm-9">{{ $invoice->toBp?->code }} / {{ $invoice->toBp?->name }}</dd>
        <dt class="col-sm-3">状態</dt><dd class="col-sm-9">{{ $invoice->status->label() }}</dd>
        <dt class="col-sm-3">税別合計</dt><dd class="col-sm-9">{{ number_format($invoice->subtotal) }} 円</dd>
        <dt class="col-sm-3">税額</dt><dd class="col-sm-9">{{ number_format($invoice->tax_total) }} 円</dd>
        <dt class="col-sm-3">税込合計</dt><dd class="col-sm-9"><strong>{{ number_format($invoice->total) }} 円</strong></dd>
    </dl>

    @if ($canManage ?? false)
        <div class="mb-3 d-flex gap-2">
            @if ($invoice->status->value === 'issued')
                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.kickbacks.mark-paid', $invoice) }}">
                    @csrf
                    <button class="btn btn-success btn-sm" type="submit">入金済にする</button>
                </form>
                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.kickbacks.withdraw', $invoice) }}">
                    @csrf
                    <button class="btn btn-outline-danger btn-sm" type="submit">取下げ</button>
                </form>
            @endif
        </div>
    @endif

    <h2 class="h5">明細（区間内訳）</h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
            <tr>
                <th>内容</th>
                <th class="text-end">上値</th>
                <th class="text-end">下値</th>
                <th class="text-end">差額（税別）</th>
                <th class="text-end">税率</th>
                <th class="text-end">税額</th>
                <th class="text-end">税込</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="text-end">{{ number_format($line->upper_amount) }}</td>
                    <td class="text-end">{{ number_format($line->partition_amount) }}</td>
                    <td class="text-end">
                        {{ number_format($line->amount) }}
                        @if ($line->amount < 0)
                            <span class="badge text-bg-danger">絶対値 {{ number_format(abs($line->amount)) }}</span>
                        @endif
                    </td>
                    <td class="text-end">{{ $line->tax_rate }}%</td>
                    <td class="text-end">{{ number_format($line->tax_amount) }}</td>
                    <td class="text-end">{{ number_format($line->amount_inclusive) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
