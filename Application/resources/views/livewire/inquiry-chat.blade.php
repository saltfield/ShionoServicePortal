<div wire:poll.5s>
    <div class="border rounded bg-white p-3 mb-3" style="max-height: 28rem; overflow-y: auto;">
        @foreach ($inquiry->messages as $message)
            @php
                $mine = $message->user_id === auth($routePrefix)->id();
            @endphp
            <div class="mb-3 {{ $mine ? 'text-end' : '' }}">
                <div class="small text-muted mb-1">
                    {{ $message->user?->name ?: $message->user?->login_id }}
                    · {{ $message->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                </div>
                <div class="d-inline-block text-start px-3 py-2 rounded {{ $mine ? 'bg-primary text-white' : 'bg-light' }}" style="max-width: 80%; white-space: pre-wrap;">{{ $message->body }}</div>
            </div>
        @endforeach
    </div>

    @if ($isClosed)
        <div class="alert alert-secondary py-2">クローズ済みです。返信するには再オープンが必要です。</div>
    @else
        <form wire:submit="send" class="d-flex gap-2 align-items-start">
            <div class="flex-grow-1">
                <textarea wire:model="body" class="form-control" rows="2" placeholder="メッセージを入力" required></textarea>
                @error('body') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            </div>
            <button type="submit" class="btn btn-primary">送信</button>
        </form>
    @endif
</div>
