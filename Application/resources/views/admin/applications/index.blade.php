@extends('layouts.app')

@section('title', '申請一覧')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">価格申請</h1>
        <a href="{{ route(($routePrefix ?? 'admin').'.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
    </div>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>種別</th>
                    <th>契約</th>
                    <th>from</th>
                    <th>to</th>
                    <th>金額</th>
                    <th>状態</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($applications as $application)
                    <tr>
                        <td>{{ $application->type->label() }}</td>
                        <td><a href="{{ route(($routePrefix ?? 'admin').'.contracts.show', $application->contract_id) }}"><code>{{ $application->contract?->code }}</code></a></td>
                        <td>{{ $application->fromBp?->code }}</td>
                        <td>{{ $application->toBp?->code }}</td>
                        <td class="text-end">{{ number_format($application->amount ?? 0, 0) }}</td>
                        <td>{{ $application->status->label() }}</td>
                        <td>
                            @if ($application->status->value === 'pending')
                                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.applications.decide', $application) }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="approve" value="1">
                                    <button class="btn btn-success btn-sm" type="submit">承認</button>
                                </form>
                                <form method="POST" action="{{ route(($routePrefix ?? 'admin').'.applications.decide', $application) }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="approve" value="0">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">却下</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">申請がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $applications->links() }}
@endsection
