@extends('layouts.app')

@section('title', '問い合わせ一覧')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'customer' ? 'カスタマー' : 'BP') }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">問い合わせ一覧</h1>
        <div class="d-flex gap-2">
            <a href="{{ route(($routePrefix ?? 'admin').'.inquiries.create') }}" class="btn btn-primary btn-sm">新規作成</a>
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
                    <th>件名</th>
                    <th>状態</th>
                    <th>カスタマー</th>
                    <th>対応BP</th>
                    <th>更新</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($inquiries as $inquiry)
                    <tr>
                        <td>
                            <a href="{{ route(($routePrefix ?? 'admin').'.inquiries.show', $inquiry) }}">
                                {{ $inquiry->subject }}
                            </a>
                        </td>
                        <td>{{ $inquiry->status->label() }}</td>
                        <td>{{ $inquiry->customer?->code ?? '—' }}</td>
                        <td>{{ $inquiry->owningBp?->code }}</td>
                        <td class="small text-muted">{{ $inquiry->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted">問い合わせはありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $inquiries->links() }}
@endsection
