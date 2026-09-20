<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Contract\Services\DocumentRenderService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\Customer;
use App\Models\DataFieldName;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
    Storage::fake('local');
});

it('replaces placeholders and renders pdf from html template', function () {
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $parent = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Render Parent');
    $child = $hierarchy->createChild($parent, $seq->next(PartnerCodePrefix::Bpn), 'Render Child');
    $hierarchy->update($child, [
        'postal_code' => '100-0001',
        'address' => '東京都千代田区1-1',
        'phone' => '03-1111-2222',
        'email' => 'bp@example.com',
    ]);

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $child->id,
        'name' => 'Render Customer',
        'entity_type' => 'corporate',
        'two_factor_mode' => TwoFactorMode::Optional,
        'is_active' => true,
    ]);
    $site = Site::query()->create([
        'customer_id' => $customer->id,
        'name' => '本社拠点',
        'postal_code' => '150-0001',
        'address' => '渋谷区',
        'billing_name' => '請求太郎',
        'is_primary' => true,
        'is_active' => true,
    ]);

    $admin = User::factory()->admin()->create(['login_id' => 'RENADMIN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);
    $bpUser = User::factory()->bp($child)->create(['login_id' => 'RENBP', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $child->id);
    $parentUser = User::factory()->bp($parent)->create(['login_id' => 'RENPARENT', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($parentUser, 'bp_owner', RoleScope::Bp, $parent->id);

    $item = app(CatalogPricingService::class)->createItem($admin, [
        'name' => '月額プラン',
        'billing_type' => BillingType::Running->value,
        'partition_price' => 1000,
        'user_price' => 2000,
        'tax_rate' => 10,
    ]);

    $html = <<<'HTML'
<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>
<p>{{bp_name}} / {{c_name}} / {{s_name}}</p>
<p>{{iname}} {{price}} {{price_in}} {{tax}} {{rate}}</p>
<p>回線: {{line_id}}</p>
<p>未知: {{unknown_code}}</p>
</body></html>
HTML;
    $file = UploadedFile::fake()->createWithContent('notice.html', $html);
    $template = app(ContractService::class)->addItemDocument($admin, $item, '開通案内', $file);

    DataFieldName::query()->create(['name' => '回線ID', 'replace_code' => 'line_id', 'is_active' => true]);

    $contract = app(ContractService::class)->createDraft($bpUser, $site, [$item->id]);
    $app = app(ContractService::class)->submitPriceApproval($bpUser, $contract);
    app(ContractService::class)->decidePriceApproval($parentUser, $app, true);

    $line = $contract->fresh()->items->first();
    app(ContractService::class)->upsertItemData($bpUser, $line, [
        ['name' => '回線ID', 'replace_code' => 'line_id', 'value' => 'ABC-001'],
    ]);

    $rendered = app(DocumentRenderService::class)->renderPdf($line->fresh(), $template);

    expect($rendered['html'])->toContain('Render Child')
        ->and($rendered['html'])->toContain('Render Customer')
        ->and($rendered['html'])->toContain('本社拠点')
        ->and($rendered['html'])->toContain('月額プラン')
        ->and($rendered['html'])->toContain('2000')
        ->and($rendered['html'])->toContain('2200')
        ->and($rendered['html'])->toContain('200')
        ->and($rendered['html'])->toContain('ABC-001')
        ->and($rendered['html'])->toContain('未知: ')
        ->and($rendered['html'])->not->toContain('{{')
        ->and(str_starts_with($rendered['binary'], '%PDF'))->toBeTrue();

    app(ContractService::class)->activate($bpUser, $contract->fresh());
    $doc = $line->fresh()->documents->first();
    expect($doc)->not->toBeNull()
        ->and($doc->mime_type)->toBe('application/pdf')
        ->and(Storage::disk('local')->get($doc->file_path))->toStartWith('%PDF');

    // overwrite regenerate
    app(ContractService::class)->upsertItemData($bpUser, $line, [
        ['name' => '回線ID', 'replace_code' => 'line_id', 'value' => 'XYZ-999'],
    ]);
    app(ContractService::class)->regenerateDocuments($bpUser, $contract->fresh());
    $again = app(DocumentRenderService::class)->templateToHtml(
        $template,
        app(DocumentRenderService::class)->buildReplaceMap($line->fresh())
    );
    expect($again)->toContain('XYZ-999')
        ->and($line->fresh()->documents)->toHaveCount(1);
});
