@extends('layouts.app')

@section('title', '請求詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'bp' ? 'BP' : 'カスタマー') }}
@endsection

@section('content')
    @include('partials.breadcrumb', [
        'crumbs' => [['label' => '請求一覧', 'url' => route(($routePrefix ?? 'admin').'.invoices.index')]],
        'current' => '請求詳細',
    ])
    <h1 class="h3 mb-3">請求詳細</h1>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <dl class="row">
        <dt class="col-sm-3">請求番号</dt><dd class="col-sm-9"><code>{{ $invoice->code }}</code></dd>
        <dt class="col-sm-3">請求月</dt><dd class="col-sm-9">{{ $invoice->billing_year_month }}</dd>
        <dt class="col-sm-3">契約</dt><dd class="col-sm-9"><code>{{ $invoice->contract?->code }}</code></dd>
        <dt class="col-sm-3">カスタマー</dt><dd class="col-sm-9">{{ $invoice->customer?->name }}</dd>
        <dt class="col-sm-3">管理BP</dt><dd class="col-sm-9">{{ $invoice->owningBp?->name }}</dd>
        <dt class="col-sm-3">状態</dt>
        <dd class="col-sm-9">
            {{ $invoice->status->label() }}
            @include('partials.invoice-payment-diff-badge', ['invoice' => $invoice, 'class' => 'ms-1'])
        </dd>
        <dt class="col-sm-3">税別合計</dt><dd class="col-sm-9">{{ number_format($invoice->subtotal) }} 円</dd>
        <dt class="col-sm-3">税額</dt><dd class="col-sm-9">{{ number_format($invoice->tax_total) }} 円</dd>
        <dt class="col-sm-3">税込合計</dt><dd class="col-sm-9"><strong>{{ number_format($invoice->total) }} 円</strong></dd>
        <dt class="col-sm-3">入金金額（税込）</dt>
        <dd class="col-sm-9">
            @if ($invoice->paid_amount !== null)
                {{ number_format($invoice->paid_amount) }} 円
            @else
                <span class="text-muted">—</span>
            @endif
        </dd>
        @if ($invoice->note)
            <dt class="col-sm-3">メモ</dt><dd class="col-sm-9">{{ $invoice->note }}</dd>
        @endif
    </dl>

    @if ($canManage ?? false)
        <div class="mb-3 d-flex flex-wrap gap-2 align-items-end">
            @if ($invoice->status->value === 'issued')
                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.invoices.mark-paid', $invoice) }}" class="d-flex flex-wrap gap-2 align-items-end border rounded p-2">
                    @csrf
                    <div>
                        <label class="form-label small mb-1" for="paid_amount">入金金額（税込）</label>
                        <input
                            type="number"
                            min="0"
                            step="1"
                            id="paid_amount"
                            name="paid_amount"
                            class="form-control form-control-sm"
                            value="{{ old('paid_amount', $invoice->total) }}"
                            required
                        >
                    </div>
                    <button class="btn btn-success btn-sm" type="submit">入金済にする</button>
                </form>
                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.invoices.cancel', $invoice) }}">
                    @csrf
                    <button class="btn btn-outline-danger btn-sm" type="submit">取消</button>
                </form>
            @endif
            @if (($canEditPaidAmount ?? false) && $invoice->status->value === 'paid')
                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.invoices.paid-amount', $invoice) }}" class="d-flex flex-wrap gap-2 align-items-end border rounded p-2">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="form-label small mb-1" for="paid_amount_edit">入金金額の修正（税込）</label>
                        <input
                            type="number"
                            min="0"
                            step="1"
                            id="paid_amount_edit"
                            name="paid_amount"
                            class="form-control form-control-sm"
                            value="{{ old('paid_amount', $invoice->paid_amount) }}"
                            required
                        >
                    </div>
                    <button class="btn btn-outline-primary btn-sm" type="submit">金額を更新</button>
                </form>
            @endif
        </div>
    @endif

    <h2 class="h5">明細</h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
            <tr>
                <th>内容</th>
                <th>区分</th>
                <th class="text-end">税別</th>
                <th class="text-end">税率</th>
                <th class="text-end">税額</th>
                <th class="text-end">税込</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->billing_type->label() }}</td>
                    <td class="text-end">{{ number_format($line->amount) }}</td>
                    <td class="text-end">{{ $line->tax_rate }}%</td>
                    <td class="text-end">{{ number_format($line->tax_amount) }}</td>
                    <td class="text-end">{{ number_format($line->amount_inclusive) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
