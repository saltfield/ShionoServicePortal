@php
    $sharedNote = $sharedNote ?? null;
    $organizationNote = $organizationNote ?? null;
    $canEditNotes = $canEditNotes ?? false;
    $notesSubjectLabel = $notesSubjectLabel ?? '対象';
    $sharedNoteRoute = $sharedNoteRoute ?? null;
    $organizationNoteRoute = $organizationNoteRoute ?? null;
    $maxLen = \App\Domains\Support\Services\EntityNoteService::MAX_BODY_LENGTH;
    $tz = config('app.timezone');
@endphp

<div class="row g-3" style="max-width:48rem">
    <div class="col-12">
        <div class="card" data-note-panel="shared">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <h2 class="h6 mb-1">共有備考</h2>
                        <p class="small text-muted mb-0">この{{ $notesSubjectLabel }}を見られるユーザー全員が確認できます。</p>
                    </div>
                    @if ($canEditNotes)
                        <button type="button" class="btn btn-outline-primary btn-sm js-note-edit" data-target="shared">編集</button>
                    @endif
                </div>
                <div class="small text-muted mb-2 js-note-meta">
                    @if ($sharedNote?->updated_at)
                        最終更新: {{ $sharedNote->updated_at->timezone($tz)->format('Y-m-d H:i') }}
                        @if ($sharedNote->updatedBy)
                            （{{ $sharedNote->updatedBy->login_id }}）
                        @endif
                    @else
                        最終更新: —
                    @endif
                </div>
                <div class="js-note-view border rounded p-3 bg-light" style="white-space: pre-wrap; min-height: 4rem;">@if (filled($sharedNote?->body)){{ $sharedNote->body }}@else<span class="text-muted">（未登録）</span>@endif</div>
                @if ($canEditNotes && $sharedNoteRoute)
                    <form method="POST" action="{{ $sharedNoteRoute }}" class="js-note-edit-form d-none mt-3" data-confirm-shared="1">
                        @csrf
                        @method('PUT')
                        @if (! empty($returnCustomerId))
                            <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
                        @endif
                        <label class="form-label" for="shared_note_body">本文（最大 {{ number_format($maxLen) }} 文字）</label>
                        <textarea name="body" id="shared_note_body" class="form-control" rows="8" maxlength="{{ $maxLen }}">{{ old('body', $sharedNote?->body) }}</textarea>
                        <div class="d-flex gap-2 mt-2">
                            <button type="submit" class="btn btn-primary btn-sm">保存</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm js-note-cancel">キャンセル</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card" data-note-panel="organization">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <h2 class="h6 mb-1">組織内備考</h2>
                        <p class="small text-muted mb-0">自組織のユーザーのみが確認できます（他組織には表示されません）。</p>
                    </div>
                    @if ($canEditNotes)
                        <button type="button" class="btn btn-outline-primary btn-sm js-note-edit" data-target="organization">編集</button>
                    @endif
                </div>
                <div class="small text-muted mb-2 js-note-meta">
                    @if ($organizationNote?->updated_at)
                        最終更新: {{ $organizationNote->updated_at->timezone($tz)->format('Y-m-d H:i') }}
                        @if ($organizationNote->updatedBy)
                            （{{ $organizationNote->updatedBy->login_id }}）
                        @endif
                    @else
                        最終更新: —
                    @endif
                </div>
                <div class="js-note-view border rounded p-3 bg-light" style="white-space: pre-wrap; min-height: 4rem;">@if (filled($organizationNote?->body)){{ $organizationNote->body }}@else<span class="text-muted">（未登録）</span>@endif</div>
                @if ($canEditNotes && $organizationNoteRoute)
                    <form method="POST" action="{{ $organizationNoteRoute }}" class="js-note-edit-form d-none mt-3">
                        @csrf
                        @method('PUT')
                        @if (! empty($returnCustomerId))
                            <input type="hidden" name="return_customer_id" value="{{ $returnCustomerId }}">
                        @endif
                        <label class="form-label" for="organization_note_body">本文（最大 {{ number_format($maxLen) }} 文字）</label>
                        <textarea name="body" id="organization_note_body" class="form-control" rows="8" maxlength="{{ $maxLen }}">{{ old('body', $organizationNote?->body) }}</textarea>
                        <div class="d-flex gap-2 mt-2">
                            <button type="submit" class="btn btn-primary btn-sm">保存</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm js-note-cancel">キャンセル</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

<dialog id="sharedNoteConfirmDialog" class="border-0 rounded-3 shadow p-0" style="max-width: 28rem; width: calc(100% - 2rem);">
    <div class="p-4">
        <h2 class="h5 mb-3">共有備考の保存確認</h2>
        <p class="mb-3">共有備考は、この{{ $notesSubjectLabel }}を見られるユーザー全員に表示されます。内容に問題がないか確認してから保存してください。</p>
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary" id="sharedNoteConfirmCancel">キャンセル</button>
            <button type="button" class="btn btn-primary" id="sharedNoteConfirmOk">保存する</button>
        </div>
    </div>
</dialog>

<script>
    (function () {
        const dialog = document.getElementById('sharedNoteConfirmDialog');
        const confirmOk = document.getElementById('sharedNoteConfirmOk');
        const confirmCancel = document.getElementById('sharedNoteConfirmCancel');
        let pendingForm = null;

        document.querySelectorAll('[data-note-panel]').forEach(function (panel) {
            const editBtn = panel.querySelector('.js-note-edit');
            const view = panel.querySelector('.js-note-view');
            const form = panel.querySelector('.js-note-edit-form');
            const cancelBtn = panel.querySelector('.js-note-cancel');
            if (!editBtn || !view || !form) {
                return;
            }
            editBtn.addEventListener('click', function () {
                view.classList.add('d-none');
                editBtn.classList.add('d-none');
                form.classList.remove('d-none');
            });
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    form.classList.add('d-none');
                    view.classList.remove('d-none');
                    editBtn.classList.remove('d-none');
                });
            }
            form.addEventListener('submit', function (event) {
                if (!form.dataset.confirmShared) {
                    return;
                }
                event.preventDefault();
                pendingForm = form;
                if (dialog && typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else if (window.confirm('共有備考は閲覧可能な全員に表示されます。保存しますか？')) {
                    form.submit();
                }
            });
        });

        if (confirmOk) {
            confirmOk.addEventListener('click', function () {
                if (pendingForm) {
                    pendingForm.submit();
                }
            });
        }
        if (confirmCancel && dialog) {
            confirmCancel.addEventListener('click', function () {
                dialog.close();
                pendingForm = null;
            });
        }
        if (dialog) {
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    dialog.close();
                    pendingForm = null;
                }
            });
        }
    })();
</script>
