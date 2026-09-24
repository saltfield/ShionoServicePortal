<?php

namespace App\Http\Controllers\Customer;

use App\Domains\Contract\Services\ContractService;
use App\Domains\Support\Services\AnnouncementService;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        InquiryService $inquiries,
        AnnouncementService $announcements,
        ContractService $contracts,
    ): View {
        $actor = $request->user('customer');

        return view('customer.dashboard', [
            'unreadTicketCount' => $inquiries->unreadIssuedCount($actor),
            'unreadAnnouncementCount' => $announcements->unreadCount($actor),
            'unreadContractMessageCount' => $contracts->unreadMessageContractCount(
                $actor,
                null,
                $actor->customer_id ? (int) $actor->customer_id : null,
            ),
        ]);
    }
}
