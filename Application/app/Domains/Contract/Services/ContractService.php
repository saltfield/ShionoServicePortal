<?php

namespace App\Domains\Contract\Services;

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Catalog\Support\OrderCatalogScope;
use App\Domains\Contract\Enums\ApplicationStatus;
use App\Domains\Contract\Enums\ApplicationType;
use App\Domains\Contract\Enums\ContractStatus;
use App\Domains\Iam\Services\AuditLogger;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\BpRelationResolver;
use App\Domains\Notification\Services\NotificationService;
use App\Models\Application;
use App\Models\BusinessPartner;
use App\Models\Contract;
use App\Models\ContractData;
use App\Models\ContractItem;
use App\Models\ContractItemData;
use App\Models\ContractItemDocument;
use App\Models\ContractMessage;
use App\Models\ContractMessageRead;
use App\Models\ContractStatusHistory;
use App\Models\Customer;
use App\Models\DataFieldName;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemDocument;
use App\Models\Site;
use App\Models\User;
use App\Domains\Contract\Support\ReservedReplaceCodes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class ContractService
{
    public const MAX_ITEM_DOCUMENTS = 5;

    public const MAX_DATA_ROWS = 10;

    /** @var list<string> */
    public const ITEM_DOCUMENT_EXTENSIONS = ['xls', 'xlsx', 'xml', 'html', 'htm'];

    public function __construct(
        private readonly NumberSequenceService $sequences,
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly BpHierarchyService $hierarchy,
        private readonly CatalogPricingService $pricing,
        private readonly BpRelationResolver $relationResolver,
        private readonly DocumentRenderService $documentRender,
        private readonly ContractPriceLayerService $priceLayers,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  list<int>  $itemIds
     * @param  array{
     *   special_price_requested?: bool,
     *   special_price_reason?: ?string,
     *   partitions?: array<int|string, int|string>,
     *   unit_prices?: array<int|string, int|string>
     * }  $options
     */
    public function createDraft(User $actor, Site $site, array $itemIds, array $options = []): Contract
    {
        $site->loadMissing('customer.managingBp');
        $customer = $site->customer;
        abort_unless($customer?->managingBp, 403);

        $this->ensureCustomerScope($actor, $customer);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $customer->managing_bp_id,
        ]);

        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            throw new InvalidArgumentException('品目を1件以上選択してください。');
        }

        $specialRequested = (bool) ($options['special_price_requested'] ?? false);
        $specialReason = trim((string) ($options['special_price_reason'] ?? ''));
        $requestedPartitions = $options['partitions'] ?? [];
        $requestedUnitPrices = $options['unit_prices'] ?? [];

        if ($specialRequested && $specialReason === '') {
            throw new InvalidArgumentException('特価申請理由を入力してください。');
        }

        $items = Item::query()->whereIn('id', $itemIds)->where('is_active', true)->get();
        if ($items->count() !== count($itemIds)) {
            throw new InvalidArgumentException('無効な品目が含まれています。');
        }

        $owningBp = $customer->managingBp;
        $allowedOwnerBpIds = OrderCatalogScope::ownerBpIds($actor, $owningBp, $this->hierarchy);
        foreach ($items as $item) {
            if (! OrderCatalogScope::itemAllowed($item, $allowedOwnerBpIds)) {
                throw new InvalidArgumentException('他BPの独自サービスは契約に含められません。');
            }
        }

        $this->assertRequiredItemsSatisfied($items);

        $parentBp = $owningBp->parent;

        if ($specialRequested) {
            foreach ($items as $item) {
                if (! array_key_exists((string) $item->id, $requestedPartitions) && ! array_key_exists($item->id, $requestedPartitions)) {
                    throw new InvalidArgumentException("品目 {$item->code} の希望仕切りを入力してください。");
                }
                if (! array_key_exists((string) $item->id, $requestedUnitPrices) && ! array_key_exists($item->id, $requestedUnitPrices)) {
                    throw new InvalidArgumentException("品目 {$item->code} のエンドユーザー価格を入力してください。");
                }
            }
        }

        $contract = DB::transaction(function () use ($actor, $site, $customer, $owningBp, $parentBp, $items, $specialRequested, $specialReason, $requestedPartitions, $requestedUnitPrices) {
            $contract = Contract::query()->create([
                'code' => $this->sequences->next(PartnerCodePrefix::Contract),
                'site_id' => $site->id,
                'customer_id' => $customer->id,
                'owning_bp_id' => $owningBp->id,
                'status' => ContractStatus::Draft,
                'special_price_requested' => $specialRequested,
                'special_price_reason' => $specialRequested ? $specialReason : null,
            ]);

            foreach ($items as $item) {
                $standardPartition = (int) ($parentBp
                    ? $this->pricing->resolveWholesaleAmount($item, $parentBp, $owningBp)
                    : $item->partition_price);
                $defaultUnit = (int) $this->pricing->resolveCustomerAmount($item, $customer, $owningBp);

                $partition = $standardPartition;
                if ($specialRequested) {
                    $raw = $requestedPartitions[$item->id] ?? $requestedPartitions[(string) $item->id] ?? null;
                    $partition = (int) round((float) $raw);
                    if ($partition < 0) {
                        throw new InvalidArgumentException('希望仕切りは0以上で入力してください。');
                    }
                }

                $unit = $defaultUnit;
                $hasUnitOverride = array_key_exists($item->id, $requestedUnitPrices)
                    || array_key_exists((string) $item->id, $requestedUnitPrices);
                if ($hasUnitOverride) {
                    $rawUnit = $requestedUnitPrices[$item->id] ?? $requestedUnitPrices[(string) $item->id];
                    $unit = (int) round((float) $rawUnit);
                    if ($unit < 0) {
                        throw new InvalidArgumentException('エンドユーザー価格は0以上で入力してください。');
                    }
                }

                ContractItem::query()->create([
                    'contract_id' => $contract->id,
                    'item_id' => $item->id,
                    'unit_price' => $unit,
                    'partition_price' => $partition,
                    'standard_partition_price' => $standardPartition,
                    'tax_rate' => (int) ($item->tax_rate ?? 10),
                    'price_locked' => false,
                ]);
            }

            $this->recordStatus($contract, null, ContractStatus::Draft, $actor, $specialRequested ? 'オーダー作成（特価申請）' : 'オーダー作成');
            $this->audit($actor, 'contract.create', $contract, [
                'special_price_requested' => $specialRequested,
            ]);

            return $contract->load('items.item');
        });

        if ($specialRequested) {
            $this->submitPriceApproval($actor, $contract->fresh(['items.item', 'owningBp.parent']));
        }

        return $contract->fresh(['items.item', 'owningBp']);
    }

    public function deleteDraft(User $actor, Contract $contract): void
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if ($contract->status !== ContractStatus::Draft) {
            throw new InvalidArgumentException('オーダー作成中の契約のみ削除できます。');
        }

        $code = $contract->code;
        $id = $contract->id;

        DB::transaction(function () use ($contract) {
            $contract->items()->each(function (ContractItem $line): void {
                $line->dataRows()->delete();
                $line->documents()->delete();
                $line->delete();
            });
            $contract->applications()->delete();
            $contract->statusHistories()->delete();
            $contract->delete();
        });

        $this->auditLogger->log(
            category: 'contract',
            action: 'contract.delete_draft',
            result: 'success',
            actor: $actor,
            targetType: Contract::class,
            targetId: $id,
            meta: ['code' => $code],
        );
    }

    /**
     * @param  array<int|string, array{unit_price?: int|string, partition_price?: int|string}>  $pricesByContractItemId
     * @param  array{special_price_requested?: bool, special_price_reason?: ?string}  $options
     */
    public function updateDraftPrices(User $actor, Contract $contract, array $pricesByContractItemId, array $options = []): Contract
    {
        $this->assertEditableDraft($actor, $contract);
        $contract->loadMissing('items');

        $specialRequested = (bool) ($options['special_price_requested'] ?? false);
        $specialReason = trim((string) ($options['special_price_reason'] ?? ''));
        $partitionTouched = false;

        foreach ($pricesByContractItemId as $contractItemId => $row) {
            /** @var ContractItem|null $line */
            $line = $contract->items->firstWhere('id', (int) $contractItemId);
            if ($line === null || ! array_key_exists('partition_price', $row)) {
                continue;
            }
            $nextPartition = (int) round((float) $row['partition_price']);
            if ($nextPartition !== (int) $line->partition_price) {
                $partitionTouched = true;
                break;
            }
        }

        if ($partitionTouched && ! $specialRequested) {
            throw new InvalidArgumentException('仕切りを変更する場合は特価申請が必要です。');
        }
        if ($specialRequested && $specialReason === '') {
            throw new InvalidArgumentException('特価申請理由を入力してください。');
        }

        foreach ($pricesByContractItemId as $contractItemId => $row) {
            /** @var ContractItem|null $line */
            $line = $contract->items()->whereKey($contractItemId)->first();
            if ($line === null) {
                continue;
            }
            if ($line->price_locked) {
                throw new InvalidArgumentException('ロック済み明細の価格は直接変更できません。変更申請を利用してください。');
            }
            if (isset($row['unit_price'])) {
                $line->unit_price = (int) round((float) $row['unit_price']);
            }
            if ($specialRequested && array_key_exists('partition_price', $row)) {
                $line->partition_price = (int) round((float) $row['partition_price']);
            }
            $line->save();
        }

        if ($specialRequested) {
            $contract->special_price_requested = true;
            $contract->special_price_reason = $specialReason;
            $contract->save();
        }

        $this->audit($actor, 'contract.prices.update', $contract, [
            'special_price' => $specialRequested,
        ]);

        return $contract->fresh(['items.item']);
    }

    public function submitPriceApproval(User $actor, Contract $contract, ?string $specialPriceReason = null): Application
    {
        $this->assertEditableDraft($actor, $contract);

        if ($actor->user_type === UserType::Bp && (int) $actor->bp_id !== (int) $contract->owning_bp_id) {
            throw new InvalidArgumentException('価格承認申請は契約の管理BPのみ行えます。');
        }

        $contract->loadMissing(['owningBp.parent', 'items']);

        if ($specialPriceReason !== null) {
            $reason = trim($specialPriceReason);
            if ($reason === '') {
                throw new InvalidArgumentException('特価申請理由を入力してください。');
            }
            $contract->special_price_requested = true;
            $contract->special_price_reason = $reason;
            $contract->save();
        }

        $parent = $contract->owningBp?->parent;
        if ($parent === null) {
            // ルートBPは自己承認扱いで承認済へ
            return DB::transaction(function () use ($actor, $contract) {
                foreach ($contract->items as $line) {
                    $line->price_locked = true;
                    $line->save();
                }
                $this->transition($contract, ContractStatus::Approved, $actor, 'ルートBPのため価格自己確定');
                $this->priceLayers->syncForContract($contract->fresh(['owningBp', 'items.item']));
                $this->issueDocumentsForContract($contract);

                return Application::query()->create([
                    'type' => ApplicationType::PriceApproval,
                    'contract_id' => $contract->id,
                    'from_bp_id' => $contract->owning_bp_id,
                    'to_bp_id' => $contract->owning_bp_id,
                    'status' => ApplicationStatus::Approved,
                    'payload_json' => [
                        'auto' => true,
                        'special_price' => (bool) $contract->special_price_requested,
                        'special_price_reason' => $contract->special_price_reason,
                        'submitted_by_user_id' => $actor->id,
                    ],
                    'amount' => $contract->items->sum(fn ($i) => (float) $i->partition_price),
                    'decided_by_user_id' => $actor->id,
                    'decided_at' => now(),
                ]);
            });
        }

        $amount = $contract->items->sum(fn ($i) => (float) $i->partition_price);
        $isSpecial = (bool) $contract->special_price_requested;
        $resume = $this->rejectedChainResumePoint($contract);
        $chainPayload = $resume['chain'] ?? $this->buildApprovalChainPayload($contract);
        $toBpId = $resume['to_bp_id'] ?? (int) $parent->id;

        return DB::transaction(function () use ($actor, $contract, $toBpId, $amount, $isSpecial, $chainPayload, $resume) {
            $application = Application::query()->create([
                'type' => ApplicationType::PriceApproval,
                'contract_id' => $contract->id,
                'from_bp_id' => $contract->owning_bp_id,
                'to_bp_id' => $toBpId,
                'status' => ApplicationStatus::Pending,
                'payload_json' => [
                    'special_price' => $isSpecial,
                    'special_price_reason' => $isSpecial ? $contract->special_price_reason : null,
                    'chain' => $chainPayload,
                    'resumed_from_application_id' => $resume['rejected_application_id'] ?? null,
                    'submitted_by_user_id' => $actor->id,
                    'items' => $contract->items->map(function (ContractItem $line) {
                        $standard = $line->standard_partition_price !== null
                            ? (int) $line->standard_partition_price
                            : (int) $line->partition_price;
                        $requested = (int) $line->partition_price;

                        return [
                            'contract_item_id' => $line->id,
                            'item_id' => $line->item_id,
                            'unit_price' => (string) $line->unit_price,
                            'partition_price' => (string) $requested,
                            'standard_partition_price' => (string) $standard,
                            'partition_diff' => $standard - $requested,
                        ];
                    })->all(),
                ],
                'amount' => $amount,
            ]);

            $note = $isSpecial ? '特価申請（価格承認）' : '価格承認申請';
            if (($resume['rejected_application_id'] ?? null) !== null) {
                $note .= '（却下後の再申請 '.$application->approvalProgressLabel().'）';
            }
            $this->transition($contract, ContractStatus::PendingPriceApproval, $actor, $note);
            $this->audit($actor, 'contract.price_approval.submit', $contract, [
                'application_id' => $application->id,
                'special_price' => $isSpecial,
                'chain' => $chainPayload,
                'resumed' => ($resume['rejected_application_id'] ?? null) !== null,
            ]);

            $this->notifications->notifyApplication($application, $actor, 'submitted');

            return $application;
        });
    }

    public function decidePriceApproval(User $actor, Application $application, bool $approve, ?string $note = null): Application
    {
        if ($application->type !== ApplicationType::PriceApproval || $application->status !== ApplicationStatus::Pending) {
            throw new InvalidArgumentException('処理可能な価格承認申請ではありません。');
        }

        $application->loadMissing(['contract.items.item', 'contract.owningBp']);
        $contract = $application->contract;

        $this->authorization->authorize($actor, 'contract.approve', [
            'resource_type' => 'contract',
            'owner_bp_id' => $application->from_bp_id,
            'amount' => (float) $application->amount,
            'depth_diff_to_applicant' => $this->depthDiffToApplicant($actor, $application),
        ]);

        if ($actor->user_type === UserType::Bp) {
            abort_unless(
                (int) $actor->bp_id === (int) $application->to_bp_id,
                403,
                '承認先BPのユーザーのみ処理できます。'
            );
        }

        return DB::transaction(function () use ($actor, $application, $contract, $approve, $note) {
            $application->status = $approve ? ApplicationStatus::Approved : ApplicationStatus::Rejected;
            $application->decided_by_user_id = $actor->id;
            $application->decided_at = now();
            $application->save();

            if (! $approve) {
                $this->transition($contract, ContractStatus::Draft, $actor, $note ?? '価格却下');
                $this->audit($actor, 'contract.price_approval.reject', $contract);
                $rejected = $application->fresh();
                $this->notifications->notifyApplication($rejected, $actor, 'rejected');

                return $rejected;
            }

            $next = $this->nextApprovalRecipient($application);
            if ($next !== null) {
                $forwarded = $this->forwardPriceApproval($application, $next);
                $this->transition(
                    $contract,
                    ContractStatus::PendingPriceApproval,
                    $actor,
                    $note ?? '価格承認（上位BPへ回付 '.$forwarded->approvalProgressLabel().'）'
                );
                $this->audit($actor, 'contract.price_approval.approve', $contract, [
                    'application_id' => $application->id,
                    'forwarded_application_id' => $forwarded->id,
                    'chain' => $forwarded->payload_json['chain'] ?? null,
                ]);
                $this->notifications->notifyApplication($forwarded, $actor, 'forwarded');

                return $forwarded;
            }

            foreach ($contract->items as $line) {
                $line->price_locked = true;
                $line->save();
            }
            $this->transition($contract, ContractStatus::Approved, $actor, $note ?? '価格承認');
            $this->priceLayers->syncForContract($contract->fresh(['owningBp', 'items.item']));
            $this->issueDocumentsForContract($contract);
            $this->audit($actor, 'contract.price_approval.approve', $contract, [
                'application_id' => $application->id,
            ]);
            $approved = $application->fresh();
            $this->notifications->notifyApplication($approved, $actor, 'approved');

            return $approved;
        });
    }

    /**
     * 管理者登録（カタログ）品目を含む場合はルートまで連鎖承認する。
     */
    private function requiresRootApprovalChain(Contract $contract): bool
    {
        $contract->loadMissing('items.item');

        return $contract->items->contains(
            fn (ContractItem $line) => $line->item !== null && $line->item->owning_bp_id === null
        );
    }

    /**
     * @return array{required: bool, total: int, step: int, origin_bp_id: int}
     */
    private function buildApprovalChainPayload(Contract $contract): array
    {
        $contract->loadMissing('owningBp');
        $required = $this->requiresRootApprovalChain($contract);
        $total = 1;
        if ($required && $contract->owningBp) {
            $total = max(1, $this->hierarchy->ancestors($contract->owningBp, includeSelf: false)->count());
        }

        return [
            'required' => $required,
            'total' => $total,
            'step' => 1,
            'origin_bp_id' => (int) $contract->owning_bp_id,
        ];
    }

    /**
     * 連鎖承認で却下された場合、再申請は却下したBPの段階から再開する。
     *
     * @return array{to_bp_id: int, chain: array{required: bool, total: int, step: int, origin_bp_id: int}, rejected_application_id: int}|null
     */
    private function rejectedChainResumePoint(Contract $contract): ?array
    {
        $rejected = Application::query()
            ->where('contract_id', $contract->id)
            ->where('type', ApplicationType::PriceApproval->value)
            ->where('status', ApplicationStatus::Rejected->value)
            ->latest('id')
            ->first();

        if ($rejected === null) {
            return null;
        }

        $chain = is_array($rejected->payload_json) ? ($rejected->payload_json['chain'] ?? null) : null;
        if (! is_array($chain) || empty($chain['required'])) {
            return null;
        }

        $step = max(1, (int) ($chain['step'] ?? 1));
        $total = max($step, (int) ($chain['total'] ?? 1));

        return [
            'to_bp_id' => (int) $rejected->to_bp_id,
            'chain' => [
                'required' => true,
                'total' => $total,
                'step' => $step,
                'origin_bp_id' => (int) ($chain['origin_bp_id'] ?? $contract->owning_bp_id),
            ],
            'rejected_application_id' => (int) $rejected->id,
        ];
    }

    private function nextApprovalRecipient(Application $application): ?BusinessPartner
    {
        if (! $application->requiresApprovalChain()) {
            return null;
        }

        $progress = $application->approvalProgress();
        if ($progress !== null && $progress['step'] >= $progress['total']) {
            return null;
        }

        $current = BusinessPartner::query()->find($application->to_bp_id);
        $parent = $current?->parent;
        if ($parent === null) {
            return null;
        }

        return $parent;
    }

    private function forwardPriceApproval(Application $approved, BusinessPartner $nextTo): Application
    {
        $payload = is_array($approved->payload_json) ? $approved->payload_json : [];
        $chain = is_array($payload['chain'] ?? null) ? $payload['chain'] : [];
        $chain['step'] = (int) ($chain['step'] ?? 1) + 1;
        $chain['total'] = max((int) ($chain['total'] ?? 1), $chain['step']);
        $payload['chain'] = $chain;
        $payload['forwarded_from_application_id'] = $approved->id;

        return Application::query()->create([
            'type' => ApplicationType::PriceApproval,
            'contract_id' => $approved->contract_id,
            'from_bp_id' => $approved->from_bp_id,
            'to_bp_id' => $nextTo->id,
            'status' => ApplicationStatus::Pending,
            'payload_json' => $payload,
            'amount' => $approved->amount,
        ]);
    }

    public function submitPriceChange(User $actor, Contract $contract, array $changesByContractItemId): Application
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if ($actor->user_type === UserType::Bp && (int) $actor->bp_id !== (int) $contract->owning_bp_id) {
            throw new InvalidArgumentException('価格変更申請は契約の管理BPのみ行えます。');
        }

        if (! in_array($contract->status, [ContractStatus::Approved, ContractStatus::Activated], true)) {
            throw new InvalidArgumentException('承認後の契約のみ価格変更申請できます。');
        }

        $contract->loadMissing(['owningBp.parent', 'items']);
        $parent = $contract->owningBp?->parent;
        if ($parent === null) {
            throw new InvalidArgumentException('ルートBPの価格変更は管理者に依頼してください。');
        }

        $payloadItems = [];
        $amount = 0.0;
        foreach ($changesByContractItemId as $contractItemId => $row) {
            $line = $contract->items->firstWhere('id', (int) $contractItemId);
            if ($line === null) {
                continue;
            }
            $unit = $row['unit_price'] ?? $line->unit_price;
            $partition = $row['partition_price'] ?? $line->partition_price;
            $payloadItems[] = [
                'contract_item_id' => $line->id,
                'unit_price' => (string) $unit,
                'partition_price' => (string) $partition,
            ];
            $amount += (float) $partition;
        }

        if ($payloadItems === []) {
            throw new InvalidArgumentException('変更内容が空です。');
        }

        $application = Application::query()->create([
            'type' => ApplicationType::PriceChange,
            'contract_id' => $contract->id,
            'from_bp_id' => $contract->owning_bp_id,
            'to_bp_id' => $parent->id,
            'status' => ApplicationStatus::Pending,
            'payload_json' => [
                'items' => $payloadItems,
                'submitted_by_user_id' => $actor->id,
            ],
            'amount' => $amount,
        ]);

        $this->audit($actor, 'contract.price_change.submit', $contract, ['application_id' => $application->id]);
        $this->notifications->notifyApplication($application, $actor, 'submitted');

        return $application;
    }

    public function decidePriceChange(User $actor, Application $application, bool $approve, ?string $note = null): Application
    {
        if ($application->type !== ApplicationType::PriceChange || $application->status !== ApplicationStatus::Pending) {
            throw new InvalidArgumentException('処理可能な価格変更申請ではありません。');
        }

        $application->loadMissing('contract.items');
        $contract = $application->contract;

        $this->authorization->authorize($actor, 'contract.approve', [
            'resource_type' => 'contract',
            'owner_bp_id' => $application->from_bp_id,
            'amount' => (float) $application->amount,
            'depth_diff_to_applicant' => $this->depthDiffToApplicant($actor, $application),
        ]);

        if ($actor->user_type === UserType::Bp) {
            abort_unless((int) $actor->bp_id === (int) $application->to_bp_id, 403);
        }

        return DB::transaction(function () use ($actor, $application, $contract, $approve, $note) {
            $application->status = $approve ? ApplicationStatus::Approved : ApplicationStatus::Rejected;
            $application->decided_by_user_id = $actor->id;
            $application->decided_at = now();
            $application->save();

            if ($approve) {
                foreach ($application->payload_json['items'] ?? [] as $row) {
                    $line = $contract->items->firstWhere('id', (int) $row['contract_item_id']);
                    if ($line === null) {
                        continue;
                    }
                    $line->unit_price = $row['unit_price'];
                    $line->partition_price = $row['partition_price'];
                    $line->price_locked = true;
                    $line->save();
                }
                $this->priceLayers->syncForContract($contract->fresh(['owningBp', 'items.item']));
            }

            $this->audit(
                $actor,
                $approve ? 'contract.price_change.approve' : 'contract.price_change.reject',
                $contract,
                ['note' => $note]
            );

            $result = $application->fresh();
            $this->notifications->notifyApplication(
                $result,
                $actor,
                $approve ? 'approved' : 'rejected',
            );

            return $result;
        });
    }

    public function activate(User $actor, Contract $contract, string $firstBillingYearMonth): Contract
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if ($actor->user_type === UserType::Customer) {
            throw new InvalidArgumentException('カスタマーはサービス提供を確定できません。');
        }

        if ($contract->status !== ContractStatus::Approved) {
            throw new InvalidArgumentException('承認済の契約のみサービス提供開始にできます。');
        }

        $firstBillingYearMonth = $this->normalizeYearMonth($firstBillingYearMonth);

        $contract->loadMissing('items.item');
        $minTerm = $contract->items
            ->map(fn (ContractItem $line) => (int) ($line->item?->minimum_term_months ?? 0))
            ->filter(fn (int $months) => $months > 0)
            ->max();

        $contract->activated_at = now();
        $contract->first_billing_year_month = $firstBillingYearMonth;
        $contract->minimum_term_months_snapshot = $minTerm ?: null;
        $this->transition($contract, ContractStatus::Activated, $actor, 'サービス提供開始');
        $this->issueDocumentsForContract($contract);
        $this->audit($actor, 'contract.activate', $contract, [
            'first_billing_year_month' => $firstBillingYearMonth,
        ]);

        return $contract->fresh();
    }

    public function revertServiceProvided(User $actor, Contract $contract): Contract
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if ($actor->user_type === UserType::Customer) {
            throw new InvalidArgumentException('カスタマーはステータスを戻せません。');
        }

        if ($contract->status !== ContractStatus::Activated) {
            throw new InvalidArgumentException('サービス提供開始の契約のみ承認済へ戻せます。');
        }

        if (Invoice::query()->where('contract_id', $contract->id)->where('status', '!=', 'withdrawn')->exists()) {
            throw new InvalidArgumentException('請求が発行済みのためステータスを戻せません。');
        }

        $contract->activated_at = null;
        $this->transition($contract, ContractStatus::Approved, $actor, 'サービス提供の取消（戻し）');
        $this->audit($actor, 'contract.activate.revert', $contract);

        return $contract->fresh();
    }

    /**
     * @return array{suggested_amount: int, remaining_months: int, minimum_term_months: int, elapsed_months: int}
     */
    public function suggestCancellationAmount(Contract $contract, string $finalBillingYearMonth): array
    {
        $finalBillingYearMonth = $this->normalizeYearMonth($finalBillingYearMonth);
        $contract->loadMissing('items.item');

        $startYm = $contract->first_billing_year_month
            ?: ($contract->activated_at?->timezone(config('app.timezone'))->format('Ym'));
        if (! $startYm) {
            throw new InvalidArgumentException('初回請求月が未設定のため残期間を計算できません。');
        }

        $elapsed = $this->monthsBetweenInclusive($startYm, $finalBillingYearMonth);
        $minTerm = (int) ($contract->minimum_term_months_snapshot
            ?: $contract->items
                ->map(fn (ContractItem $line) => (int) ($line->item?->minimum_term_months ?? 0))
                ->filter(fn (int $months) => $months > 0)
                ->max()
            ?: 0);

        $remaining = max(0, $minTerm - $elapsed);
        $suggested = 0;
        foreach ($contract->items as $line) {
            if ($line->item?->billing_type !== BillingType::Running) {
                continue;
            }
            $term = (int) ($line->item->minimum_term_months ?? 0);
            if ($term <= 0) {
                continue;
            }
            $lineRemaining = max(0, $term - $elapsed);
            $suggested += (int) $line->unit_price * $lineRemaining;
        }

        return [
            'suggested_amount' => $suggested,
            'remaining_months' => $remaining,
            'minimum_term_months' => $minTerm,
            'elapsed_months' => $elapsed,
        ];
    }

    public function cancel(User $actor, Contract $contract, string $finalBillingYearMonth, int $cancellationAmount, ?string $note = null): Contract
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if ($actor->user_type === UserType::Customer) {
            throw new InvalidArgumentException('カスタマーは解約できません。');
        }

        if ($contract->status !== ContractStatus::Activated) {
            throw new InvalidArgumentException('サービス提供開始の契約のみ解約できます。');
        }

        $finalBillingYearMonth = $this->normalizeYearMonth($finalBillingYearMonth);
        if ($cancellationAmount < 0) {
            throw new InvalidArgumentException('解約金額は0以上で入力してください。');
        }

        $contract->final_billing_year_month = $finalBillingYearMonth;
        $contract->cancellation_amount = $cancellationAmount;
        $contract->cancellation_note = $note ? mb_substr($note, 0, 500) : null;
        $contract->cancelled_at = now();
        $this->transition($contract, ContractStatus::Cancelled, $actor, '解約');
        $this->audit($actor, 'contract.cancel', $contract, [
            'final_billing_year_month' => $finalBillingYearMonth,
            'cancellation_amount' => $cancellationAmount,
        ]);

        return $contract->fresh();
    }

    public function postMessage(User $actor, Contract $contract, string $body): ContractMessage
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.view', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if (in_array($contract->status, [ContractStatus::Activated, ContractStatus::Cancelled], true)) {
            throw new InvalidArgumentException('サービス提供開始後はメッセージを投稿できません。');
        }

        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('メッセージを入力してください。');
        }
        if (mb_strlen($body) > 2000) {
            throw new InvalidArgumentException('メッセージは2000文字以内で入力してください。');
        }

        $message = ContractMessage::query()->create([
            'contract_id' => $contract->id,
            'user_id' => $actor->id,
            'body' => $body,
        ]);

        $this->markMessagesRead($actor, $contract->fresh());
        $this->audit($actor, 'contract.message.create', $contract, ['message_id' => $message->id]);

        return $message;
    }

    public function canPostMessages(Contract $contract): bool
    {
        return ! in_array($contract->status, [ContractStatus::Activated, ContractStatus::Cancelled], true);
    }

    public function markMessagesRead(User $actor, Contract $contract): void
    {
        $lastMessageId = $contract->messages()->max('id');

        ContractMessageRead::query()->updateOrCreate(
            [
                'contract_id' => $contract->id,
                'user_id' => $actor->id,
            ],
            [
                'last_read_at' => now(),
                'last_read_message_id' => $lastMessageId,
            ],
        );
    }

    public function isMessagesUnread(User $actor, Contract $contract): bool
    {
        $lastMessage = $contract->relationLoaded('messages')
            ? $contract->messages->sortByDesc('id')->first()
            : $contract->messages()->latest('id')->first();

        if ($lastMessage === null || (int) $lastMessage->user_id === (int) $actor->id) {
            return false;
        }

        $read = ContractMessageRead::query()
            ->where('contract_id', $contract->id)
            ->where('user_id', $actor->id)
            ->first();

        if ($read === null || $read->last_read_message_id === null) {
            return true;
        }

        return (int) $lastMessage->id > (int) $read->last_read_message_id;
    }

    /**
     * @param  list<int>|null  $owningBpIds  null = 全契約（管理者）
     */
    public function unreadMessageContractCount(User $actor, ?array $owningBpIds = null, ?int $customerId = null): int
    {
        $query = Contract::query();

        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        } elseif ($owningBpIds !== null) {
            $query->whereIn('owning_bp_id', $owningBpIds);
        }

        $ids = $query->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        $count = 0;
        $contracts = Contract::query()
            ->whereIn('id', $ids)
            ->with([
                'messages' => fn ($q) => $q->latest('id')->limit(1),
            ])
            ->get();

        foreach ($contracts as $contract) {
            if ($this->isMessagesUnread($actor, $contract)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<int>
     */
    public function unreadMessageContractIds(User $actor, ?array $owningBpIds = null, ?int $customerId = null): array
    {
        $query = Contract::query();

        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        } elseif ($owningBpIds !== null) {
            $query->whereIn('owning_bp_id', $owningBpIds);
        }

        $ids = [];
        foreach ($query->with(['messages' => fn ($q) => $q->latest('id')->limit(1)])->get() as $contract) {
            if ($this->isMessagesUnread($actor, $contract)) {
                $ids[] = (int) $contract->id;
            }
        }

        return $ids;
    }

    /**
     * @param  array{
     *   auto_invoice_enabled?: bool,
     *   billing_suspended?: bool,
     *   end_user_billing_disabled?: bool,
     *   bill_initial_in_system?: bool,
     *   kickback_start_year_month?: ?string,
     *   recalc_on_price_change?: bool,
     *   items?: array<int, array{bill_initial_in_system?: ?bool, end_user_billing_disabled?: ?bool}>
     * }  $payload
     */
    public function updateBillingSettings(User $actor, Contract $contract, array $payload): Contract
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'invoice.manage', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if ($actor->user_type === UserType::Customer) {
            throw new InvalidArgumentException('カスタマーは請求設定を変更できません。');
        }

        $contract->loadMissing(['items.item', 'owningBp']);

        $changingEuContract = array_key_exists('end_user_billing_disabled', $payload)
            && (bool) $payload['end_user_billing_disabled'] !== (bool) $contract->end_user_billing_disabled;
        if ($changingEuContract) {
            $this->assertCanToggleEndUserBilling($actor, $contract, null);
        }

        return DB::transaction(function () use ($actor, $contract, $payload) {
            if (array_key_exists('auto_invoice_enabled', $payload)) {
                $contract->auto_invoice_enabled = (bool) $payload['auto_invoice_enabled'];
            }
            if (array_key_exists('billing_suspended', $payload)) {
                $contract->billing_suspended = (bool) $payload['billing_suspended'];
            }
            if (array_key_exists('end_user_billing_disabled', $payload)) {
                $contract->end_user_billing_disabled = (bool) $payload['end_user_billing_disabled'];
            }
            if (array_key_exists('bill_initial_in_system', $payload)) {
                $contract->bill_initial_in_system = (bool) $payload['bill_initial_in_system'];
            }
            if (array_key_exists('recalc_on_price_change', $payload)) {
                $contract->recalc_on_price_change = (bool) $payload['recalc_on_price_change'];
            }
            if (array_key_exists('kickback_start_year_month', $payload)) {
                $ym = $payload['kickback_start_year_month'];
                if ($ym === null || $ym === '') {
                    $contract->kickback_start_year_month = null;
                } else {
                    $contract->kickback_start_year_month = $this->normalizeYearMonth((string) $ym);
                }
            }
            $contract->save();

            $itemPayloads = $payload['items'] ?? [];
            foreach ($itemPayloads as $contractItemId => $itemData) {
                /** @var ContractItem|null $line */
                $line = $contract->items->firstWhere('id', (int) $contractItemId);
                if (! $line) {
                    continue;
                }

                if (array_key_exists('end_user_billing_disabled', $itemData)) {
                    $raw = $itemData['end_user_billing_disabled'];
                    $next = $raw === '' || $raw === null ? null : (bool) $raw;
                    if ($next !== $line->end_user_billing_disabled) {
                        $this->assertCanToggleEndUserBilling($actor, $contract, $line);
                        $line->end_user_billing_disabled = $next;
                    }
                }
                if (array_key_exists('bill_initial_in_system', $itemData)) {
                    $raw = $itemData['bill_initial_in_system'];
                    $line->bill_initial_in_system = $raw === '' || $raw === null ? null : (bool) $raw;
                }
                $line->save();
            }

            $this->audit($actor, 'contract.billing.update', $contract, [
                'auto_invoice_enabled' => $contract->auto_invoice_enabled,
                'billing_suspended' => $contract->billing_suspended,
                'end_user_billing_disabled' => $contract->end_user_billing_disabled,
            ]);

            return $contract->fresh(['items.item', 'owningBp']);
        });
    }

    private function assertCanToggleEndUserBilling(User $actor, Contract $contract, ?ContractItem $line): void
    {
        if ($actor->user_type === UserType::Admin) {
            return;
        }

        if ($actor->user_type !== UserType::Bp || $actor->businessPartner === null) {
            throw new InvalidArgumentException('EU請求設定を変更する権限がありません。');
        }

        $actorBp = $actor->businessPartner;

        if ($line === null) {
            $contract->loadMissing('owningBp');
            if ($contract->owningBp && $this->hierarchy->isSelfOrDescendant($actorBp, $contract->owningBp)) {
                return;
            }

            throw new InvalidArgumentException('管理BPまたはその上位BPのみ契約のEU請求を変更できます。');
        }

        $line->loadMissing('item');
        $itemOwnerId = $line->item?->owning_bp_id;
        if ($itemOwnerId === null) {
            throw new InvalidArgumentException('管理者品目のEU請求設定は管理者のみ変更できます。');
        }

        $itemOwner = BusinessPartner::query()->find($itemOwnerId);
        if ($itemOwner && $this->hierarchy->isSelfOrDescendant($actorBp, $itemOwner)) {
            return;
        }

        throw new InvalidArgumentException('品目管理者またはその上位BPのみ明細のEU請求を変更できます。');
    }

    private function normalizeYearMonth(string $yearMonth): string
    {
        $normalized = preg_replace('/\D+/', '', $yearMonth) ?? '';
        if (! preg_match('/^\d{6}$/', $normalized)) {
            throw new InvalidArgumentException('請求月は YYYYMM 形式で入力してください。');
        }
        $month = (int) substr($normalized, 4, 2);
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('請求月の月が不正です。');
        }

        return $normalized;
    }

    private function monthsBetweenInclusive(string $startYm, string $endYm): int
    {
        $start = ((int) substr($startYm, 0, 4)) * 12 + ((int) substr($startYm, 4, 2));
        $end = ((int) substr($endYm, 0, 4)) * 12 + ((int) substr($endYm, 4, 2));
        if ($end < $start) {
            throw new InvalidArgumentException('最終請求月は初回請求月以降を指定してください。');
        }

        return $end - $start + 1;
    }

    public function regenerateDocuments(User $actor, Contract $contract): Contract
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if (! in_array($contract->status, [ContractStatus::Approved, ContractStatus::Activated], true)) {
            throw new InvalidArgumentException('承認後の契約のみドキュメントを再生成できます。');
        }

        $result = $this->issueDocumentsForContract($contract);
        if ($result['template_count'] === 0) {
            throw new InvalidArgumentException(
                'この契約の品目に Document テンプレートがありません。品目詳細でテンプレートを登録してから再度実行してください。'
            );
        }
        if ($result['generated'] === 0) {
            $detail = $result['failures'] !== []
                ? implode(' / ', array_slice($result['failures'], 0, 3))
                : '原因不明';
            throw new InvalidArgumentException('Document の生成に失敗しました: '.$detail);
        }

        $this->audit($actor, 'contract.documents.regenerate', $contract, [
            'generated' => $result['generated'],
            'failed' => count($result['failures']),
        ]);

        return $contract->fresh(['items.documents']);
    }

    public function deleteContractItemDocument(User $actor, ContractItemDocument $document): void
    {
        $document->loadMissing('contractItem.contract');
        $contract = $document->contractItem?->contract;
        abort_unless($contract, 404);

        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if (! in_array($contract->status, [ContractStatus::Approved, ContractStatus::Activated, ContractStatus::Cancelled], true)) {
            throw new InvalidArgumentException('この状態の契約ではドキュメントを削除できません。');
        }

        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        $meta = [
            'contract_id' => $contract->id,
            'contract_item_id' => $document->contract_item_id,
            'title' => $document->title,
        ];
        $document->delete();

        $this->audit($actor, 'contract.document.delete', $contract, $meta);
    }

    public function upsertItemData(User $actor, ContractItem $contractItem, array $rows): void
    {
        $contractItem->loadMissing('contract');
        $this->ensureContractScope($actor, $contractItem->contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contractItem->contract->owning_bp_id,
        ]);

        if (! in_array($contractItem->contract->status, [ContractStatus::Approved, ContractStatus::Activated], true)) {
            throw new InvalidArgumentException('承認後の契約のみデータを登録できます。');
        }

        $normalized = $this->normalizeDataRows($rows);

        DB::transaction(function () use ($contractItem, $normalized) {
            $contractItem->dataRows()->delete();
            foreach ($normalized as $index => $row) {
                ContractItemData::query()->create([
                    'contract_item_id' => $contractItem->id,
                    'data_field_name_id' => $row['data_field_name_id'],
                    'name' => $row['name'],
                    'replace_code' => $row['replace_code'],
                    'value' => $row['value'],
                    'sort_order' => $index,
                ]);
            }
        });

        $this->audit($actor, 'contract.data.upsert', $contractItem->contract, [
            'contract_item_id' => $contractItem->id,
            'row_count' => count($normalized),
        ]);
    }

    public function upsertContractData(User $actor, Contract $contract, array $rows): void
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if (! in_array($contract->status, [ContractStatus::Approved, ContractStatus::Activated], true)) {
            throw new InvalidArgumentException('承認後の契約のみデータを登録できます。');
        }

        $normalized = $this->normalizeDataRows($rows);

        DB::transaction(function () use ($contract, $normalized) {
            $contract->dataRows()->delete();
            foreach ($normalized as $index => $row) {
                ContractData::query()->create([
                    'contract_id' => $contract->id,
                    'data_field_name_id' => $row['data_field_name_id'],
                    'name' => $row['name'],
                    'replace_code' => $row['replace_code'],
                    'value' => $row['value'],
                    'sort_order' => $index,
                ]);
            }
        });

        $this->audit($actor, 'contract.common_data.upsert', $contract, [
            'row_count' => count($normalized),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{data_field_name_id: int|null, name: string, replace_code: string, value: string}>
     */
    private function normalizeDataRows(array $rows): array
    {
        $normalized = [];

        foreach (array_values($rows) as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $fieldId = $row['data_field_name_id'] ?? null;
            $name = trim((string) ($row['name'] ?? ''));
            $replaceCode = trim((string) ($row['replace_code'] ?? ''));

            if ($fieldId) {
                $master = DataFieldName::query()->whereKey($fieldId)->where('is_active', true)->first();
                if ($master) {
                    $name = $master->name;
                    $fieldId = $master->id;
                    $replaceCode = (string) ($master->replace_code ?? '');
                } else {
                    $fieldId = null;
                }
            }

            if ($name === '') {
                continue;
            }

            if ($replaceCode === '') {
                throw new InvalidArgumentException("「{$name}」の置換コードを入力してください。");
            }

            if (! preg_match(ReservedReplaceCodes::pattern(), $replaceCode)) {
                throw new InvalidArgumentException("置換コード「{$replaceCode}」の形式が不正です。");
            }

            if (ReservedReplaceCodes::isReserved($replaceCode)) {
                throw new InvalidArgumentException("置換コード「{$replaceCode}」は予約語のため使用できません。");
            }

            $normalized[] = [
                'data_field_name_id' => $fieldId ? (int) $fieldId : null,
                'name' => $name,
                'replace_code' => $replaceCode,
                'value' => $value,
            ];
        }

        if (count($normalized) > self::MAX_DATA_ROWS) {
            throw new InvalidArgumentException('データは最大'.self::MAX_DATA_ROWS.'行までです。');
        }

        return $normalized;
    }

    public function addItemDocument(User $actor, Item $item, string $title, UploadedFile $file): ItemDocument
    {
        $owningBpId = null;
        if ($actor->user_type === UserType::Admin) {
            $this->authorization->authorize($actor, 'item.manage');
        } elseif ($actor->user_type === UserType::Bp) {
            $this->authorization->authorize($actor, 'contract.create');
            $actorBp = $actor->businessPartner;
            abort_unless($actorBp, 403);
            if ($item->isBpOwned() && (int) $item->owning_bp_id !== (int) $actorBp->id) {
                throw new InvalidArgumentException('他BPの独自サービスには Document を登録できません。');
            }
            $owningBpId = $actorBp->id;
        } else {
            throw new InvalidArgumentException('Documentテンプレートを登録する権限がありません。');
        }

        $scopedCount = $item->documents()
            ->when(
                $owningBpId === null,
                fn ($q) => $q->whereNull('owning_bp_id'),
                fn ($q) => $q->where('owning_bp_id', $owningBpId)
            )
            ->count();
        if ($scopedCount >= self::MAX_ITEM_DOCUMENTS) {
            throw new InvalidArgumentException('Documentテンプレートは最大'.self::MAX_ITEM_DOCUMENTS.'件までです。');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::ITEM_DOCUMENT_EXTENSIONS, true)) {
            throw new InvalidArgumentException(
                'Documentテンプレートは Excel（.xls / .xlsx）、XML、HTML のみアップロードできます。'
            );
        }

        $path = $file->store('item-documents/'.$item->id, 'local');

        return ItemDocument::query()->create([
            'item_id' => $item->id,
            'owning_bp_id' => $owningBpId,
            'title' => $title,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'sort_order' => (int) $item->documents()->max('sort_order') + 1,
        ]);
    }

    /**
     * テンプレートを削除する。契約へ発行済みの PDF は残し、参照のみ外す。
     * 再生成時は「現時点のテンプレート」だけで PDF を追加・更新する。
     */
    public function deleteItemDocument(User $actor, ItemDocument $document): void
    {
        $document->loadMissing('item');

        if ($actor->user_type === UserType::Admin) {
            $this->authorization->authorize($actor, 'item.manage');
        } elseif ($actor->user_type === UserType::Bp) {
            $this->authorization->authorize($actor, 'contract.create');
            $actorBp = $actor->businessPartner;
            abort_unless($actorBp, 403);
            if ($document->owning_bp_id === null || (int) $document->owning_bp_id !== (int) $actorBp->id) {
                throw new InvalidArgumentException('標準テンプレート、または他BPのテンプレートは削除できません。');
            }
            $item = $document->item;
            if ($item && $item->isBpOwned() && (int) $item->owning_bp_id !== (int) $actorBp->id) {
                throw new InvalidArgumentException('他BPの独自サービスに紐づくテンプレートは削除できません。');
            }
        } else {
            throw new InvalidArgumentException('Documentテンプレートを削除する権限がありません。');
        }

        // 発行済み PDF のファイルは消さず、テンプレート参照だけ外す（FK の SET NULL と同等を明示）
        ContractItemDocument::query()
            ->where('item_document_id', $document->id)
            ->update(['item_document_id' => null]);

        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        $meta = [
            'item_id' => $document->item_id,
            'title' => $document->title,
            'owning_bp_id' => $document->owning_bp_id,
        ];
        $document->delete();

        $this->auditLogger->log(
            category: 'catalog',
            action: 'item.document.delete',
            result: 'success',
            actor: $actor,
            targetType: ItemDocument::class,
            targetId: $meta['item_id'],
            meta: $meta,
        );
    }

    /**
     * 現時点のテンプレートから PDF を追加・更新する。
     * 削除済みテンプレート由来の発行済み PDF（item_document_id = null）は残す。
     *
     * @return array{template_count: int, generated: int, failures: list<string>}
     */
    public function issueDocumentsForContract(Contract $contract): array
    {
        $contract->loadMissing('items.item.documents', 'items.documents', 'items.dataRows.dataFieldName', 'dataRows.dataFieldName', 'customer', 'site', 'owningBp');
        $owningBpId = (int) $contract->owning_bp_id;
        $templateCount = 0;
        $generated = 0;
        $failures = [];

        foreach ($contract->items as $line) {
            if ($line->item === null) {
                continue;
            }

            $templates = $line->item->documents
                ->filter(fn (ItemDocument $doc) => $doc->owning_bp_id === null || (int) $doc->owning_bp_id === $owningBpId)
                ->values();
            $templateCount += $templates->count();

            foreach ($templates as $doc) {
                try {
                    $rendered = $this->documentRender->renderPdf($line, $doc);
                } catch (Throwable $exception) {
                    $failures[] = ($line->item->code ?? '品目').'/'.$doc->title.': '.$exception->getMessage();
                    $this->auditLogger->log(
                        'contract',
                        'contract.document.render_failed',
                        'failure',
                        null,
                        targetType: Contract::class,
                        targetId: $contract->id,
                        meta: [
                            'contract_item_id' => $line->id,
                            'item_document_id' => $doc->id,
                            'message' => $exception->getMessage(),
                        ],
                    );

                    continue;
                }

                try {
                    $existing = $line->documents()->where('item_document_id', $doc->id)->first();
                    $attrs = [
                        'title' => $doc->title,
                        'original_name' => pathinfo($doc->original_name ?: $doc->title, PATHINFO_FILENAME).'.pdf',
                        'mime_type' => 'application/pdf',
                    ];

                    if ($existing) {
                        // 同一テンプレートの再生成は上書き。過去テンプレート由来の別レコードは触らない。
                        $target = $existing->file_path
                            ?: 'contract-documents/'.$contract->id.'/'.$line->id.'/'.$existing->id.'.pdf';
                        Storage::disk('local')->put($target, $rendered['binary']);
                        $existing->update([
                            ...$attrs,
                            'file_path' => $target,
                        ]);
                    } else {
                        $created = ContractItemDocument::query()->create([
                            'contract_item_id' => $line->id,
                            'item_document_id' => $doc->id,
                            'file_path' => 'pending',
                            ...$attrs,
                        ]);
                        $target = 'contract-documents/'.$contract->id.'/'.$line->id.'/'.$created->id.'.pdf';
                        Storage::disk('local')->put($target, $rendered['binary']);
                        $created->update(['file_path' => $target]);
                    }
                    $generated++;
                } catch (Throwable $exception) {
                    $failures[] = ($line->item->code ?? '品目').'/'.$doc->title.': '.$exception->getMessage();
                    $this->auditLogger->log(
                        'contract',
                        'contract.document.store_failed',
                        'failure',
                        null,
                        targetType: Contract::class,
                        targetId: $contract->id,
                        meta: [
                            'contract_item_id' => $line->id,
                            'item_document_id' => $doc->id,
                            'message' => $exception->getMessage(),
                        ],
                    );
                }
            }
        }

        return [
            'template_count' => $templateCount,
            'generated' => $generated,
            'failures' => $failures,
        ];
    }

    private function assertRequiredItemsSatisfied($items): void
    {
        $ids = $items->pluck('id')->all();
        foreach ($items as $item) {
            if ($item->required_item_id && ! in_array($item->required_item_id, $ids, true)) {
                $required = Item::query()->find($item->required_item_id);
                throw new InvalidArgumentException(
                    "「{$item->name}」には必須セット品目「".($required?->name ?? $item->required_item_id).'」が必要です。'
                );
            }
        }
    }

    private function assertEditableDraft(User $actor, Contract $contract): void
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if (! in_array($contract->status, [ContractStatus::Draft, ContractStatus::PendingPriceApproval], true)) {
            // pending では価格更新不可。submit 前の draft のみ updateDraftPrices
        }

        if ($contract->status !== ContractStatus::Draft) {
            throw new InvalidArgumentException('オーダー作成中の契約のみ編集できます。');
        }
    }

    private function ensureCustomerScope(User $actor, Customer $customer): void
    {
        if ($actor->user_type !== UserType::Bp) {
            return;
        }
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);
        $customer->loadMissing('managingBp');
        abort_unless(
            $customer->managingBp && $this->hierarchy->isSelfOrDescendant($actorBp, $customer->managingBp),
            403
        );
    }

    private function ensureContractScope(User $actor, Contract $contract): void
    {
        if ($actor->user_type === UserType::Customer) {
            abort_unless((int) $actor->customer_id === (int) $contract->customer_id, 403);

            return;
        }
        if ($actor->user_type === UserType::Bp) {
            $actorBp = $actor->businessPartner;
            abort_unless($actorBp, 403);
            $contract->loadMissing('owningBp');
            abort_unless($this->hierarchy->isSelfOrDescendant($actorBp, $contract->owningBp), 403);
        }
    }

    public function assertActorCanAccessContract(User $actor, Contract $contract): void
    {
        $this->ensureContractScope($actor, $contract);
    }

    private function transition(Contract $contract, ContractStatus $to, User $actor, ?string $note): void
    {
        $from = $contract->status;
        $contract->status = $to;
        if ($to === ContractStatus::PendingPriceApproval || $to === ContractStatus::Approved) {
            $contract->applied_at = $contract->applied_at ?? now();
        }
        $contract->save();

        ContractStatusHistory::query()->create([
            'contract_id' => $contract->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $actor->id,
            'note' => $note,
        ]);
    }

    private function recordStatus(Contract $contract, ?ContractStatus $from, ContractStatus $to, User $actor, ?string $note): void
    {
        ContractStatusHistory::query()->create([
            'contract_id' => $contract->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $actor->id,
            'note' => $note,
        ]);
    }

    private function depthDiffToApplicant(User $actor, Application $application): ?int
    {
        if ($actor->user_type !== UserType::Bp || ! $actor->bp_id) {
            return null;
        }

        $relation = $this->relationResolver->resolve((int) $actor->bp_id, (int) $application->from_bp_id);

        return $relation['depth_diff_to_applicant'];
    }

    private function audit(User $actor, string $action, Contract $contract, array $meta = []): void
    {
        $this->auditLogger->log(
            category: 'contract',
            action: $action,
            result: 'success',
            actor: $actor,
            targetType: Contract::class,
            targetId: $contract->id,
            meta: array_merge(['code' => $contract->code], $meta),
        );
    }
}
