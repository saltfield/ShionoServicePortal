@extends('layouts.app')

@section('title', 'キックバック詳細')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection

@section('content')
    @php
        $adjustmentLine = $invoice->lines->firstWhere('is_adjustment', true);
        $baseLines = $invoice->lines->where('is_adjustment', false);
    @endphp
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
        <dt class="col-sm-3">対象請求月</dt><dd class="col-sm-9">{{ $invoice->billing_year_month }}</dd>
        <dt class="col-sm-3">対象請求</dt>
        <dd class="col-sm-9">
            @if ($invoice->sourceInvoice)
                <a href="{{ route(($routePrefix ?? 'admin').'.invoices.show', $invoice->sourceInvoice) }}">
                    <code>{{ $invoice->sourceInvoice->code }}</code>
                </a>
                <span class="text-muted small ms-1">
                    （税込 {{ number_format($invoice->sourceInvoice->total) }} /
                    入金 {{ $invoice->sourceInvoice->paid_amount !== null ? number_format($invoice->sourceInvoice->paid_amount).' 円' : '未入金' }}）
                </span>
            @else
                —
            @endif
        </dd>
        <dt class="col-sm-3">支払期月</dt><dd class="col-sm-9">{{ $invoice->due_year_month ?? '—' }}</dd>
        <dt class="col-sm-3">契約</dt><dd class="col-sm-9"><code>{{ $invoice->contract?->code }}</code></dd>
        <dt class="col-sm-3">支払BP</dt><dd class="col-sm-9">{{ $invoice->fromBp?->code }} / {{ $invoice->fromBp?->name }}</dd>
        <dt class="col-sm-3">受取BP</dt><dd class="col-sm-9">{{ $invoice->toBp?->code }} / {{ $invoice->toBp?->name }}</dd>
        <dt class="col-sm-3">状態</dt>
        <dd class="col-sm-9">
            {{ $invoice->status->label() }}
            @if ($invoice->manual_adjusted)
                <span class="badge text-bg-secondary ms-1">手動調整済</span>
            @endif
            @include('partials.kickback-payment-diff-badge', ['invoice' => $invoice, 'class' => 'ms-1'])
        </dd>
        <dt class="col-sm-3">税別合計</dt><dd class="col-sm-9">{{ number_format($invoice->subtotal) }} 円</dd>
        <dt class="col-sm-3">税額</dt><dd class="col-sm-9">{{ number_format($invoice->tax_total) }} 円</dd>
        <dt class="col-sm-3">請求額（税込）</dt><dd class="col-sm-9"><strong>{{ number_format($invoice->total) }} 円</strong></dd>
        <dt class="col-sm-3">入金金額（税込）</dt>
        <dd class="col-sm-9">
            @if ($invoice->paid_amount !== null)
                {{ number_format($invoice->paid_amount) }} 円
            @else
                <span class="text-muted">—</span>
            @endif
        </dd>
    </dl>

    @if ($canManage ?? false)
        <div class="mb-3 d-flex flex-wrap gap-2 align-items-end">
            @if ($invoice->status->value === 'issued')
                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.kickbacks.mark-paid', $invoice) }}" class="d-flex flex-wrap gap-2 align-items-end border rounded p-2">
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
                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.kickbacks.withdraw', $invoice) }}">
                    @csrf
                    <button class="btn btn-outline-danger btn-sm" type="submit">取下げ</button>
                </form>
            @endif
            @if (($canEditPaidAmount ?? false) && $invoice->status->value === 'paid')
                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.kickbacks.paid-amount', $invoice) }}" class="d-flex flex-wrap gap-2 align-items-end border rounded p-2">
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
            @if ($invoice->sourceInvoice && $invoice->status->value === 'issued')
                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.kickbacks.regenerate', $invoice) }}">
                    @csrf
                    <button class="btn btn-outline-secondary btn-sm" type="submit">入金按分で再計算</button>
                </form>
            @endif
        </div>
    @endif

    <h2 class="h5">明細（区間内訳）</h2>
    <div class="table-responsive mb-3">
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
            @forelse ($baseLines as $line)
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
            @empty
                <tr><td colspan="7" class="text-muted">明細がありません。</td></tr>
            @endforelse
            @if ($adjustmentLine)
                <tr class="table-warning">
                    <td>
                        {{ $adjustmentLine->description }}
                        <span class="badge text-bg-secondary ms-1">手動</span>
                    </td>
                    <td class="text-end">—</td>
                    <td class="text-end">—</td>
                    <td class="text-end">{{ number_format($adjustmentLine->amount) }}</td>
                    <td class="text-end">{{ $adjustmentLine->tax_rate }}%</td>
                    <td class="text-end">{{ number_format($adjustmentLine->tax_amount) }}</td>
                    <td class="text-end">{{ number_format($adjustmentLine->amount_inclusive) }}</td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

    @if (($canAdjustAmounts ?? false) && $invoice->status->value === 'issued')
        <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.kickbacks.amounts', $invoice) }}" class="border rounded p-3 mb-3" style="max-width: 28rem;">
            @csrf
            @method('PUT')
            <h3 class="h6">端数調整明細</h3>
            <p class="small text-muted mb-2">
                税込1円単位で調整明細を追加します（税率0%、マイナス可）。0を保存すると調整明細を削除します。
                保存後は「手動調整済」となり、通常の再計算では上書きされません（「入金按分で再計算」は上書きします）。
            </p>
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label small mb-1" for="adjustment_amount">調整額（税込・円）</label>
                    <input
                        type="number"
                        step="1"
                        id="adjustment_amount"
                        name="adjustment_amount"
                        class="form-control form-control-sm"
                        value="{{ old('adjustment_amount', $adjustmentLine?->amount_inclusive ?? 0) }}"
                        required
                    >
                </div>
                <button class="btn btn-primary btn-sm" type="submit">調整を保存</button>
            </div>
        </form>
    @endif
@endsection
