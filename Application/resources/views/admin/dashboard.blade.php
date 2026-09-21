@extends('layouts.app')

@section('title', '管理者ダッシュボード')
@section('area', '管理者')

@section('content')
    <h1 class="h3 mb-3">管理者ダッシュボード</h1>

    <div class="row g-3">
        <div class="col-md-3">
            <a href="{{ route('admin.tickets.received') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 bg-white h-100">
                    <div class="small text-muted">未読チケット</div>
                    <div class="fs-4">{{ $unreadTicketCount }}</div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="{{ route('admin.announcements.index') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 bg-white h-100">
                    <div class="small text-muted">未読お知らせ</div>
                    <div class="fs-4">{{ $unreadAnnouncementCount }}</div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="{{ route('admin.applications.index') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 bg-white h-100">
                    <div class="small text-muted">価格申請（未決裁）</div>
                    <div class="fs-4">{{ $pendingApplicationCount }}</div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="{{ route('admin.contracts.index') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 bg-white h-100">
                    <div class="small text-muted">下書き契約</div>
                    <div class="fs-4">{{ $draftContractCount }}</div>
                </div>
            </a>
        </div>
    </div>
@endsection
