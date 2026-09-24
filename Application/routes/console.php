<?php

use App\Domains\Billing\Services\MonthlyBillingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    app(MonthlyBillingService::class)->runIfScheduled();
})->everyMinute()->name('billing-monthly-scheduler');
