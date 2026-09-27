<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Billing\Enums\BillingBatchDayMode;
use App\Domains\Billing\Enums\BillingBatchRunTrigger;
use App\Domains\Billing\Services\BillingScheduleService;
use App\Domains\Billing\Services\MonthlyBillingService;
use App\Domains\Iam\Services\AuditLogger;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\BillingBatchRun;
use App\Models\BusinessPartner;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class BillingBatchSettingsController extends Controller
{
    public function edit(Request $request, AuthorizationService $authorization, BillingScheduleService $schedule): View
    {
        $authorization->authorize($request->user('admin'), 'invoice.manage');

        $recentRuns = BillingBatchRun::query()
            ->with(['actor', 'errors.contract'])
            ->latest('id')
            ->limit(30)
            ->get();

        return view('admin.billing.batch-settings', [
            'schedule' => $schedule->getScheduleWithNextRun(),
            'dayModes' => BillingBatchDayMode::cases(),
            'recentRuns' => $recentRuns,
            'businessPartners' => BusinessPartner::query()->orderBy('depth')->orderBy('code')->get(['id', 'code', 'name', 'depth']),
            'customers' => Customer::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, AuthorizationService $authorization, BillingScheduleService $schedule, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user('admin');
        $authorization->authorize($actor, 'invoice.manage');

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'day_mode' => ['required', Rule::enum(BillingBatchDayMode::class)],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        try {
            $updated = $schedule->updateSchedule([
                'enabled' => $request->boolean('enabled'),
                'day_mode' => $validated['day_mode'],
                'day_of_month' => $validated['day_of_month'] ?? null,
                'time' => $validated['time'],
                'timezone' => 'Asia/Tokyo',
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['schedule' => $exception->getMessage()]);
        }

        $audit->log('billing', 'billing_batch.schedule.update', 'success', $actor, meta: $updated);

        return back()->with('status', '自動請求スケジュールを更新しました。');
    }

    public function run(Request $request, AuthorizationService $authorization, MonthlyBillingService $monthly, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user('admin');
        $authorization->authorize($actor, 'invoice.manage');

        $validated = $request->validate([
            'billing_year_month' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $stats = $monthly->run(
            $validated['billing_year_month'],
            $actor,
            BillingBatchRunTrigger::Manual,
        );
        $audit->log(
            'billing',
            'billing_batch.run',
            $stats['errors'] > 0 ? 'failure' : 'success',
            $actor,
            meta: $stats,
        );

        $statusLabel = match ($stats['status']) {
            'success' => '成功',
            'partial' => '一部失敗',
            'failed' => '失敗',
            default => $stats['status'],
        };

        return back()->with('status', sprintf(
            '手動生成を実行しました（結果: %s / 請求月 %s / 請求 %d / キックバック %d / スキップ %d / エラー %d）。',
            $statusLabel,
            $stats['billing_year_month'],
            $stats['invoices'],
            $stats['kickbacks'],
            $stats['skipped'],
            $stats['errors'],
        ));
    }

    public function runRange(Request $request, AuthorizationService $authorization, MonthlyBillingService $monthly, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user('admin');
        $authorization->authorize($actor, 'invoice.manage');

        $validated = $request->validate([
            'from_year_month' => ['required', 'string', 'regex:/^\d{6}$/'],
            'to_year_month' => ['required', 'string', 'regex:/^\d{6}$/'],
            'scope_type' => ['required', 'in:bp_tree,bp,customer'],
            'business_partner_id' => ['required_if:scope_type,bp_tree,bp', 'nullable', 'integer', 'exists:business_partners,id'],
            'customer_id' => ['required_if:scope_type,customer', 'nullable', 'integer', 'exists:customers,id'],
        ]);

        $scope = match ($validated['scope_type']) {
            'customer' => ['type' => 'customer', 'id' => (int) $validated['customer_id']],
            default => ['type' => $validated['scope_type'], 'id' => (int) $validated['business_partner_id']],
        };

        try {
            $stats = $monthly->runRange(
                $validated['from_year_month'],
                $validated['to_year_month'],
                $scope,
                $actor,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['scope_type' => $exception->getMessage()]);
        }

        $audit->log(
            'billing',
            'billing_batch.run_range',
            $stats['errors'] > 0 ? 'failure' : 'success',
            $actor,
            meta: [
                'from' => $stats['from'],
                'to' => $stats['to'],
                'scope' => $stats['scope'],
                'months' => count($stats['months']),
                'invoices' => $stats['invoices'],
                'kickbacks' => $stats['kickbacks'],
                'skipped' => $stats['skipped'],
                'errors' => $stats['errors'],
            ],
        );

        $monthSummaries = collect($stats['months'])
            ->map(fn (array $month) => sprintf(
                '%s(請求%d/skip%d/err%d)',
                $month['billing_year_month'],
                $month['invoices'],
                $month['skipped'],
                $month['errors'],
            ))
            ->implode('、');

        $scopeLabel = match ($stats['scope']['type']) {
            'bp_tree' => sprintf('BP（配下含む） %s（%s）', $stats['scope']['code'], $stats['scope']['name']),
            'bp' => sprintf('BP単体 %s（%s）', $stats['scope']['code'], $stats['scope']['name']),
            default => sprintf('CN %s（%s）', $stats['scope']['code'], $stats['scope']['name']),
        };

        return back()->with('status', sprintf(
            '過去月の請求を生成しました（対象: %s / %s〜%s / 合計 請求 %d / スキップ %d / エラー %d）。キックバックは含めません。詳細: %s',
            $scopeLabel,
            $stats['from'],
            $stats['to'],
            $stats['invoices'],
            $stats['skipped'],
            $stats['errors'],
            $monthSummaries,
        ));
    }

    public function showRun(Request $request, BillingBatchRun $run, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'invoice.view');

        $run->load(['actor', 'errors.contract']);

        $invoices = $run->invoices()
            ->with(['contract', 'customer'])
            ->latest('id')
            ->paginate(50, ['*'], 'invoices_page');

        $kickbacks = $run->kickbackInvoices()
            ->with(['contract', 'fromBp', 'toBp'])
            ->latest('id')
            ->paginate(50, ['*'], 'kickbacks_page');

        return view('admin.billing.batch-run', [
            'run' => $run,
            'invoices' => $invoices,
            'kickbacks' => $kickbacks,
        ]);
    }
}
