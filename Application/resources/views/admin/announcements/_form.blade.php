@php
    $prefix = $routePrefix ?? 'admin';
    $isEdit = ($announcement ?? null) !== null;
    $allowBroadcast = $allowBroadcast ?? ($allowAll ?? false);
    $selectedType = old('delivery_type', $selectedDeliveryType ?? ($allowBroadcast ? 'all' : 'customer'));
    $selectedTargetIds = collect(old('target_ids', $selectedTargetIds ?? []))->map(fn ($id) => (int) $id)->all();
    $publishedMode = old('published_mode', $publishedMode ?? 'immediate');
    $expiresMode = old('expires_mode', $expiresMode ?? 'indefinite');
    $includeNew = (bool) old('include_new_registrations', $includeNewRegistrations ?? true);
    $publishedDate = old('published_date', $publishedDate ?? '');
    $publishedTime = old('published_time', $publishedTime ?? '');
    $expiresDate = old('expires_date', $expiresDate ?? '');
    $expiresTime = old('expires_time', $expiresTime ?? '');
@endphp

@include('partials.breadcrumb', [
    'crumbs' => [['label' => 'お知らせ', 'url' => route($prefix.'.announcements.index')]],
    'current' => $isEdit ? 'お知らせ編集' : 'お知らせ登録',
])

<h1 class="h3 mb-3">{{ $isEdit ? 'お知らせ編集' : 'お知らせ登録' }}</h1>
@if ($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<form
    method="POST"
    action="{{ $isEdit ? route($prefix.'.announcements.update', $announcement) : route($prefix.'.announcements.store') }}"
    class="card card-body"
    style="max-width:48rem"
    id="announcementForm"
>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="mb-3">
        <label class="form-label" for="title">タイトル</label>
        <input type="text" name="title" id="title" class="form-control" value="{{ old('title', $announcement->title ?? '') }}" required>
    </div>
    <div class="mb-3">
        <label class="form-label" for="body">本文</label>
        <textarea name="body" id="body" class="form-control" rows="6" required>{{ old('body', $announcement->body ?? '') }}</textarea>
    </div>

    <div class="mb-3">
        <label class="form-label" for="delivery_type">配信種別</label>
        <select name="delivery_type" id="delivery_type" class="form-select" required>
            @if ($allowBroadcast)
                <option value="all" @selected($selectedType === 'all')>全BPと全カスタマー</option>
                <option value="all_bp" @selected($selectedType === 'all_bp')>全BPのみ</option>
                <option value="all_customer" @selected($selectedType === 'all_customer')>全カスタマーのみ</option>
            @endif
            <option value="bp" @selected($selectedType === 'bp')>BP（個別選択）</option>
            <option value="customer" @selected($selectedType === 'customer')>カスタマー（個別選択）</option>
        </select>
    </div>

    <div class="mb-3 @if (! in_array($selectedType, ['all', 'all_bp', 'all_customer'], true)) d-none @endif" id="includeNewWrap">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="include_new_registrations" value="1" id="include_new_registrations" @checked($includeNew)>
            <label class="form-check-label" for="include_new_registrations">
                今後新規登録されるBP / カスタマーにも配信する
            </label>
        </div>
        <div class="form-text">オフの場合、現時点の対象組織のみに固定します。</div>
    </div>

    <div class="mb-3 @if ($selectedType !== 'bp') d-none @endif" id="bpTargetsWrap">
        <label class="form-label">配信先一覧（BP）</label>
        <div class="border rounded p-2" style="max-height:16rem; overflow:auto">
            @forelse ($businessPartners as $bp)
                <div class="form-check">
                    <input
                        class="form-check-input js-bp-target"
                        type="checkbox"
                        name="target_ids[]"
                        value="{{ $bp->id }}"
                        id="bp_target_{{ $bp->id }}"
                        @checked(in_array((int) $bp->id, $selectedTargetIds, true))
                        @disabled($selectedType !== 'bp')
                    >
                    <label class="form-check-label" for="bp_target_{{ $bp->id }}">
                        <code>{{ $bp->code }}</code> / {{ $bp->name }}
                    </label>
                </div>
            @empty
                <p class="text-muted small mb-0">選択可能なBPがありません。</p>
            @endforelse
        </div>
    </div>

    <div class="mb-3 @if ($selectedType !== 'customer') d-none @endif" id="customerTargetsWrap">
        <label class="form-label">配信先一覧（カスタマー）</label>
        <div class="border rounded p-2" style="max-height:16rem; overflow:auto">
            @forelse ($customers as $customer)
                <div class="form-check">
                    <input
                        class="form-check-input js-customer-target"
                        type="checkbox"
                        name="target_ids[]"
                        value="{{ $customer->id }}"
                        id="customer_target_{{ $customer->id }}"
                        @checked(in_array((int) $customer->id, $selectedTargetIds, true))
                        @disabled($selectedType !== 'customer')
                    >
                    <label class="form-check-label" for="customer_target_{{ $customer->id }}">
                        <code>{{ $customer->code }}</code> / {{ $customer->name }}
                    </label>
                </div>
            @empty
                <p class="text-muted small mb-0">選択可能なカスタマーがありません。</p>
            @endforelse
        </div>
    </div>

    <fieldset class="mb-3">
        <legend class="form-label">公開開始</legend>
        <div class="form-check">
            <input class="form-check-input js-published-mode" type="radio" name="published_mode" id="published_immediate" value="immediate" @checked($publishedMode === 'immediate')>
            <label class="form-check-label" for="published_immediate">即時</label>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input js-published-mode" type="radio" name="published_mode" id="published_scheduled" value="scheduled" @checked($publishedMode === 'scheduled')>
            <label class="form-check-label" for="published_scheduled">予定日時を指定</label>
        </div>
        <div class="row g-2 @if ($publishedMode !== 'scheduled') d-none @endif" id="publishedScheduleWrap">
            <div class="col-md-5">
                <label class="form-label small" for="published_date">日付</label>
                <input type="date" name="published_date" id="published_date" class="form-control form-control-sm" value="{{ $publishedDate }}">
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="published_time">時刻（任意）</label>
                <input type="time" name="published_time" id="published_time" class="form-control form-control-sm" value="{{ $publishedTime }}">
            </div>
        </div>
    </fieldset>

    <fieldset class="mb-4">
        <legend class="form-label">公開期間（終了）</legend>
        <div class="form-check">
            <input class="form-check-input js-expires-mode" type="radio" name="expires_mode" id="expires_indefinite" value="indefinite" @checked($expiresMode === 'indefinite')>
            <label class="form-check-label" for="expires_indefinite">無期限</label>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input js-expires-mode" type="radio" name="expires_mode" id="expires_until" value="until" @checked($expiresMode === 'until')>
            <label class="form-check-label" for="expires_until">終了日時を指定</label>
        </div>
        <div class="row g-2 @if ($expiresMode !== 'until') d-none @endif" id="expiresScheduleWrap">
            <div class="col-md-5">
                <label class="form-label small" for="expires_date">日付</label>
                <input type="date" name="expires_date" id="expires_date" class="form-control form-control-sm" value="{{ $expiresDate }}">
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="expires_time">時刻（任意）</label>
                <input type="time" name="expires_time" id="expires_time" class="form-control form-control-sm" value="{{ $expiresTime }}">
            </div>
        </div>
    </fieldset>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">{{ $isEdit ? '更新' : '保存' }}</button>
        <a href="{{ route($prefix.'.announcements.index') }}" class="btn btn-outline-secondary">キャンセル</a>
    </div>
</form>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const deliveryType = document.getElementById('delivery_type');
        const includeNewWrap = document.getElementById('includeNewWrap');
        const bpWrap = document.getElementById('bpTargetsWrap');
        const customerWrap = document.getElementById('customerTargetsWrap');
        const bpInputs = document.querySelectorAll('.js-bp-target');
        const customerInputs = document.querySelectorAll('.js-customer-target');
        const publishedScheduleWrap = document.getElementById('publishedScheduleWrap');
        const expiresScheduleWrap = document.getElementById('expiresScheduleWrap');

        const syncDelivery = () => {
            const type = deliveryType?.value;
            const isBroadcast = ['all', 'all_bp', 'all_customer'].includes(type);
            includeNewWrap?.classList.toggle('d-none', !isBroadcast);
            bpWrap?.classList.toggle('d-none', type !== 'bp');
            customerWrap?.classList.toggle('d-none', type !== 'customer');
            bpInputs.forEach((input) => { input.disabled = type !== 'bp'; });
            customerInputs.forEach((input) => { input.disabled = type !== 'customer'; });
        };

        const syncPublished = () => {
            const mode = document.querySelector('.js-published-mode:checked')?.value;
            publishedScheduleWrap?.classList.toggle('d-none', mode !== 'scheduled');
        };

        const syncExpires = () => {
            const mode = document.querySelector('.js-expires-mode:checked')?.value;
            expiresScheduleWrap?.classList.toggle('d-none', mode !== 'until');
        };

        deliveryType?.addEventListener('change', syncDelivery);
        document.querySelectorAll('.js-published-mode').forEach((el) => el.addEventListener('change', syncPublished));
        document.querySelectorAll('.js-expires-mode').forEach((el) => el.addEventListener('change', syncExpires));
        syncDelivery();
        syncPublished();
        syncExpires();
    });
</script>
@endpush
