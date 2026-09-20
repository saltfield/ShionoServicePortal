@extends('layouts.app')

@section('title', '契約一覧')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">契約一覧</h1>
        <div class="d-flex gap-2">
            <a href="{{ route(($routePrefix ?? 'admin').'.contracts.create') }}" class="btn btn-primary btn-sm">新規申込</a>
            <a href="{{ route(($routePrefix ?? 'admin').'.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
        </div>
    </div>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>契約番号</th>
                    <th>カスタマー</th>
                    <th>拠点</th>
                    <th>管理BP</th>
                    <th>状態</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contracts as $contract)
                    <tr>
                        <td><a href="{{ route(($routePrefix ?? 'admin').'.contracts.show', $contract) }}"><code>{{ $contract->code }}</code></a></td>
                        <td>{{ $contract->customer?->code }}</td>
                        <td>{{ $contract->site?->name }}</td>
                        <td>{{ $contract->owningBp?->code }}</td>
                        <td>{{ $contract->status->label() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted">契約がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $contracts->links() }}
@endsection
