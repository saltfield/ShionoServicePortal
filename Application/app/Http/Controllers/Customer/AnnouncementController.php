<?php

namespace App\Http\Controllers\Customer;

use App\Domains\Support\Services\AnnouncementService;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(Request $request, AnnouncementService $service): View
    {
        $actor = $request->user('customer');
        $announcements = $service->visibleQuery($actor)->limit(50)->get();
        $unreadIds = $announcements
            ->filter(fn (Announcement $a) => ! $a->reads()->where('user_id', $actor->id)->exists())
            ->pluck('id')
            ->all();

        return view('admin.announcements.index', [
            'announcements' => $announcements,
            'routePrefix' => 'customer',
            'canManage' => false,
            'unreadIds' => $unreadIds,
        ]);
    }

    public function show(Request $request, Announcement $announcement, AnnouncementService $service): View
    {
        $actor = $request->user('customer');
        abort_unless($service->isVisibleTo($actor, $announcement), 403);
        $service->markRead($actor, $announcement);

        return view('admin.announcements.show', [
            'announcement' => $announcement->load('targets'),
            'routePrefix' => 'customer',
            'canManage' => false,
            'deleteConfirmationCode' => null,
        ]);
    }
}
