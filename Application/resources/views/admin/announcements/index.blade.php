@extends('layouts.app')

@section('title', 'お知らせ')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'customer' ? 'カスタマー' : 'BP') }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">お知らせ</h1>
        <div class="d-flex gap-2">
            @if ($canManage ?? false)
                <a href="{{ route(($routePrefix ?? 'admin').'.announcements.create') }}" class="btn btn-primary btn-sm">新規公開</a>
            @endif
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
                    <th>タイトル</th>
                    <th>公開日時</th>
                    <th>配信先</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($announcements as $announcement)
                    <tr>
                        <td>
                            <a href="{{ route(($routePrefix ?? 'admin').'.announcements.show', $announcement) }}">
                                {{ $announcement->title }}
                            </a>
                            @if (in_array($announcement->id, $unreadIds ?? [], true))
                                <span class="badge text-bg-danger ms-1">未読</span>
                            @endif
                        </td>
                        <td class="small">{{ $announcement->published_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                        <td class="small">
                            @foreach ($announcement->targets as $target)
                                {{ $target->target_type->label() }}@if ($target->target_id)#{{ $target->target_id }}@endif
                                @if (! $loop->last)、@endif
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-muted">お知らせはありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
