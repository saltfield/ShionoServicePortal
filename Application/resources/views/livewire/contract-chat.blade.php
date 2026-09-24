<div wire:poll.5s>
    <div class="border rounded bg-white p-3 mb-3" style="max-height: 28rem; overflow-y: auto;">
        @forelse ($contract->messages as $message)
            @php
                $alignEnd = $message->user_id === auth($routePrefix)->id();
                $bubbleClass = $alignEnd ? 'bg-primary text-white' : 'bg-light';
            @endphp
            <div class="mb-3 {{ $alignEnd ? 'text-end' : '' }}">
                <div class="small text-muted mb-1">
                    {{ $message->user?->name ?: $message->user?->login_id ?: '不明' }}
                    · {{ $message->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                </div>
                <div class="d-inline-block text-start px-3 py-2 rounded {{ $bubbleClass }}" style="max-width: 80%; white-space: pre-wrap;">{{ $message->body }}</div>
            </div>
        @empty
            <div class="text-muted small">メッセージはまだありません。</div>
        @endforelse
    </div>

    @if ($canPost)
        <form wire:submit="send" class="d-flex flex-column gap-2">
            <textarea wire:model="body" class="form-control" rows="2" maxlength="2000" placeholder="メッセージを入力"></textarea>
            @error('body') <div class="text-danger small">{{ $message }}</div> @enderror
            <div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">送信</button>
            </div>
        </form>
    @else
        <div class="alert alert-secondary py-2 mb-0">サービス提供開始後はメッセージを投稿できません（閲覧のみ）。</div>
    @endif
</div>
