<?php

namespace App\Console\Commands;

use App\Domains\Billing\Services\MonthlyBillingService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RunMonthlyBillingCommand extends Command
{
    protected $signature = 'billing:run-monthly
                            {--month= : 請求月 YYYYMM（単月・全契約）}
                            {--from= : 開始月 YYYYMM（範囲生成）}
                            {--to= : 終了月 YYYYMM（範囲生成）}
                            {--bp-tree= : 範囲生成の対象 BP コード（配下含む）}
                            {--bp= : 範囲生成の対象 BP コード（単体）}
                            {--customer= : 範囲生成の対象カスタマーコード}';

    protected $description = '月次のカスタマー請求・キックバックを生成する（単月または BP/CN 限定の範囲）';

    public function handle(MonthlyBillingService $monthly): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $bpTree = $this->option('bp-tree');
        $bp = $this->option('bp');
        $customer = $this->option('customer');

        if ($from !== null || $to !== null || $bpTree !== null || $bp !== null || $customer !== null) {
            if ($from === null || $to === null) {
                $this->error('範囲生成では --from と --to を指定してください。');

                return self::FAILURE;
            }

            $scopeOptions = array_filter([
                'bp_tree' => $bpTree,
                'bp' => $bp,
                'customer' => $customer,
            ], fn ($value) => $value !== null);

            if (count($scopeOptions) !== 1) {
                $this->error('範囲生成では --bp-tree / --bp / --customer のいずれかを1つ指定してください。');

                return self::FAILURE;
            }

            $type = array_key_first($scopeOptions);
            $scope = [
                'type' => $type,
                'code' => $scopeOptions[$type],
            ];

            try {
                $stats = $monthly->runRange($from, $to, $scope);
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $typeLabel = match ($stats['scope']['type']) {
                'bp_tree' => 'BP（配下含む）',
                'bp' => 'BP単体',
                default => 'CN',
            };
            $this->info(sprintf(
                '対象: %s %s（%s）',
                $typeLabel,
                $stats['scope']['code'],
                $stats['scope']['name'],
            ));

            foreach ($stats['months'] as $month) {
                $this->info(sprintf(
                    '請求月 %s: invoices=%d kickbacks=%d skipped=%d errors=%d status=%s',
                    $month['billing_year_month'],
                    $month['invoices'],
                    $month['kickbacks'],
                    $month['skipped'],
                    $month['errors'],
                    $month['status'],
                ));
            }

            $this->info(sprintf(
                '合計 %s〜%s: invoices=%d kickbacks=%d skipped=%d errors=%d',
                $stats['from'],
                $stats['to'],
                $stats['invoices'],
                $stats['kickbacks'],
                $stats['skipped'],
                $stats['errors'],
            ));

            return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
        }

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
