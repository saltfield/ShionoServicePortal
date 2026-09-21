<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Services\AnnouncementService;
use App\Http\Controllers\Concerns\ConfirmsAnnouncementDeletion;
use App\Http\Controllers\Concerns\HandlesAnnouncementForm;
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
    use HandlesAnnouncementForm;

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
            'allowBroadcast' => true,
        ]);
    }

    public function store(Request $request, AnnouncementService $service): RedirectResponse
    {
        $payload = $this->validatedAnnouncementPayload($request, allowBroadcast: true);

        try {
            $announcement = $service->publish(
                $request->user('admin'),
                $payload['title'],
                $payload['body'],
                $payload['targets'],
                $payload['options'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['title' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.announcements.show', $announcement)
            ->with('status', 'お知らせを保存しました。');
    }

    public function edit(Request $request, Announcement $announcement, RbacService $rbac): View
    {
        abort_unless($rbac->hasPermission($request->user('admin'), 'announcement.manage'), 403);
        $announcement->load('targets');

        return view('admin.announcements.edit', array_merge([
            'routePrefix' => 'admin',
            'announcement' => $announcement,
            'customers' => Customer::query()->orderBy('code')->get(),
            'businessPartners' => BusinessPartner::query()->orderBy('code')->get(),
            'allowBroadcast' => true,
        ], $this->announcementFormState($announcement)));
    }

    public function update(Request $request, Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        $payload = $this->validatedAnnouncementPayload($request, allowBroadcast: true);

        try {
            $service->update(
                $request->user('admin'),
                $announcement,
                $payload['title'],
                $payload['body'],
                $payload['targets'],
                $payload['options'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['title' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.announcements.show', $announcement)
            ->with('status', 'お知らせを更新しました。');
    }

    public function show(Request $request, Announcement $announcement, AnnouncementService $service, RbacService $rbac): View
    {
        $actor = $request->user('admin');
        $canManage = $rbac->hasPermission($actor, 'announcement.manage');
        if (! $canManage && ! $service->isVisibleTo($actor, $announcement)) {
            abort(403);
        }

        if ($service->isVisibleTo($actor, $announcement)) {
            $service->markRead($actor, $announcement);
        }

        return view('admin.announcements.show', [
            'announcement' => $announcement->load('targets'),
            'routePrefix' => 'admin',
            'canManage' => $canManage,
            'deleteConfirmationCode' => $canManage
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
