@extends('layouts.app')

@section('title', 'BP管理')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">BP管理</h1>
        <div class="d-flex gap-2">
            <a href="{{ route(($routePrefix ?? 'admin').'.business-partners.create') }}" class="btn btn-primary btn-sm">新規作成</a>
            <a href="{{ route(($routePrefix ?? 'admin').'.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="GET" class="row g-2 align-items-end mb-3">
        <div class="col-md-3">
            <label class="form-label small mb-1" for="bpn">BPN</label>
            <input type="text" id="bpn" name="bpn" value="{{ $filters['bpn'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1" for="bp_name">BP名</label>
            <input type="text" id="bp_name" name="bp_name" value="{{ $filters['bp_name'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-auto">
            <button class="btn btn-primary btn-sm" type="submit">検索</button>
            <a href="{{ route(($routePrefix ?? 'admin').'.business-partners.index') }}" class="btn btn-outline-secondary btn-sm">クリア</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>BPN</th>
                <th>名称</th>
                <th>階層</th>
                <th>親BP</th>
                <th>2FA</th>
                <th>状態</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($partners as $partner)
                @php $isSelf = isset($selfBpId) && (int) $partner->id === (int) $selfBpId; @endphp
                <tr class="{{ $isSelf ? 'table-primary' : '' }}">
                    <td>
                        <code>{{ $partner->code }}</code>
                        @if ($isSelf)
                            <span class="badge text-bg-primary ms-1">自BP</span>
                        @endif
                    </td>
                    <td>{{ $partner->name }}</td>
                    <td>{{ $partner->depth }}</td>
                    <td>{{ $partner->parent?->code ?? '-' }}</td>
                    <td>{{ $partner->two_factor_mode?->label() ?? '—' }}</td>
                    <td>{{ $partner->is_active ? '有効' : '無効' }}</td>
                    <td><a href="{{ route(($routePrefix ?? 'admin').'.business-partners.show', $partner) }}" class="btn btn-outline-secondary btn-sm">詳細</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">BPがありません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $partners->links() }}
@endsection
