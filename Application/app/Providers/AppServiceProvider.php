<?php

namespace App\Providers;

use App\Models\BusinessPartner;
use App\Models\Contract;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FA\Google2FA;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Google2FA::class, fn () => new Google2FA);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        Relation::morphMap([
            'contract' => Contract::class,
            'bp' => BusinessPartner::class,
            'customer' => Customer::class,
        ]);
    }
}
