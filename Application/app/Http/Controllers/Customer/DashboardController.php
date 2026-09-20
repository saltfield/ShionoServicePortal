<?php

namespace App\Http\Controllers\Customer;

use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Services\AnnouncementService;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, InquiryService $inquiries, AnnouncementService $announcements): View
    {
        $actor = $request->user('customer');

        return view('customer.dashboard', [
            'openInquiryCount' => $inquiries->visibleQuery($actor)
                ->whereIn('status', [InquiryStatus::Open->value, InquiryStatus::InProgress->value])
                ->count(),
            'unreadAnnouncementCount' => $announcements->unreadCount($actor),
        ]);
    }
}
