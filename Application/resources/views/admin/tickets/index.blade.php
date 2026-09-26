@extends('layouts.app')

@section('title', $pageTitle ?? 'チケット一覧')
@section('area')
    {{ ($routePrefix ?? 'admin') === 'admin' ? '管理者' : (($routePrefix ?? '') === 'customer' ? 'カスタマー' : 'BP') }}
@endsection
@section('logout_action', route(($routePrefix ?? 'admin').'.logout'))

@section('content')
    @php
        $listRoute = ($listMode ?? 'received') === 'issued'
            ? ($routePrefix ?? 'admin').'.tickets.issued'
            : ($routePrefix ?? 'admin').'.tickets.received';
        $filters = $filters ?? ['include_closed' => false, 'q' => ''];
    @endphp
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="ssp-page-title mb-0">{{ $pageTitle ?? 'チケット一覧' }}</h1>
        <div class="d-flex gap-2">
            @if (($canCreate ?? false) && ($listMode ?? '') === 'issued')
                <a href="{{ route(($routePrefix ?? 'admin').'.tickets.create') }}" class="btn btn-primary btn-sm">チケット発行</a>
            @endif
        </div>
    </div>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="GET" action="{{ route($listRoute) }}" class="row g-2 align-items-end mb-3">
        <div class="col-md-5">
            <label class="form-label small mb-1" for="q">検索</label>
            <input type="text" id="q" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm"
                   placeholder="番号・件名・BPN/CN・名称">
        </div>
        <div class="col-auto">
            <div class="form-check mb-1">
                <input type="checkbox" class="form-check-input" id="include_closed" name="include_closed" value="1"
                       @checked($filters['include_closed']) onchange="this.form.submit()">
                <label class="form-check-label small" for="include_closed">クローズ済みを表示</label>
            </div>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary btn-sm" type="submit">検索</button>
            <a href="{{ route($listRoute) }}" class="btn btn-outline-secondary btn-sm">クリア</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>番号</th>
                    <th>件名</th>
                    <th>状態</th>
                    <th>公開範囲</th>
                    <th>発行元（BPN/CN）</th>
                    <th>対応先</th>
                    <th>更新</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($inquiries as $inquiry)
                    <tr>
                        <td class="small font-monospace">{{ $inquiry->code }}</td>
                        <td>
                            <a href="{{ route(($routePrefix ?? 'admin').'.tickets.show', $inquiry) }}">
                                {{ $inquiry->subject }}
                            </a>
                        </td>
                        <td>{{ $inquiry->status->label() }}</td>
                        <td class="small">{{ $inquiry->visibility->label() }}</td>
                        <td class="small">{{ $inquiry->issuerLabel() }}</td>
                        <td class="small">
                            @if ($inquiry->assignee_type?->value === 'admin')
                                管理者
                            @else
                                {{ $inquiry->assigneeBp ? $inquiry->assigneeBp->code.' / '.$inquiry->assigneeBp->name : '—' }}
                            @endif
                        </td>
                        <td class="small text-muted">{{ $inquiry->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">チケットはありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $inquiries->links() }}
@endsection
