@extends('layouts.app')

@section('title', '契約一覧')
@section('area', 'カスタマー')
@section('logout_action', route('customer.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="ssp-page-title mb-0">契約一覧</h1>
        <a href="{{ route('customer.dashboard') }}" class="btn btn-outline-secondary btn-sm">ダッシュボード</a>
    </div>
    @if (! empty($unreadMessagesFilter))
        <div class="alert alert-info py-2">
            未読オーダーメッセージがある契約のみ表示しています。
            <a href="{{ route('customer.contracts.index') }}" class="ms-2">すべて表示</a>
        </div>
    @endif
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr><th>契約番号</th><th>拠点</th><th>状態</th></tr>
            </thead>
            <tbody>
                @forelse ($contracts as $contract)
                    <tr>
                        <td>
                            <a href="{{ route('customer.contracts.show', array_merge(['contract' => $contract], ! empty($unreadMessagesFilter) ? ['tab' => 'messages'] : [])) }}">
                                <code>{{ $contract->code }}</code>
                            </a>
                        </td>
                        <td>{{ $contract->site?->name }}</td>
                        <td>{{ $contract->status->label() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-muted">契約がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $contracts->links() }}
@endsection
