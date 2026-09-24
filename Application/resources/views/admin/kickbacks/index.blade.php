@extends('layouts.app')

@section('title', 'キックバック一覧')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">キックバック一覧</h1>
        <a href="{{ route(($routePrefix ?? 'admin').'.invoices.index') }}" class="btn btn-outline-secondary btn-sm">請求一覧</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="table-responsive">
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
            @forelse ($invoices as $invoice)
                <tr>
                    <td>
                        <a href="{{ route(($routePrefix ?? 'admin').'.kickbacks.show', $invoice) }}">
                            <code>{{ $invoice->code }}</code>
                        </a>
                    </td>
                    <td>{{ $invoice->billing_year_month }}</td>
                    <td><code>{{ $invoice->contract?->code }}</code></td>
                    <td>{{ $invoice->fromBp?->code }} / {{ $invoice->fromBp?->name }}</td>
                    <td>{{ $invoice->toBp?->code }} / {{ $invoice->toBp?->name }}</td>
                    <td class="text-end">{{ number_format($invoice->total) }}</td>
                    <td>{{ $invoice->status->label() }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted text-center py-4">キックバックはありません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $invoices->links() }}
@endsection
