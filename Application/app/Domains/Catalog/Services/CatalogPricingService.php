<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Iam\Services\AuditLogger;
use App\Domains\Iam\Services\AuthorizationService;
use App\Models\BpWholesalePrice;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Item;
use App\Models\User;
use InvalidArgumentException;

class CatalogPricingService
{
    public function __construct(
        private readonly NumberSequenceService $sequences,
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly BpHierarchyService $hierarchy,
    ) {}

    public function createItem(User $actor, array $data): Item
    {
        $owningBpId = $this->assertCanManageItemCreate($actor, $data['owning_bp_id'] ?? null);

        $billingType = BillingType::from($data['billing_type']);
        $requiredItemId = $data['required_item_id'] ?? null;
        $this->assertRequiredItem($requiredItemId, null, $owningBpId);

        $item = Item::query()->create([
            'code' => $this->sequences->next(PartnerCodePrefix::Item),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'billing_type' => $billingType,
            'required_item_id' => $requiredItemId,
            'partition_price' => (int) ($data['partition_price'] ?? 0),
            'recommended_price' => (int) ($data['recommended_price'] ?? 0),
            'user_price' => (int) ($data['user_price'] ?? 0),
            'tax_rate' => (int) ($data['tax_rate'] ?? 10),
            'minimum_term_months' => isset($data['minimum_term_months']) && $data['minimum_term_months'] !== ''
                ? (int) $data['minimum_term_months']
                : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'owning_bp_id' => $owningBpId,
        ]);

        $this->auditLogger->log(
            category: 'catalog',
            action: 'item.create',
            result: 'success',
            actor: $actor,
            targetType: Item::class,
            targetId: $item->id,
            meta: [
                'code' => $item->code,
                'billing_type' => $item->billing_type->value,
                'owning_bp_id' => $owningBpId,
            ],
        );

        return $item;
    }

    public function updateItem(User $actor, Item $item, array $data): Item
    {
        $this->assertCanManageItem($actor, $item);

        $billingType = BillingType::from($data['billing_type'] ?? $item->billing_type->value);
        $requiredItemId = array_key_exists('required_item_id', $data)
            ? $data['required_item_id']
            : $item->required_item_id;
        $this->assertRequiredItem($requiredItemId, $item->id, $item->owning_bp_id);

        $item->fill([
            'name' => $data['name'] ?? $item->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $item->description,
            'billing_type' => $billingType,
            'required_item_id' => $requiredItemId,
            'partition_price' => array_key_exists('partition_price', $data) ? (int) $data['partition_price'] : $item->partition_price,
            'recommended_price' => array_key_exists('recommended_price', $data) ? (int) $data['recommended_price'] : $item->recommended_price,
            'user_price' => array_key_exists('user_price', $data) ? (int) $data['user_price'] : $item->user_price,
            'tax_rate' => array_key_exists('tax_rate', $data) ? (int) $data['tax_rate'] : $item->tax_rate,
            'minimum_term_months' => array_key_exists('minimum_term_months', $data)
                ? ($data['minimum_term_months'] === null || $data['minimum_term_months'] === ''
                    ? null
                    : (int) $data['minimum_term_months'])
                : $item->minimum_term_months,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $item->is_active,
        ]);
        $item->save();

        $this->auditLogger->log(
            category: 'catalog',
            action: 'item.update',
            result: 'success',
            actor: $actor,
            targetType: Item::class,
            targetId: $item->id,
            meta: ['code' => $item->code],
        );

        return $item->fresh();
    }

    public function deleteItem(User $actor, Item $item): void
    {
        $this->assertCanManageItem($actor, $item);

        if ($item->requiredByItems()->exists()) {
            throw new InvalidArgumentException('他品目の必須セット先のため削除できません。');
        }

        if ($item->wholesalePrices()->exists() || $item->customerPrices()->exists()) {
            throw new InvalidArgumentException('価格設定が存在するため削除できません。');
        }

        $code = $item->code;
        $id = $item->id;
        $item->delete();

        $this->auditLogger->log(
            category: 'catalog',
            action: 'item.delete',
            result: 'success',
            actor: $actor,
            targetType: Item::class,
            targetId: $id,
            meta: ['code' => $code],
        );
    }

    public function upsertWholesalePrice(
        User $actor,
        Item $item,
        BusinessPartner $seller,
        BusinessPartner $buyer,
        string|float $amount,
    ): BpWholesalePrice {
        if ((int) $buyer->parent_id !== (int) $seller->id) {
            throw new InvalidArgumentException('卸価格は直接の子BPに対してのみ設定できます。');
        }

        $this->authorization->authorize($actor, 'price.wholesale.edit', [
            'resource_type' => 'price',
            'owner_bp_id' => $buyer->id,
        ]);

        if ($actor->user_type === UserType::Bp) {
            $actorBp = $actor->businessPartner;
            abort_unless($actorBp && $actorBp->id === $seller->id, 403, '自BP以外の卸価格は設定できません。');
        }

        $price = BpWholesalePrice::query()->updateOrCreate(
            [
                'item_id' => $item->id,
                'seller_bp_id' => $seller->id,
                'buyer_bp_id' => $buyer->id,
            ],
            ['amount' => (int) round((float) $amount)],
        );

        $this->auditLogger->log(
            category: 'catalog',
            action: 'price.wholesale.upsert',
            result: 'success',
            actor: $actor,
            targetType: BpWholesalePrice::class,
            targetId: $price->id,
            meta: [
                'item_id' => $item->id,
                'seller_bp_id' => $seller->id,
                'buyer_bp_id' => $buyer->id,
                'amount' => (string) $price->amount,
            ],
        );

        return $price;
    }

    public function upsertCustomerPrice(
        User $actor,
        Item $item,
        Customer $customer,
        string|float $amount,
    ): CustomerPrice {
        $customer->loadMissing('managingBp');
        $managingBp = $customer->managingBp;
        abort_unless($managingBp, 403);

        $this->authorization->authorize($actor, 'price.customer.edit', [
            'resource_type' => 'price',
            'owner_bp_id' => $managingBp->id,
        ]);

        if ($actor->user_type === UserType::Bp) {
            $actorBp = $actor->businessPartner;
            abort_unless($actorBp, 403);
            if (! $this->hierarchy->isSelfOrDescendant($actorBp, $managingBp)) {
                abort(403, '自BP配下以外のカスタマー価格は設定できません。');
            }
        }

        $bpId = $actor->user_type === UserType::Bp
            ? $actor->businessPartner->id
            : $managingBp->id;

        $price = CustomerPrice::query()->updateOrCreate(
            [
                'item_id' => $item->id,
                'bp_id' => $bpId,
                'customer_id' => $customer->id,
            ],
            ['amount' => (int) round((float) $amount)],
        );

        $this->auditLogger->log(
            category: 'catalog',
            action: 'price.customer.upsert',
            result: 'success',
            actor: $actor,
            targetType: CustomerPrice::class,
            targetId: $price->id,
            meta: [
                'item_id' => $item->id,
                'bp_id' => $bpId,
                'customer_id' => $customer->id,
                'amount' => (string) $price->amount,
            ],
        );

        return $price;
    }

    public function resolveWholesaleAmount(Item $item, BusinessPartner $seller, BusinessPartner $buyer): string
    {
        $row = BpWholesalePrice::query()
            ->where('item_id', $item->id)
            ->where('seller_bp_id', $seller->id)
            ->where('buyer_bp_id', $buyer->id)
            ->first();

        return (string) (int) round((float) ($row?->amount ?? $item->partition_price));
    }

    public function resolveCustomerAmount(Item $item, Customer $customer, ?BusinessPartner $bp = null): string
    {
        $query = CustomerPrice::query()
            ->where('item_id', $item->id)
            ->where('customer_id', $customer->id);

        if ($bp !== null) {
            $query->where('bp_id', $bp->id);
        }

        $row = $query->first();

        return (string) (int) round((float) ($row?->amount ?? $item->user_price));
    }

    private function assertCanManageItemCreate(User $actor, mixed $requestedOwningBpId): ?int
    {
        if ($actor->user_type === UserType::Admin) {
            $this->authorization->authorize($actor, 'item.manage');

            return null;
        }

        if ($actor->user_type !== UserType::Bp) {
            throw new InvalidArgumentException('品目を作成する権限がありません。');
        }

        $this->authorization->authorize($actor, 'contract.create');
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);
        $owningBpId = $requestedOwningBpId ? (int) $requestedOwningBpId : $actorBp->id;
        abort_unless($owningBpId === (int) $actorBp->id, 403, '自BP以外の独自サービスは作成できません。');

        return $owningBpId;
    }

    private function assertCanManageItem(User $actor, Item $item): void
    {
        if ($actor->user_type === UserType::Admin) {
            $this->authorization->authorize($actor, 'item.manage');

            return;
        }

        if ($actor->user_type !== UserType::Bp) {
            throw new InvalidArgumentException('品目を更新する権限がありません。');
        }

        $this->authorization->authorize($actor, 'contract.create');
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);
        if (! $item->isBpOwned() || (int) $item->owning_bp_id !== (int) $actorBp->id) {
            throw new InvalidArgumentException('標準品目は編集できません。自BPの独自サービスのみ編集できます。');
        }
    }

    private function assertRequiredItem(mixed $requiredItemId, ?int $selfId, ?int $owningBpId = null): void
    {
        if ($requiredItemId === null || $requiredItemId === '') {
            return;
        }

        $requiredId = (int) $requiredItemId;
        if ($selfId !== null && $requiredId === $selfId) {
            throw new InvalidArgumentException('必須セット品目に自分自身は指定できません。');
        }

        $required = Item::query()->find($requiredId);
        if ($required === null) {
            throw new InvalidArgumentException('必須セット品目が見つかりません。');
        }

        if ($owningBpId !== null) {
            $allowed = $required->owning_bp_id === null || (int) $required->owning_bp_id === (int) $owningBpId;
            if (! $allowed) {
                throw new InvalidArgumentException('必須セット品目には標準品目または自BPの独自サービスのみ指定できます。');
            }
        }

        if ($selfId !== null && $this->wouldCreateRequirementCycle($selfId, $requiredId)) {
            throw new InvalidArgumentException('必須セット品目の指定が循環しています。');
        }
    }

    private function wouldCreateRequirementCycle(int $itemId, int $requiredId): bool
    {
        $cursor = $requiredId;
        $guard = 0;

        while ($cursor !== null && $guard < 50) {
            if ($cursor === $itemId) {
                return true;
            }
            $cursor = Item::query()->whereKey($cursor)->value('required_item_id');
            $cursor = $cursor !== null ? (int) $cursor : null;
            $guard++;
        }

        return false;
    }
}
