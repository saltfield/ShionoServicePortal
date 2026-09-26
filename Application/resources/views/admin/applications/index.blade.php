@extends('layouts.app')

@section('title', '申請一覧')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="ssp-page-title mb-0">価格申請</h1>
        <a href="{{ route(($routePrefix ?? 'admin').'.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
    </div>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <p class="small text-muted mb-2">古い申請から上に表示しています。上から順に対応してください。</p>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>種別</th>
                    <th>契約</th>
                    <th>from</th>
                    <th>to</th>
                    <th>金額</th>
                    <th>申請日時</th>
                    <th>状態</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($applications as $application)
                    <tr>
                        <td>
                            {{ $application->type->label() }}
                            @if ($application->approvalProgressLabel())
                                <span class="badge text-bg-secondary ms-1">{{ $application->approvalProgressLabel() }}</span>
                            @endif
                        </td>
                        <td><code>{{ $application->contract?->code }}</code></td>
                        <td>{{ $application->fromBp?->code }}</td>
                        <td>{{ $application->toBp?->code }}</td>
                        <td class="text-end">{{ number_format($application->amount ?? 0, 0) }}</td>
                        <td class="small text-nowrap">{{ $application->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>{{ $application->status->label() }}</td>
                        <td>
                            @if ($application->contract_id)
                                <a
                                    href="{{ route(($routePrefix ?? 'admin').'.contracts.show', ['contract' => $application->contract_id, 'tab' => 'items']) }}"
                                    class="btn btn-outline-secondary btn-sm"
                                >明細確認</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-muted">申請がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $applications->links() }}
@endsection
