<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Services\AnnouncementService;
use App\Http\Controllers\Concerns\ConfirmsAnnouncementDeletion;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\BusinessPartner;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class AnnouncementController extends Controller
{
    use ConfirmsAnnouncementDeletion;

    public function index(Request $request, AnnouncementService $service, RbacService $rbac): View
    {
        $actor = $request->user('admin');
        $canManage = $rbac->hasPermission($actor, 'announcement.manage');

        return view('admin.announcements.index', [
            'announcements' => $canManage
                ? $service->manageableList($actor)
                : $service->visibleQuery($actor)->limit(50)->get(),
            'routePrefix' => 'admin',
            'canManage' => $canManage,
            'unreadIds' => [],
        ]);
    }

    public function create(Request $request, RbacService $rbac): View
    {
        abort_unless($rbac->hasPermission($request->user('admin'), 'announcement.manage'), 403);

        return view('admin.announcements.create', [
            'routePrefix' => 'admin',
            'customers' => Customer::query()->orderBy('code')->get(),
            'businessPartners' => BusinessPartner::query()->orderBy('code')->get(),
            'allowAll' => true,
        ]);
    }

    public function store(Request $request, AnnouncementService $service): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'target_type' => ['required', 'in:all,bp,customer'],
            'target_id' => ['nullable', 'integer'],
        ]);

        $targets = [[
            'type' => $validated['target_type'],
            'id' => $validated['target_type'] === 'all' ? null : ($validated['target_id'] ?? null),
        ]];

        try {
            $announcement = $service->publish(
                $request->user('admin'),
                $validated['title'],
                $validated['body'],
                $targets,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['title' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.announcements.show', $announcement)
            ->with('status', 'お知らせを公開しました。');
    }

    public function show(Request $request, Announcement $announcement, AnnouncementService $service, RbacService $rbac): View
    {
        $actor = $request->user('admin');
        if ($rbac->hasPermission($actor, 'announcement.manage')) {
            // ok
        } elseif (! $service->isVisibleTo($actor, $announcement)) {
            abort(403);
        }

        $service->markRead($actor, $announcement);

        return view('admin.announcements.show', [
            'announcement' => $announcement->load('targets'),
            'routePrefix' => 'admin',
            'canManage' => $rbac->hasPermission($actor, 'announcement.manage'),
            'deleteConfirmationCode' => $rbac->hasPermission($actor, 'announcement.manage')
                ? $this->issueAnnouncementDeleteConfirmationCode($announcement)
                : null,
        ]);
    }

    public function destroy(Request $request, Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        $this->assertAnnouncementDeleteConfirmation($request, $announcement);
        $service->delete($request->user('admin'), $announcement);

        return redirect()
            ->route('admin.announcements.index')
            ->with('status', 'お知らせを削除しました。');
    }
}
