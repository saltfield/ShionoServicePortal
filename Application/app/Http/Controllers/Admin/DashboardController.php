<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Contract\Enums\ApplicationStatus;
use App\Domains\Contract\Enums\ContractStatus;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Services\AnnouncementService;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, InquiryService $inquiries, AnnouncementService $announcements): View
    {
        $actor = $request->user('admin');

        return view('admin.dashboard', [
            'openInquiryCount' => $inquiries->visibleQuery($actor)
                ->whereIn('status', [InquiryStatus::Open->value, InquiryStatus::InProgress->value])
                ->count(),
            'unreadAnnouncementCount' => $announcements->unreadCount($actor),
            'pendingApplicationCount' => Application::query()
                ->where('status', ApplicationStatus::Pending->value)
                ->count(),
            'draftContractCount' => Contract::query()
                ->where('status', ContractStatus::Draft->value)
                ->count(),
        ]);
    }
}
