<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Contract\Enums\ApplicationStatus;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Services\AnnouncementService;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        InquiryService $inquiries,
        AnnouncementService $announcements,
        BpHierarchyService $hierarchy,
    ): View {
        $actor = $request->user('bp');
        $bpIds = $actor->businessPartner
            ? $hierarchy->descendantIdsIncludingSelf($actor->businessPartner)
            : [];

        return view('bp.dashboard', [
            'openInquiryCount' => $inquiries->visibleQuery($actor)
                ->whereIn('status', [InquiryStatus::Open->value, InquiryStatus::InProgress->value])
                ->count(),
            'unreadAnnouncementCount' => $announcements->unreadCount($actor),
            'pendingApplicationCount' => Application::query()
                ->where('status', ApplicationStatus::Pending->value)
                ->whereIn('to_bp_id', $bpIds)
                ->count(),
        ]);
    }
}
