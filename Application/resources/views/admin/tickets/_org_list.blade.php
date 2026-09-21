@php
    $tickets = $tickets ?? collect();
    $inspectBack = $inspectBack ?? [];
@endphp
<div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
        <thead>
        <tr>
            <th>番号</th>
            <th>件名</th>
            <th>状態</th>
            <th>発行元</th>
            <th>対応先</th>
            <th>更新</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse ($tickets as $ticket)
            <tr>
                <td class="small font-monospace">{{ $ticket->code }}</td>
                <td>{{ $ticket->subject }}</td>
                <td>{{ $ticket->status->label() }}</td>
                <td class="small">{{ $ticket->issuerLabel() }}</td>
                <td class="small">
                    @if ($ticket->assignee_type?->value === 'admin')
                        管理者
                    @else
                        {{ $ticket->assigneeBp ? $ticket->assigneeBp->code.' / '.$ticket->assigneeBp->name : '—' }}
                    @endif
                </td>
                <td class="small text-muted">{{ $ticket->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                <td class="text-end">
                    <a
                        href="{{ route('admin.tickets.inspect', array_merge(['inquiry' => $ticket], $inspectBack)) }}"
                        class="btn btn-outline-secondary btn-sm"
                    >確認</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-muted text-center">チケットはありません。</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@if (method_exists($tickets, 'links'))
    {{ $tickets->links() }}
@endif
