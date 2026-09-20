<?php

namespace App\Domains\Contract\Services;

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Contract\Enums\ApplicationStatus;
use App\Domains\Contract\Enums\ApplicationType;
use App\Domains\Contract\Enums\ContractStatus;
use App\Domains\Iam\Services\AuditLogger;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\BpRelationResolver;
use App\Models\Application;
use App\Models\BusinessPartner;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractItemData;
use App\Models\ContractItemDocument;
use App\Models\ContractStatusHistory;
use App\Models\Customer;
use App\Models\DataFieldName;
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
    ) {}

    public function createDraft(User $actor, Site $site, array $itemIds): Contract
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

        $items = Item::query()->whereIn('id', $itemIds)->where('is_active', true)->get();
        if ($items->count() !== count($itemIds)) {
            throw new InvalidArgumentException('無効な品目が含まれています。');
        }

        $this->assertRequiredItemsSatisfied($items);

        $owningBp = $customer->managingBp;
        $parentBp = $owningBp->parent;

        return DB::transaction(function () use ($actor, $site, $customer, $owningBp, $parentBp, $items) {
            $contract = Contract::query()->create([
                'code' => $this->sequences->next(PartnerCodePrefix::Contract),
                'site_id' => $site->id,
                'customer_id' => $customer->id,
                'owning_bp_id' => $owningBp->id,
                'status' => ContractStatus::Draft,
            ]);

            foreach ($items as $item) {
                $partition = $parentBp
                    ? $this->pricing->resolveWholesaleAmount($item, $parentBp, $owningBp)
                    : (string) $item->partition_price;
                $unit = $this->pricing->resolveCustomerAmount($item, $customer, $owningBp);

                ContractItem::query()->create([
                    'contract_id' => $contract->id,
                    'item_id' => $item->id,
                    'unit_price' => (int) $unit,
                    'partition_price' => (int) $partition,
                    'tax_rate' => (int) ($item->tax_rate ?? 10),
                    'price_locked' => false,
                ]);
            }

            $this->recordStatus($contract, null, ContractStatus::Draft, $actor, '下書き作成');
            $this->audit($actor, 'contract.create', $contract);

            return $contract->load('items.item');
        });
    }

    public function deleteDraft(User $actor, Contract $contract): void
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if ($contract->status !== ContractStatus::Draft) {
            throw new InvalidArgumentException('下書き状態の契約のみ削除できます。');
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

    public function updateDraftPrices(User $actor, Contract $contract, array $pricesByContractItemId): Contract
    {
        $this->assertEditableDraft($actor, $contract);

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
            if (isset($row['partition_price'])) {
                $line->partition_price = (int) round((float) $row['partition_price']);
            }
            $line->save();
        }

        $this->audit($actor, 'contract.prices.update', $contract);

        return $contract->fresh(['items.item']);
    }

    public function submitPriceApproval(User $actor, Contract $contract): Application
    {
        $this->assertEditableDraft($actor, $contract);
        $contract->loadMissing(['owningBp.parent', 'items']);

        $parent = $contract->owningBp?->parent;
        if ($parent === null) {
            // ルートBPは自己承認扱いで承認済へ
            return DB::transaction(function () use ($actor, $contract) {
                foreach ($contract->items as $line) {
                    $line->price_locked = true;
                    $line->save();
                }
                $this->transition($contract, ContractStatus::Approved, $actor, 'ルートBPのため価格自己確定');
                $this->issueDocumentsForContract($contract);

                return Application::query()->create([
                    'type' => ApplicationType::PriceApproval,
                    'contract_id' => $contract->id,
                    'from_bp_id' => $contract->owning_bp_id,
                    'to_bp_id' => $contract->owning_bp_id,
                    'status' => ApplicationStatus::Approved,
                    'payload_json' => ['auto' => true],
                    'amount' => $contract->items->sum(fn ($i) => (float) $i->partition_price),
                    'decided_by_user_id' => $actor->id,
                    'decided_at' => now(),
                ]);
            });
        }

        $amount = $contract->items->sum(fn ($i) => (float) $i->partition_price);

        return DB::transaction(function () use ($actor, $contract, $parent, $amount) {
            $application = Application::query()->create([
                'type' => ApplicationType::PriceApproval,
                'contract_id' => $contract->id,
                'from_bp_id' => $contract->owning_bp_id,
                'to_bp_id' => $parent->id,
                'status' => ApplicationStatus::Pending,
                'payload_json' => [
                    'items' => $contract->items->map(fn (ContractItem $line) => [
                        'contract_item_id' => $line->id,
                        'unit_price' => (string) $line->unit_price,
                        'partition_price' => (string) $line->partition_price,
                    ])->all(),
                ],
                'amount' => $amount,
            ]);

            $this->transition($contract, ContractStatus::PendingPriceApproval, $actor, '価格承認申請');
            $this->audit($actor, 'contract.price_approval.submit', $contract, ['application_id' => $application->id]);

            return $application;
        });
    }

    public function decidePriceApproval(User $actor, Application $application, bool $approve, ?string $note = null): Application
    {
        if ($application->type !== ApplicationType::PriceApproval || $application->status !== ApplicationStatus::Pending) {
            throw new InvalidArgumentException('処理可能な価格承認申請ではありません。');
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

            if ($approve) {
                foreach ($contract->items as $line) {
                    $line->price_locked = true;
                    $line->save();
                }
                $this->transition($contract, ContractStatus::Approved, $actor, $note ?? '価格承認');
                $this->issueDocumentsForContract($contract);
            } else {
                $this->transition($contract, ContractStatus::Draft, $actor, $note ?? '価格却下');
            }

            $this->audit($actor, $approve ? 'contract.price_approval.approve' : 'contract.price_approval.reject', $contract);

            return $application->fresh();
        });
    }

    public function submitPriceChange(User $actor, Contract $contract, array $changesByContractItemId): Application
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

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
            'payload_json' => ['items' => $payloadItems],
            'amount' => $amount,
        ]);

        $this->audit($actor, 'contract.price_change.submit', $contract, ['application_id' => $application->id]);

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
            }

            $this->audit(
                $actor,
                $approve ? 'contract.price_change.approve' : 'contract.price_change.reject',
                $contract,
                ['note' => $note]
            );

            return $application->fresh();
        });
    }

    public function activate(User $actor, Contract $contract): Contract
    {
        $this->ensureContractScope($actor, $contract);
        $this->authorization->authorize($actor, 'contract.create', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        if ($contract->status !== ContractStatus::Approved) {
            throw new InvalidArgumentException('承認済の契約のみ開通できます。');
        }

        $contract->activated_at = now();
        $this->transition($contract, ContractStatus::Activated, $actor, '開通');
        $this->issueDocumentsForContract($contract);
        $this->audit($actor, 'contract.activate', $contract);

        return $contract->fresh();
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

        $this->issueDocumentsForContract($contract);
        $this->audit($actor, 'contract.documents.regenerate', $contract);

        return $contract->fresh(['items.documents']);
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

        DB::transaction(function () use ($contractItem, $rows) {
            $contractItem->dataRows()->delete();
            foreach (array_values($rows) as $index => $row) {
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

                ContractItemData::query()->create([
                    'contract_item_id' => $contractItem->id,
                    'data_field_name_id' => $fieldId,
                    'name' => $name,
                    'replace_code' => $replaceCode,
                    'value' => $value,
                    'sort_order' => $index,
                ]);
            }
        });

        $this->audit($actor, 'contract.data.upsert', $contractItem->contract, [
            'contract_item_id' => $contractItem->id,
        ]);
    }

    public function addItemDocument(User $actor, Item $item, string $title, UploadedFile $file): ItemDocument
    {
        if ($actor->user_type !== UserType::Admin) {
            throw new InvalidArgumentException('Documentテンプレートは管理者のみ登録できます。');
        }
        $this->authorization->authorize($actor, 'item.manage');

        if ($item->documents()->count() >= self::MAX_ITEM_DOCUMENTS) {
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
            'title' => $title,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'sort_order' => (int) $item->documents()->max('sort_order') + 1,
        ]);
    }

    public function issueDocumentsForContract(Contract $contract): void
    {
        $contract->loadMissing('items.item.documents', 'items.documents', 'items.dataRows.dataFieldName', 'customer', 'site', 'owningBp');

        foreach ($contract->items as $line) {
            foreach ($line->item->documents as $doc) {
                try {
                    $rendered = $this->documentRender->renderPdf($line, $doc);
                } catch (Throwable $exception) {
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

                $target = 'contract-documents/'.$contract->id.'/'.$line->id.'/'.$doc->id.'.pdf';
                Storage::disk('local')->put($target, $rendered['binary']);

                $existing = $line->documents()->where('item_document_id', $doc->id)->first();
                $attrs = [
                    'title' => $doc->title,
                    'file_path' => $target,
                    'original_name' => pathinfo($doc->original_name ?: $doc->title, PATHINFO_FILENAME).'.pdf',
                    'mime_type' => 'application/pdf',
                ];

                if ($existing) {
                    if ($existing->file_path !== $target && Storage::disk('local')->exists($existing->file_path)) {
                        Storage::disk('local')->delete($existing->file_path);
                    }
                    $existing->update($attrs);
                } else {
                    ContractItemDocument::query()->create([
                        'contract_item_id' => $line->id,
                        'item_document_id' => $doc->id,
                        ...$attrs,
                    ]);
                }
            }
        }
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
            throw new InvalidArgumentException('下書き状態の契約のみ編集できます。');
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
