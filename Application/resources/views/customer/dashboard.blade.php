@extends('layouts.app')

@section('title', 'カスタマーダッシュボード')
@section('area', 'カスタマー')

@section('content')
    <h1 class="h3 mb-3">カスタマーダッシュボード</h1>
    <p class="text-muted mb-3">CN: {{ auth('customer')->user()->customer?->code }}</p>

    <div class="row g-3">
        <div class="col-md-6">
            <a href="{{ route('customer.inquiries.index') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 bg-white h-100">
                    <div class="small text-muted">オープン問い合わせ</div>
                    <div class="fs-4">{{ $openInquiryCount }}</div>
                </div>
            </a>
        </div>
        <div class="col-md-6">
            <a href="{{ route('customer.announcements.index') }}" class="text-decoration-none text-dark">
                <div class="border rounded p-3 bg-white h-100">
                    <div class="small text-muted">未読お知らせ</div>
                    <div class="fs-4">{{ $unreadAnnouncementCount }}</div>
                </div>
            </a>
        </div>
    </div>
@endsection
