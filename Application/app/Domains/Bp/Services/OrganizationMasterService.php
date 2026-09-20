<?php

namespace App\Domains\Bp\Services;

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Iam\Services\AuditLogger;
use App\Domains\Iam\Services\AuthorizationService;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrganizationMasterService
{
    public function __construct(
        private readonly BpHierarchyService $hierarchy,
        private readonly NumberSequenceService $sequences,
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function actorBp(User $actor): ?BusinessPartner
    {
        return $actor->user_type === UserType::Bp ? $actor->businessPartner : null;
    }

    public function ensureBpInScope(User $actor, BusinessPartner $partner): void
    {
        $actorBp = $this->actorBp($actor);
        if ($actorBp === null) {
            return;
        }

        if (! $this->hierarchy->isSelfOrDescendant($actorBp, $partner)) {
            abort(403, '自BP配下以外は操作できません。');
        }
    }

    public function ensureCustomerInScope(User $actor, Customer $customer): void
    {
        $customer->loadMissing('managingBp');
        if ($customer->managingBp === null) {
            abort(403);
        }
        $this->ensureBpInScope($actor, $customer->managingBp);
    }

    public function createBp(User $actor, array $data, ?BusinessPartner $parent): BusinessPartner
    {
        $this->authorization->authorize($actor, 'bp.manage', [
            'resource_type' => 'bp',
            'owner_bp_id' => $parent?->id ?? $this->actorBp($actor)?->id,
        ]);

        if ($actor->user_type === UserType::Bp) {
            if ($parent === null) {
                throw new InvalidArgumentException('BPユーザーはルートBPを作成できません。');
            }
            $this->ensureBpInScope($actor, $parent);
        }

        $code = $this->sequences->next(PartnerCodePrefix::Bpn);
        $mode = TwoFactorMode::from($data['two_factor_mode'] ?? TwoFactorMode::Optional->value);
        $attrs = [
            'two_factor_mode' => $mode,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'postal_code' => $data['postal_code'] ?? null,
            'address' => $data['address'] ?? null,
            'building_name' => $data['building_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ];

        $partner = $parent === null
            ? $this->hierarchy->createRoot($code, $data['name'], $attrs)
            : $this->hierarchy->createChild($parent, $code, $data['name'], $attrs);

        $this->auditLogger->log(
            category: 'master',
            action: 'bp.create',
            result: 'success',
            actor: $actor,
            targetType: BusinessPartner::class,
            targetId: $partner->id,
            meta: ['code' => $partner->code, 'parent_id' => $partner->parent_id],
        );

        return $partner;
    }

    public function updateBp(User $actor, BusinessPartner $partner, array $data): BusinessPartner
    {
        $this->authorization->authorize($actor, 'bp.manage', [
            'resource_type' => 'bp',
            'owner_bp_id' => $partner->id,
        ]);
        $this->ensureBpInScope($actor, $partner);

        $updated = $this->hierarchy->update($partner, [
            'name' => $data['name'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'two_factor_mode' => TwoFactorMode::from($data['two_factor_mode']),
            'postal_code' => $data['postal_code'] ?? null,
            'address' => $data['address'] ?? null,
            'building_name' => $data['building_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ]);

        $this->auditLogger->log(
            category: 'master',
            action: 'bp.update',
            result: 'success',
            actor: $actor,
            targetType: BusinessPartner::class,
            targetId: $updated->id,
            meta: ['code' => $updated->code],
        );

        return $updated;
    }

    public function moveBp(User $actor, BusinessPartner $partner, ?BusinessPartner $newParent): BusinessPartner
    {
        $this->authorization->authorize($actor, 'bp.manage', [
            'resource_type' => 'bp',
            'owner_bp_id' => $partner->id,
        ]);
        $this->ensureBpInScope($actor, $partner);

        if ($actor->user_type === UserType::Bp) {
            if ($newParent === null) {
                throw new InvalidArgumentException('BPユーザーはルートへの移動ができません。');
            }
            $this->ensureBpInScope($actor, $newParent);
        }

        $moved = $this->hierarchy->move($partner, $newParent);

        $this->auditLogger->log(
            category: 'master',
            action: 'bp.move',
            result: 'success',
            actor: $actor,
            targetType: BusinessPartner::class,
            targetId: $moved->id,
            meta: ['new_parent_id' => $moved->parent_id],
        );

        return $moved;
    }

    public function deleteBp(User $actor, BusinessPartner $partner): void
    {
        $this->authorization->authorize($actor, 'bp.manage', [
            'resource_type' => 'bp',
            'owner_bp_id' => $partner->id,
        ]);
        $this->ensureBpInScope($actor, $partner);

        if ($actor->user_type === UserType::Bp && $partner->id === $this->actorBp($actor)?->id) {
            throw new InvalidArgumentException('自BPは削除できません。');
        }

        $code = $partner->code;
        $id = $partner->id;
        $this->hierarchy->delete($partner);

        $this->auditLogger->log(
            category: 'master',
            action: 'bp.delete',
            result: 'success',
            actor: $actor,
            targetType: BusinessPartner::class,
            targetId: $id,
            meta: ['code' => $code],
        );
    }

    public function createCustomer(User $actor, BusinessPartner $managingBp, array $data): Customer
    {
        $this->authorization->authorize($actor, 'customer.manage', [
            'resource_type' => 'customer',
            'owner_bp_id' => $managingBp->id,
        ]);
        $this->ensureBpInScope($actor, $managingBp);

        $customer = Customer::query()->create([
            'code' => $this->sequences->next(PartnerCodePrefix::Cn),
            'managing_bp_id' => $managingBp->id,
            'name' => $data['name'],
            'name_kana' => $data['name_kana'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'address' => $data['address'] ?? null,
            'building_name' => $data['building_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'entity_type' => $data['entity_type'],
            'two_factor_mode' => TwoFactorMode::from($data['two_factor_mode'] ?? TwoFactorMode::Optional->value),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $this->auditLogger->log(
            category: 'master',
            action: 'customer.create',
            result: 'success',
            actor: $actor,
            targetType: Customer::class,
            targetId: $customer->id,
            meta: ['code' => $customer->code, 'managing_bp_id' => $managingBp->id],
        );

        return $customer;
    }

    public function updateCustomer(User $actor, Customer $customer, array $data): Customer
    {
        $this->authorization->authorize($actor, 'customer.manage', [
            'resource_type' => 'customer',
            'owner_bp_id' => $customer->managing_bp_id,
        ]);
        $this->ensureCustomerInScope($actor, $customer);

        $customer->fill([
            'name' => $data['name'],
            'name_kana' => $data['name_kana'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'address' => $data['address'] ?? null,
            'building_name' => $data['building_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'entity_type' => $data['entity_type'],
            'two_factor_mode' => TwoFactorMode::from($data['two_factor_mode']),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ])->save();

        $this->auditLogger->log(
            category: 'master',
            action: 'customer.update',
            result: 'success',
            actor: $actor,
            targetType: Customer::class,
            targetId: $customer->id,
            meta: ['code' => $customer->code],
        );

        return $customer->fresh();
    }

    public function deleteCustomer(User $actor, Customer $customer): void
    {
        $this->authorization->authorize($actor, 'customer.manage', [
            'resource_type' => 'customer',
            'owner_bp_id' => $customer->managing_bp_id,
        ]);
        $this->ensureCustomerInScope($actor, $customer);

        if ($customer->users()->exists()) {
            throw new InvalidArgumentException('所属ユーザーが存在するため削除できません。');
        }

        if ($customer->sites()->exists()) {
            throw new InvalidArgumentException('拠点が存在するため削除できません。');
        }

        $code = $customer->code;
        $id = $customer->id;
        $customer->delete();

        $this->auditLogger->log(
            category: 'master',
            action: 'customer.delete',
            result: 'success',
            actor: $actor,
            targetType: Customer::class,
            targetId: $id,
            meta: ['code' => $code],
        );
    }

    public function createSite(User $actor, Customer $customer, array $data): Site
    {
        $this->authorization->authorize($actor, 'site.manage', [
            'resource_type' => 'site',
            'owner_bp_id' => $customer->managing_bp_id,
        ]);
        $this->ensureCustomerInScope($actor, $customer);

        return DB::transaction(function () use ($actor, $customer, $data) {
            $isPrimary = (bool) ($data['is_primary'] ?? false);
            if ($isPrimary) {
                Site::query()->where('customer_id', $customer->id)->update(['is_primary' => false]);
            } elseif (! Site::query()->where('customer_id', $customer->id)->exists()) {
                $isPrimary = true;
            }

            $site = Site::query()->create([
                'customer_id' => $customer->id,
                'name' => $data['name'],
                'postal_code' => $data['postal_code'] ?? null,
                'address' => $data['address'] ?? null,
                'building_name' => $data['building_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'billing_name' => $data['billing_name'],
                'billing_department' => $data['billing_department'] ?? null,
                'billing_postal_code' => $data['billing_postal_code'] ?? null,
                'billing_address' => $data['billing_address'] ?? null,
                'billing_building_name' => $data['billing_building_name'] ?? null,
                'billing_phone' => $data['billing_phone'] ?? null,
                'is_primary' => $isPrimary,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->auditLogger->log(
                category: 'master',
                action: 'site.create',
                result: 'success',
                actor: $actor,
                targetType: Site::class,
                targetId: $site->id,
                meta: ['customer_id' => $customer->id, 'name' => $site->name],
            );

            return $site;
        });
    }

    public function updateSite(User $actor, Site $site, array $data): Site
    {
        $site->loadMissing('customer');
        $this->authorization->authorize($actor, 'site.manage', [
            'resource_type' => 'site',
            'owner_bp_id' => $site->customer->managing_bp_id,
        ]);
        $this->ensureCustomerInScope($actor, $site->customer);

        return DB::transaction(function () use ($actor, $site, $data) {
            $isPrimary = (bool) ($data['is_primary'] ?? false);
            if ($isPrimary) {
                Site::query()
                    ->where('customer_id', $site->customer_id)
                    ->where('id', '!=', $site->id)
                    ->update(['is_primary' => false]);
            }

            $site->fill([
                'name' => $data['name'],
                'postal_code' => $data['postal_code'] ?? null,
                'address' => $data['address'] ?? null,
                'building_name' => $data['building_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'billing_name' => $data['billing_name'],
                'billing_department' => $data['billing_department'] ?? null,
                'billing_postal_code' => $data['billing_postal_code'] ?? null,
                'billing_address' => $data['billing_address'] ?? null,
                'billing_building_name' => $data['billing_building_name'] ?? null,
                'billing_phone' => $data['billing_phone'] ?? null,
                'is_primary' => $isPrimary,
                'is_active' => (bool) ($data['is_active'] ?? false),
            ])->save();

            $this->auditLogger->log(
                category: 'master',
                action: 'site.update',
                result: 'success',
                actor: $actor,
                targetType: Site::class,
                targetId: $site->id,
                meta: ['name' => $site->name],
            );

            return $site->fresh();
        });
    }

    public function deleteSite(User $actor, Site $site): void
    {
        $site->loadMissing('customer');
        $this->authorization->authorize($actor, 'site.manage', [
            'resource_type' => 'site',
            'owner_bp_id' => $site->customer->managing_bp_id,
        ]);
        $this->ensureCustomerInScope($actor, $site->customer);

        $id = $site->id;
        $name = $site->name;
        $customerId = $site->customer_id;
        $wasPrimary = $site->is_primary;

        $site->delete();

        if ($wasPrimary) {
            $next = Site::query()->where('customer_id', $customerId)->orderBy('id')->first();
            $next?->forceFill(['is_primary' => true])->save();
        }

        $this->auditLogger->log(
            category: 'master',
            action: 'site.delete',
            result: 'success',
            actor: $actor,
            targetType: Site::class,
            targetId: $id,
            meta: ['name' => $name],
        );
    }
}
