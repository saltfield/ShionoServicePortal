<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Contract\Enums\ContractStatus;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Support\Services\AnnouncementService;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Controller;
use App\Models\Contract;
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
        $actor = $request->user('admin');

        return view('admin.dashboard', [
            'unreadTicketCount' => $inquiries->unreadReceivedCount($actor),
            'unreadAnnouncementCount' => $announcements->unreadCount($actor),
            'unreadContractMessageCount' => $contracts->unreadMessageContractCount($actor),
            'awaitingApplicationCount' => Contract::query()
                ->where('status', ContractStatus::Draft->value)
                ->count(),
            'pendingPriceApprovalCount' => Contract::query()
                ->where('status', ContractStatus::PendingPriceApproval->value)
                ->count(),
            'serviceArrangementCount' => Contract::query()
                ->where('status', ContractStatus::Approved->value)
                ->count(),
        ]);
    }
}
