<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Services\BpHierarchyService;
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
        BpHierarchyService $hierarchy,
        ContractService $contracts,
    ): View {
        $actor = $request->user('bp');
        $bpIds = $actor->businessPartner
            ? $hierarchy->descendantIdsIncludingSelf($actor->businessPartner)
            : [];

        $scopedContracts = fn () => Contract::query()->whereIn('owning_bp_id', $bpIds);

        return view('bp.dashboard', [
            'unreadTicketCount' => $inquiries->unreadCount($actor),
            'unreadAnnouncementCount' => $announcements->unreadCount($actor),
            'unreadContractMessageCount' => $contracts->unreadMessageContractCount($actor, $bpIds),
            'awaitingApplicationCount' => $scopedContracts()
                ->where('status', ContractStatus::Draft->value)
                ->count(),
            'pendingPriceApprovalCount' => $scopedContracts()
                ->where('status', ContractStatus::PendingPriceApproval->value)
                ->count(),
            'serviceArrangementCount' => $scopedContracts()
                ->where('status', ContractStatus::Approved->value)
                ->count(),
        ]);
    }
}
