<?php

namespace App\Console\Commands;

use App\Domains\Billing\Services\MonthlyBillingService;
use Illuminate\Console\Command;

class RunMonthlyBillingCommand extends Command
{
    protected $signature = 'billing:run-monthly {--month= : 請求月 YYYYMM}';

    protected $description = '月次のカスタマー請求・キックバックを生成する';

    public function handle(MonthlyBillingService $monthly): int
    {
        $month = $this->option('month');
        $stats = $monthly->run($month ?: null);

        $this->info(sprintf(
            '請求月 %s: invoices=%d kickbacks=%d skipped=%d errors=%d',
            $stats['billing_year_month'],
            $stats['invoices'],
            $stats['kickbacks'],
            $stats['skipped'],
            $stats['errors'],
        ));

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
