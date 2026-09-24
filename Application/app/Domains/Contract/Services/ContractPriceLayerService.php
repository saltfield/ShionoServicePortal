<?php

namespace App\Domains\Contract\Services;

use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractItemPriceLayer;
use Illuminate\Support\Facades\DB;

class ContractPriceLayerService
{
    public function __construct(
        private readonly BpHierarchyService $hierarchy,
        private readonly CatalogPricingService $catalog,
    ) {}

    public function syncForContract(Contract $contract): void
    {
        $contract->loadMissing(['owningBp.parent', 'items.item']);
        $owning = $contract->owningBp;
        if ($owning === null) {
            return;
        }

        $edges = $this->hierarchy->wholesaleEdgesFromLeaf($owning);

        DB::transaction(function () use ($contract, $edges) {
            foreach ($contract->items as $line) {
                $this->syncForContractItem($line, $edges);
            }
        });
    }

    /**
     * @param  list<array{seller: \App\Models\BusinessPartner, buyer: \App\Models\BusinessPartner, depth_from_root: int}>  $edges
     */
    public function syncForContractItem(ContractItem $line, ?array $edges = null): void
    {
        $line->loadMissing(['item', 'contract.owningBp']);
        $owning = $line->contract?->owningBp;
        if ($owning === null || $line->item === null) {
            return;
        }

        $edges ??= $this->hierarchy->wholesaleEdgesFromLeaf($owning);

        ContractItemPriceLayer::query()->where('contract_item_id', $line->id)->delete();

        foreach ($edges as $edge) {
            $amount = (int) $this->catalog->resolveWholesaleAmount($line->item, $edge['seller'], $edge['buyer']);
            ContractItemPriceLayer::query()->create([
                'contract_item_id' => $line->id,
                'seller_bp_id' => $edge['seller']->id,
                'buyer_bp_id' => $edge['buyer']->id,
                'amount' => $amount,
                'depth_from_root' => $edge['depth_from_root'],
            ]);
        }
    }
}
