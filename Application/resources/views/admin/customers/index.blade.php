@extends('layouts.app')

@section('title', 'カスタマー管理')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">カスタマー管理</h1>
        <div class="d-flex gap-2">
            <a href="{{ route(($routePrefix ?? 'admin').'.customers.create', array_filter(['managing_bp_id' => $filters['managing_bp_id']])) }}" class="btn btn-primary btn-sm">新規作成</a>
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
            <label class="form-label small mb-1" for="cn">CN</label>
            <input type="text" id="cn" name="cn" value="{{ $filters['cn'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1" for="cn_name">カスタマー名</label>
            <input type="text" id="cn_name" name="cn_name" value="{{ $filters['cn_name'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1" for="managing_bp_id">管理BP</label>
            <select id="managing_bp_id" name="managing_bp_id" class="form-select form-select-sm">
                <option value="">すべて</option>
                @foreach ($managingPartners as $partner)
                    <option value="{{ $partner->id }}" @selected((int) $filters['managing_bp_id'] === $partner->id)>
                        {{ $partner->code }} / {{ $partner->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary btn-sm" type="submit">検索</button>
            <a href="{{ route(($routePrefix ?? 'admin').'.customers.index') }}" class="btn btn-outline-secondary btn-sm">クリア</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>CN</th>
                <th>名称</th>
                <th>管理BP</th>
                <th>2FA</th>
                <th>状態</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($customers as $customer)
                <tr>
                    <td><code>{{ $customer->code }}</code></td>
                    <td>{{ $customer->name }}</td>
                    <td>{{ $customer->managingBp?->code ?? '-' }}</td>
                    <td>{{ $customer->two_factor_mode?->label() ?? '—' }}</td>
                    <td>{{ $customer->is_active ? '有効' : '無効' }}</td>
                    <td><a href="{{ route(($routePrefix ?? 'admin').'.customers.show', $customer) }}" class="btn btn-outline-secondary btn-sm">詳細</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">カスタマーがありません。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $customers->links() }}
@endsection
