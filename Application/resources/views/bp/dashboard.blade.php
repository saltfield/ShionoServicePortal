@extends('layouts.app')

@section('title', 'BPダッシュボード')
@section('area', 'BP')

@section('content')
    <h1 class="h3 mb-3">BPダッシュボード</h1>
    <p class="text-muted mb-3">BPN: {{ auth('bp')->user()->businessPartner?->code }}</p>

    <div class="row g-3">
        <div class="col-md-4">
            <a href="{{ route('bp.tickets.received') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 bg-white h-100">
                    <div class="small text-muted">未読チケット</div>
                    <div class="fs-4">{{ $unreadTicketCount }}</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('bp.announcements.index') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 bg-white h-100">
                    <div class="small text-muted">未読お知らせ</div>
                    <div class="fs-4">{{ $unreadAnnouncementCount }}</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('bp.applications.index') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 bg-white h-100">
                    <div class="small text-muted">価格申請（承認待ち）</div>
                    <div class="fs-4">{{ $pendingApplicationCount }}</div>
                </div>
            </a>
        </div>
    </div>
@endsection
