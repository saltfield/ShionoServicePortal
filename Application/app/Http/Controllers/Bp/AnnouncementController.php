<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Services\BpHierarchyService;
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
        $actor = $request->user('bp');
        $canManage = $rbac->hasPermission($actor, 'announcement.manage');

        return view('admin.announcements.index', [
            'announcements' => $canManage
                ? $service->manageableList($actor)
                : $service->visibleQuery($actor)->limit(50)->get(),
            'routePrefix' => 'bp',
            'canManage' => $canManage,
            'unreadIds' => [],
        ]);
    }

    public function create(Request $request, RbacService $rbac, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'announcement.manage'), 403);
        $scopeIds = $hierarchy->descendantIdsIncludingSelf($actor->businessPartner);

        return view('admin.announcements.create', [
            'routePrefix' => 'bp',
            'customers' => Customer::query()->whereIn('managing_bp_id', $scopeIds)->orderBy('code')->get(),
            'businessPartners' => BusinessPartner::query()->whereIn('id', $scopeIds)->orderBy('code')->get(),
            'allowBroadcast' => true,
        ]);
    }

    public function store(Request $request, AnnouncementService $service): RedirectResponse
    {
        $payload = $this->validatedAnnouncementPayload($request, allowBroadcast: true);

        try {
            $announcement = $service->publish(
                $request->user('bp'),
                $payload['title'],
                $payload['body'],
                $payload['targets'],
                $payload['options'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['title' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.announcements.show', $announcement)
            ->with('status', 'お知らせを保存しました。');
    }

    public function edit(Request $request, Announcement $announcement, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'announcement.manage'), 403);
        abort_unless($announcement->owning_bp_id === $actor->bp_id, 403);
        $announcement->load('targets');

        $hierarchy = app(BpHierarchyService::class);
        $scopeIds = $hierarchy->descendantIdsIncludingSelf($actor->businessPartner);

        return view('admin.announcements.edit', array_merge([
            'routePrefix' => 'bp',
            'announcement' => $announcement,
            'customers' => Customer::query()->whereIn('managing_bp_id', $scopeIds)->orderBy('code')->get(),
            'businessPartners' => BusinessPartner::query()->whereIn('id', $scopeIds)->orderBy('code')->get(),
            'allowBroadcast' => true,
        ], $this->announcementFormState($announcement)));
    }

    public function update(Request $request, Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        $payload = $this->validatedAnnouncementPayload($request, allowBroadcast: true);

        try {
            $service->update(
                $request->user('bp'),
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
            ->route('bp.announcements.show', $announcement)
            ->with('status', 'お知らせを更新しました。');
    }

    public function show(Request $request, Announcement $announcement, AnnouncementService $service, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        $canManage = $rbac->hasPermission($actor, 'announcement.manage')
            && $announcement->owning_bp_id === $actor->bp_id;

        if (! $canManage && ! $service->isVisibleTo($actor, $announcement)) {
            abort(403);
        }

        if ($service->isVisibleTo($actor, $announcement)) {
            $service->markRead($actor, $announcement);
        }

        return view('admin.announcements.show', [
            'announcement' => $announcement->load('targets'),
            'routePrefix' => 'bp',
            'canManage' => $canManage,
            'deleteConfirmationCode' => $canManage
                ? $this->issueAnnouncementDeleteConfirmationCode($announcement)
                : null,
        ]);
    }

    public function destroy(Request $request, Announcement $announcement, AnnouncementService $service): RedirectResponse
    {
        $this->assertAnnouncementDeleteConfirmation($request, $announcement);
        $service->delete($request->user('bp'), $announcement);

        return redirect()
            ->route('bp.announcements.index')
            ->with('status', 'お知らせを削除しました。');
    }
}
