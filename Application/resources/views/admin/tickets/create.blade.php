@extends('layouts.app')

@section('title', 'チケット発行')
@section('area')
    {{ ($routePrefix ?? '') === 'customer' ? 'カスタマー' : 'BP' }}
@endsection
@section('logout_action', route(($routePrefix ?? 'bp').'.logout'))

@section('content')
    <nav class="ssp-breadcrumb" aria-label="breadcrumb">
        <ol class="breadcrumb small mb-2">
            <li class="breadcrumb-item"><a href="{{ route(($routePrefix ?? 'bp').'.tickets.issued') }}">発行チケット</a></li>
            <li class="breadcrumb-item active" aria-current="page">チケット発行</li>
        </ol>
    </nav>
    <h1 class="ssp-page-title mb-3">チケット発行</h1>
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route(($routePrefix ?? 'bp').'.tickets.store') }}" enctype="multipart/form-data" class="card card-body" style="max-width:40rem">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="subject">件名</label>
            <input type="text" name="subject" id="subject" class="form-control" value="{{ old('subject') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="body">本文</label>
            <textarea name="body" id="body" class="form-control" rows="5" required>{{ old('body') }}</textarea>
        </div>
        <div class="mb-3">
            <label class="form-label" for="visibility">公開範囲</label>
            <select name="visibility" id="visibility" class="form-select" required>
                <option value="organization" @selected(old('visibility', 'organization') === 'organization')>自組織全体公開</option>
                <option value="private" @selected(old('visibility') === 'private')>自分のみ表示</option>
            </select>
        </div>
        @if (($routePrefix ?? '') === 'bp')
            @php
                $oldProxy = old('proxy_enabled');
                $oldTarget = old('proxy_target', '');
                $proxyChecked = $oldProxy === '1' || $oldProxy === 1 || $oldProxy === true || ($oldTarget !== '' && $oldTarget !== null);
            @endphp
            <div class="mb-3">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="proxy_enabled" id="proxy_enabled" value="1" @checked($proxyChecked)>
                    <label class="form-check-label" for="proxy_enabled">代理起票</label>
                </div>
            </div>
            <div class="mb-3" id="proxy_target_wrap" @if (! $proxyChecked) style="display:none" @endif>
                <label class="form-label" for="proxy_target">代理対象</label>
                <select name="proxy_target" id="proxy_target" class="form-select" @if ($proxyChecked) required @endif>
                    <option value="">選択してください</option>
                    @if (($childBusinessPartners ?? collect())->isNotEmpty())
                        <optgroup label="配下BP">
                            @foreach ($childBusinessPartners as $bp)
                                <option value="bp:{{ $bp->id }}" @selected($oldTarget === 'bp:'.$bp->id)>
                                    {{ $bp->code }} / {{ $bp->name }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if (($customers ?? collect())->isNotEmpty())
                        <optgroup label="カスタマー">
                            @foreach ($customers as $customer)
                                <option value="customer:{{ $customer->id }}" @selected($oldTarget === 'customer:'.$customer->id)>
                                    {{ $customer->code }} / {{ $customer->name }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </div>
        @endif
        <div class="mb-3">
            <label class="form-label" for="attachments">添付（任意・最大5・各10MB）</label>
            <input type="file" name="attachments[]" id="attachments" class="form-control" multiple
                   accept=".png,.jpg,.jpeg,.gif,.heic,.heif,.pdf,image/png,image/jpeg,image/gif,image/heic,image/heif,application/pdf">
            <div class="form-text">PNG / JPEG / GIF / HEIC / HEIF / PDF</div>
        </div>
        <button class="btn btn-primary" type="submit">発行</button>
    </form>
@endsection

@if (($routePrefix ?? '') === 'bp')
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const checkbox = document.getElementById('proxy_enabled');
        const wrap = document.getElementById('proxy_target_wrap');
        const select = document.getElementById('proxy_target');
        if (!checkbox || !wrap || !select) return;
        const sync = () => {
            const on = checkbox.checked;
            wrap.style.display = on ? '' : 'none';
            select.required = on;
            if (!on) select.value = '';
        };
        checkbox.addEventListener('change', sync);
        sync();
    });
</script>
@endpush
@endif
