<div wire:poll.5s>
    @if ($readOnly ?? false)
        <div class="d-flex flex-wrap gap-3 small text-muted mb-2">
            <span><span class="d-inline-block rounded px-2 py-1 bg-light border me-1">起票側</span>グレー</span>
            <span><span class="d-inline-block rounded px-2 py-1 me-1" style="background-color:#cfe2ff;">受領側</span>薄い青</span>
        </div>
    @endif
    <div class="border rounded bg-white p-3 mb-3" style="max-height: 28rem; overflow-y: auto;">
        @forelse ($inquiry->messages as $message)
            @php
                $isStatus = $message->message_type?->value === $statusChangeType;
                if ($readOnly ?? false) {
                    $isAssigneeMessage = ! $isStatus && ($assigneeUserIds[$message->user_id] ?? false);
                    $bubbleClass = $isAssigneeMessage ? '' : 'bg-light';
                    $bubbleStyle = $isAssigneeMessage ? 'background-color:#cfe2ff;' : '';
                    $alignEnd = $isAssigneeMessage;
                } else {
                    $alignEnd = ! $isStatus && $message->user_id === auth($routePrefix)->id();
                    $bubbleClass = $alignEnd ? 'bg-primary text-white' : 'bg-light';
                    $bubbleStyle = '';
                }
            @endphp
            @if ($isStatus)
                <div class="mb-3 text-center">
                    <div class="d-inline-block small text-muted px-3 py-2 rounded bg-light border">
                        {{ $message->body }}
                        <span class="ms-1">· {{ $message->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                    </div>
                </div>
            @else
                <div class="mb-3 {{ $alignEnd ? 'text-end' : '' }}">
                    <div class="small text-muted mb-1">
                        {{ $message->user?->name ?: $message->user?->login_id }}
                        @if ($readOnly ?? false)
                            <span class="badge {{ $alignEnd ? 'text-bg-primary' : 'text-bg-secondary' }}">{{ $alignEnd ? '受領側' : '起票側' }}</span>
                        @endif
                        · {{ $message->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                    </div>
                    <div class="d-inline-block text-start px-3 py-2 rounded {{ $bubbleClass }}" style="max-width: 80%; white-space: pre-wrap; {{ $bubbleStyle }}">{{ $message->body }}</div>
                    @if ($message->attachments->isNotEmpty())
                        <div class="mt-2 {{ $alignEnd ? 'text-end' : '' }}">
                            @foreach ($message->attachments as $attachment)
                                <div class="mb-1">
                                    @if ($attachment->isPreviewableImage() && $attachment->existsOnDisk())
                                        <a href="{{ route($routePrefix.'.tickets.attachments.download', [$inquiry, $attachment]) }}" target="_blank" rel="noopener">
                                            <img src="{{ route($routePrefix.'.tickets.attachments.download', [$inquiry, $attachment]) }}" alt="{{ $attachment->original_name }}" class="img-fluid rounded border" style="max-height: 12rem;">
                                        </a>
                                    @else
                                        <a href="{{ route($routePrefix.'.tickets.attachments.download', [$inquiry, $attachment]) }}" class="small">
                                            {{ $attachment->original_name }}
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        @empty
            <div class="text-muted small">メッセージはまだありません。</div>
        @endforelse
    </div>

    @if ($readOnly ?? false)
        <div class="alert alert-secondary py-2">閲覧のみです。メッセージの送信はできません。</div>
    @elseif ($isWithdrawn)
        <div class="alert alert-secondary py-2">取下げ済みです。メッセージは送信できません。</div>
    @elseif ($isClosed)
        <div class="alert alert-secondary py-2">クローズ済みです。返信するには再オープンが必要です。</div>
    @elseif ($canReply)
        <form wire:submit="send" class="d-flex flex-column gap-2">
            <textarea wire:model="body" class="form-control" rows="2" placeholder="メッセージを入力（添付のみでも可）"></textarea>
            @error('body') <div class="text-danger small">{{ $message }}</div> @enderror
            <div>
                <input type="file" wire:model="attachments" class="form-control form-control-sm" multiple
                       accept=".png,.jpg,.jpeg,.gif,.heic,.heif,.pdf,image/png,image/jpeg,image/gif,image/heic,image/heif,application/pdf">
                <div class="form-text">添付は最大5・各10MB（PNG / JPEG / GIF / HEIC / HEIF / PDF）</div>
                @error('attachments') <div class="text-danger small">{{ $message }}</div> @enderror
                @error('attachments.*') <div class="text-danger small">{{ $message }}</div> @enderror
                <div wire:loading wire:target="attachments" class="small text-muted">アップロード中…</div>
            </div>
            <div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="send,attachments">送信</button>
            </div>
        </form>
    @endif
</div>
