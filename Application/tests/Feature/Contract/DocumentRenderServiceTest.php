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

    app(ContractService::class)->activate($bpUser, $contract->fresh(), now()->format('Ym'));
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

it('keeps issued pdf after template delete and regenerates from new templates', function () {
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $parent = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Doc Parent');
    $child = $hierarchy->createChild($parent, $seq->next(PartnerCodePrefix::Bpn), 'Doc Child');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $child->id,
        'name' => 'Doc Customer',
        'entity_type' => 'corporate',
        'two_factor_mode' => TwoFactorMode::Optional,
        'is_active' => true,
    ]);
    $site = Site::query()->create([
        'customer_id' => $customer->id,
        'name' => '本社',
        'billing_name' => '請求先',
        'billing_address' => '東京',
        'is_primary' => true,
        'is_active' => true,
    ]);

    $admin = User::factory()->admin()->create(['login_id' => 'DOCDELADMIN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);
    $bpUser = User::factory()->bp($child)->create(['login_id' => 'DOCDELBP', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $child->id);
    $parentUser = User::factory()->bp($parent)->create(['login_id' => 'DOCDELPARENT', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($parentUser, 'bp_owner', RoleScope::Bp, $parent->id);

    $item = app(CatalogPricingService::class)->createItem($admin, [
        'name' => '回線',
        'billing_type' => BillingType::Running->value,
        'partition_price' => 1000,
        'user_price' => 2000,
    ]);

    $oldTemplate = app(ContractService::class)->addItemDocument(
        $admin,
        $item,
        '旧案内',
        UploadedFile::fake()->createWithContent('old.html', '<html><body>OLD {{c_name}}</body></html>'),
    );

    $contract = app(ContractService::class)->createDraft($bpUser, $site, [$item->id]);
    $app = app(ContractService::class)->submitPriceApproval($bpUser, $contract);
    app(ContractService::class)->decidePriceApproval($parentUser, $app, true);
    app(ContractService::class)->activate($bpUser, $contract->fresh(), now()->format('Ym'));

    $line = $contract->fresh()->items->first();
    $issued = $line->documents->first();
    expect($issued)->not->toBeNull()
        ->and($issued->item_document_id)->toBe($oldTemplate->id)
        ->and(Storage::disk('local')->exists($issued->file_path))->toBeTrue();
    $issuedPath = $issued->file_path;
    $issuedBinary = Storage::disk('local')->get($issuedPath);

    app(ContractService::class)->deleteItemDocument($admin, $oldTemplate->fresh());
    expect(\App\Models\ItemDocument::query()->find($oldTemplate->id))->toBeNull();

    $kept = $line->fresh()->documents->first();
    expect($kept->item_document_id)->toBeNull()
        ->and($kept->file_path)->toBe($issuedPath)
        ->and(Storage::disk('local')->get($issuedPath))->toBe($issuedBinary);

    $newTemplate = app(ContractService::class)->addItemDocument(
        $admin,
        $item,
        '新案内',
        UploadedFile::fake()->createWithContent('new.html', '<html><body>NEW {{c_name}}</body></html>'),
    );

    app(ContractService::class)->regenerateDocuments($bpUser, $contract->fresh());
    $docs = $line->fresh()->documents;
    expect($docs)->toHaveCount(2)
        ->and($docs->firstWhere('item_document_id', null)?->file_path)->toBe($issuedPath)
        ->and($docs->firstWhere('item_document_id', $newTemplate->id))->not->toBeNull()
        ->and(Storage::disk('local')->get($issuedPath))->toBe($issuedBinary);
});

it('preserves excel merge alignment font size and borders only when set', function () {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'タイトル');
    $sheet->mergeCells('A1:C1');
    $sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    $sheet->setCellValue('A2', '枠あり');
    $sheet->getStyle('A2')->getFont()->setSize(9);
    $sheet->getStyle('A2')->getBorders()->getOutline()->setBorderStyle(
        \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
    );

    $sheet->setCellValue('B2', '{{c_name}}');
    $sheet->getStyle('B2')->getFont()->setSize(9);

    // 結合セルで縦線が右端セルにだけある典型パターン
    $sheet->setCellValue('A3', '結合枠');
    $sheet->mergeCells('A3:C3');
    $sheet->getStyle('A3')->getBorders()->getLeft()->setBorderStyle(
        \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
    );
    $sheet->getStyle('A3')->getBorders()->getTop()->setBorderStyle(
        \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
    );
    $sheet->getStyle('A3')->getBorders()->getBottom()->setBorderStyle(
        \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
    );
    $sheet->getStyle('C3')->getBorders()->getRight()->setBorderStyle(
        \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
    );
    $sheet->getStyle('C3')->getBorders()->getTop()->setBorderStyle(
        \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
    );
    $sheet->getStyle('C3')->getBorders()->getBottom()->setBorderStyle(
        \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
    );

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $path = $tmp.'.xlsx';
    @unlink($tmp);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    Storage::disk('local')->put('item-documents/excel-style.xlsx', file_get_contents($path));
    @unlink($path);

    $template = new \App\Models\ItemDocument([
        'file_path' => 'item-documents/excel-style.xlsx',
        'original_name' => 'style.xlsx',
        'title' => 'style',
    ]);

    $html = app(DocumentRenderService::class)->templateToHtml($template, ['c_name' => '顧客A']);

    expect($html)
        ->toContain('colspan="3"')
        ->toContain('font-size:18pt')
        ->toContain('text-align:center')
        ->toContain('font-size:9pt')
        ->toContain('border-top:1pt solid')
        ->toContain('顧客A')
        ->not->toContain('border="1"');

    expect(preg_match('/style="([^"]*)"[^>]*>タイトル/u', $html, $m))->toBe(1);
    expect($m[1])->toContain('font-size:18pt')
        ->and($m[1])->not->toContain('border-');

    expect(preg_match('/style="([^"]*)"[^>]*>結合枠/u', $html, $merged))->toBe(1);
    expect($merged[1])
        ->toContain('border-left:')
        ->toContain('border-right:')
        ->toContain('border-top:')
        ->toContain('border-bottom:');
});
