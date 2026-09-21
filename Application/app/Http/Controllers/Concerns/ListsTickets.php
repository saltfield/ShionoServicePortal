<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Support\Services\InquiryService;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

trait ListsTickets
{
    /**
     * @return array{include_closed: bool, q: string}
     */
    protected function ticketListFilters(Request $request): array
    {
        return [
            'include_closed' => $request->boolean('include_closed'),
            'q' => trim((string) $request->input('q', '')),
        ];
    }

    /**
     * @return LengthAwarePaginator<\App\Models\Inquiry>
     */
    protected function paginateTicketList(
        Request $request,
        InquiryService $service,
        User $actor,
        string $mode,
    ): LengthAwarePaginator {
        $filters = $this->ticketListFilters($request);
        $query = $mode === 'issued'
            ? $service->issuedQuery($actor)
            : $service->receivedQuery($actor);

        $service->applyListFilters($query, $filters['include_closed'], $filters['q']);

        return $query->latest('updated_at')->paginate(20)->withQueryString();
    }
}
