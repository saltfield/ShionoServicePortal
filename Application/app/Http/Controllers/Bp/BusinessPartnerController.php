<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Bp\Services\OrganizationMasterService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Concerns\ConfirmsBusinessPartnerDeletion;
use App\Http\Controllers\Concerns\ConfirmsBusinessPartnerMove;
use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\User;
use App\Support\ContactFieldRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class BusinessPartnerController extends Controller
{
    use ConfirmsBusinessPartnerDeletion;
    use ConfirmsBusinessPartnerMove;

    public function index(Request $request, AuthorizationService $authorization, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'bp.view');
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);

        $ids = $hierarchy->descendantIdsIncludingSelf($actorBp);

        $partners = BusinessPartner::query()
            ->with('parent')
            ->whereIn('id', $ids)
            ->orderBy('depth')
            ->orderBy('code')
            ->paginate(20);

        return view('admin.business-partners.index', [
            'partners' => $partners,
            'filters' => ['bpn' => '', 'bp_name' => ''],
            'routePrefix' => 'bp',
            'selfBpId' => $actorBp->id,
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'bp.manage');
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);

        $parents = $hierarchy->descendants($actorBp, includeSelf: true);

        return view('admin.business-partners.create', [
            'parents' => $parents,
            'modes' => TwoFactorMode::cases(),
            'selectedParentId' => $request->integer('parent_id') ?: $actorBp->id,
            'routePrefix' => 'bp',
            'requireParent' => true,
        ]);
    }

    public function store(Request $request, OrganizationMasterService $service): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['required', 'integer', 'exists:business_partners,id'],
            'two_factor_mode' => ['required', Rule::enum(TwoFactorMode::class)],
            'is_active' => ['nullable', 'boolean'],
            'postal_code' => ContactFieldRules::postalCode(),
            'address' => ['nullable', 'string', 'max:255'],
            'building_name' => ['nullable', 'string', 'max:255'],
            'phone' => ContactFieldRules::phone(),
            'email' => ['nullable', 'email', 'max:255'],
        ], [
            'postal_code.regex' => '郵便番号は半角数字（例: 100-0001）で入力してください。',
            'phone.regex' => '電話番号は半角数字とハイフンのみで入力してください。',
        ]);

        $validated['postal_code'] = ContactFieldRules::normalizePostalCode($validated['postal_code'] ?? null);
        $validated['phone'] = ContactFieldRules::normalizePhone($validated['phone'] ?? null);

        $parent = BusinessPartner::query()->findOrFail($validated['parent_id']);

        try {
            $partner = $service->createBp($request->user('bp'), [
                'name' => $validated['name'],
                'two_factor_mode' => $validated['two_factor_mode'],
                'is_active' => $request->boolean('is_active', true),
                'postal_code' => $validated['postal_code'] ?? null,
                'address' => $validated['address'] ?? null,
                'building_name' => $validated['building_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
            ], $parent);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['parent_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.business-partners.show', $partner)
            ->with('status', "{$partner->code} を作成しました。");
    }

    public function show(Request $request, BusinessPartner $businessPartner, OrganizationMasterService $service, AuthorizationService $authorization, BpHierarchyService $hierarchy, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'bp.view');
        $service->ensureBpInScope($actor, $businessPartner);
        $businessPartner->load(['parent', 'children']);

        $actorBp = $actor->businessPartner;
        $scopeIds = $hierarchy->descendantIdsIncludingSelf($actorBp);
        $canManageUsers = $rbac->hasPermission($actor, 'iam.user.manage');
        $canViewCustomers = $rbac->hasPermission($actor, 'customer.view');
        $canManageCustomers = $rbac->hasPermission($actor, 'customer.manage');
        $activeTab = match ($request->input('tab')) {
            'users' => $canManageUsers ? 'users' : 'overview',
            'customers' => $canViewCustomers ? 'customers' : 'overview',
            default => 'overview',
        };

        return view('admin.business-partners.show', [
            'partner' => $businessPartner,
            'moveCandidates' => BusinessPartner::query()
                ->whereIn('id', $scopeIds)
                ->where('id', '!=', $businessPartner->id)
                ->orderBy('depth')
                ->orderBy('code')
                ->get(),
            'modes' => TwoFactorMode::cases(),
            'routePrefix' => 'bp',
            'allowRootMove' => false,
            'deleteConfirmationCode' => $this->issueBusinessPartnerDeleteConfirmationCode($businessPartner),
            'moveConfirmationCode' => $this->issueBusinessPartnerMoveConfirmationCode($businessPartner),
            'canManageUsers' => $canManageUsers,
            'canViewCustomers' => $canViewCustomers,
            'canManageCustomers' => $canManageCustomers,
            'bpUsers' => $canManageUsers
                ? User::query()->with('roles')->where('bp_id', $businessPartner->id)->orderBy('login_id')->get()
                : collect(),
            'bpCustomers' => $canViewCustomers
                ? Customer::query()->where('managing_bp_id', $businessPartner->id)->orderBy('code')->get()
                : collect(),
            'activeTab' => $activeTab,
        ]);
    }

    public function edit(Request $request, BusinessPartner $businessPartner, OrganizationMasterService $service, AuthorizationService $authorization): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'bp.manage');
        $service->ensureBpInScope($actor, $businessPartner);

        return view('admin.business-partners.edit', [
            'partner' => $businessPartner,
            'modes' => TwoFactorMode::cases(),
            'routePrefix' => 'bp',
        ]);
    }

    public function update(Request $request, BusinessPartner $businessPartner, OrganizationMasterService $service): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'two_factor_mode' => ['required', Rule::enum(TwoFactorMode::class)],
            'is_active' => ['nullable', 'boolean'],
            'postal_code' => ContactFieldRules::postalCode(),
            'address' => ['nullable', 'string', 'max:255'],
            'building_name' => ['nullable', 'string', 'max:255'],
            'phone' => ContactFieldRules::phone(),
            'email' => ['nullable', 'email', 'max:255'],
        ], [
            'postal_code.regex' => '郵便番号は半角数字（例: 100-0001）で入力してください。',
            'phone.regex' => '電話番号は半角数字とハイフンのみで入力してください。',
        ]);

        $validated['postal_code'] = ContactFieldRules::normalizePostalCode($validated['postal_code'] ?? null);
        $validated['phone'] = ContactFieldRules::normalizePhone($validated['phone'] ?? null);

        $service->updateBp($request->user('bp'), $businessPartner, [
            'name' => $validated['name'],
            'two_factor_mode' => $validated['two_factor_mode'],
            'is_active' => $request->boolean('is_active'),
            'postal_code' => $validated['postal_code'] ?? null,
            'address' => $validated['address'] ?? null,
            'building_name' => $validated['building_name'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
        ]);

        return redirect()
            ->route('bp.business-partners.show', $businessPartner)
            ->with('status', 'BP情報を更新しました。');
    }

    public function move(Request $request, BusinessPartner $businessPartner, OrganizationMasterService $service): RedirectResponse
    {
        $this->assertBusinessPartnerMoveConfirmation($request, $businessPartner);

        $validated = $request->validate([
            'parent_id' => ['required', 'integer', 'exists:business_partners,id'],
        ]);

        $newParent = BusinessPartner::query()->findOrFail($validated['parent_id']);

        try {
            $service->moveBp($request->user('bp'), $businessPartner, $newParent);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['parent_id' => $exception->getMessage()]);
        }

        return back()->with('status', '階層を移動しました。');
    }

    public function destroy(Request $request, BusinessPartner $businessPartner, OrganizationMasterService $service): RedirectResponse
    {
        $this->assertBusinessPartnerDeleteConfirmation($request, $businessPartner);

        try {
            $service->deleteBp($request->user('bp'), $businessPartner);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['partner' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.business-partners.index')
            ->with('status', 'BPを削除しました。');
    }
}
