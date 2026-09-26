@extends('layouts.app')

@section('title', '請求一覧')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'bp' ? 'BP' : 'カスタマー') }}
@endsection

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="ssp-page-title mb-0">請求一覧</h1>
        @if (in_array($routePrefix ?? 'admin', ['admin', 'bp'], true))
            <a href="{{ route(($routePrefix ?? 'admin').'.kickbacks.index') }}" class="btn btn-outline-secondary btn-sm">キックバック</a>
        @endif
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>請求番号</th>
                <th>請求月</th>
                <th>契約</th>
                <th>カスタマー</th>
                <th class="text-end">請求額（税込）</th>
                <th class="text-end">入金額（税込）</th>
                <th>状態</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($invoices as $invoice)
                <tr>
                    <td><a href="{{ route(($routePrefix ?? 'admin').'.invoices.show', $invoice) }}"><code>{{ $invoice->code }}</code></a></td>
                    <td>{{ $invoice->billing_year_month }}</td>
                    <td><code>{{ $invoice->contract?->code }}</code></td>
                    <td>{{ $invoice->customer?->name }}</td>
                    <td class="text-end">{{ number_format($invoice->total) }}</td>
                    <td class="text-end">
                        @if ($invoice->paid_amount !== null)
                            {{ number_format($invoice->paid_amount) }}
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        {{ $invoice->status->label() }}
                        @include('partials.invoice-payment-diff-badge', ['invoice' => $invoice, 'class' => 'ms-1'])
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted text-center py-4">請求はありません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $invoices->links() }}
@endsection
