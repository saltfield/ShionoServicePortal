@extends('layouts.app')

@section('title', '品目一覧')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">品目一覧</h1>
        <div class="d-flex gap-2">
            @unless ($readOnly ?? false)
                <a href="{{ route('admin.items.create') }}" class="btn btn-primary btn-sm">新規作成</a>
            @endunless
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
                    <th>コード</th>
                    <th>名称</th>
                    <th>区分</th>
                    <th>必須セット</th>
                    <th>仕切り（税別）</th>
                    <th>ユーザー標準（税別）</th>
                    <th>税率</th>
                    <th>状態</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>
                            @if ($readOnly ?? false)
                                <code>{{ $item->code }}</code>
                            @else
                                <a href="{{ route('admin.items.show', $item) }}"><code>{{ $item->code }}</code></a>
                            @endif
                        </td>
                        <td>{{ $item->name }}</td>
                        <td>{{ $item->billing_type->label() }}</td>
                        <td>{{ $item->requiredItem ? $item->requiredItem->code : '—' }}</td>
                        <td>@include('partials.price-display', ['amount' => $item->partition_price, 'taxRate' => $item->tax_rate])</td>
                        <td>@include('partials.price-display', ['amount' => $item->user_price, 'taxRate' => $item->tax_rate])</td>
                        <td>{{ $item->tax_rate }}%</td>
                        <td>{{ $item->is_active ? '有効' : '無効' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-muted">品目がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $items->links() }}
@endsection
