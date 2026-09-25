<?php

use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\ApplicationController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Auth\LoginController as AdminLoginController;
use App\Http\Controllers\Admin\Auth\PasswordController as AdminPasswordController;
use App\Http\Controllers\Admin\Auth\TwoFactorController as AdminTwoFactorController;
use App\Http\Controllers\Admin\BpTwoFactorModeController;
use App\Http\Controllers\Admin\BusinessPartnerController;
use App\Http\Controllers\Admin\ContractController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerPriceController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DataFieldNameController;
use App\Http\Controllers\Admin\InquiryController;
use App\Http\Controllers\Admin\BillingBatchSettingsController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\KickbackInvoiceController;
use App\Http\Controllers\Admin\ItemController;
use App\Http\Controllers\Admin\ItemDocumentController;
use App\Http\Controllers\Admin\ItemTypeController;
use App\Http\Controllers\Admin\SiteController;
use App\Http\Controllers\Admin\UserPrivilegeController;
use App\Http\Controllers\Admin\WholesalePriceController;
use App\Http\Controllers\Bp\AnnouncementController as BpAnnouncementController;
use App\Http\Controllers\Bp\ApplicationController as BpApplicationController;
use App\Http\Controllers\Bp\Auth\LoginController as BpLoginController;
use App\Http\Controllers\Bp\Auth\PasswordController as BpPasswordController;
use App\Http\Controllers\Bp\Auth\TwoFactorController as BpTwoFactorController;
use App\Http\Controllers\Bp\BusinessPartnerController as BpBusinessPartnerController;
use App\Http\Controllers\Bp\ContractController as BpContractController;
use App\Http\Controllers\Bp\CustomerController as BpCustomerController;
use App\Http\Controllers\Bp\CustomerPriceController as BpCustomerPriceController;
use App\Http\Controllers\Bp\DashboardController as BpDashboardController;
use App\Http\Controllers\Bp\InquiryController as BpInquiryController;
use App\Http\Controllers\Bp\InvoiceController as BpInvoiceController;
use App\Http\Controllers\Bp\KickbackInvoiceController as BpKickbackInvoiceController;
use App\Http\Controllers\Bp\ItemController as BpItemController;
use App\Http\Controllers\Bp\ItemDocumentController as BpItemDocumentController;
use App\Http\Controllers\Bp\ItemTypeController as BpItemTypeController;
use App\Http\Controllers\Bp\SiteController as BpSiteController;
use App\Http\Controllers\Bp\UserController as BpUserController;
use App\Http\Controllers\Bp\WholesalePriceController as BpWholesalePriceController;
use App\Http\Controllers\Customer\AnnouncementController as CustomerAnnouncementController;
use App\Http\Controllers\Customer\Auth\LoginController as CustomerLoginController;
use App\Http\Controllers\Customer\Auth\PasswordController as CustomerPasswordController;
use App\Http\Controllers\Customer\Auth\TwoFactorController as CustomerTwoFactorController;
use App\Http\Controllers\Customer\ContractController as CustomerContractController;
use App\Http\Controllers\Customer\DashboardController as CustomerDashboardController;
use App\Http\Controllers\Customer\InquiryController as CustomerInquiryController;
use App\Http\Controllers\Customer\InvoiceController as CustomerInvoiceController;
use App\Http\Controllers\Customer\UserController as CustomerUserController;
use App\Http\Controllers\PostalLookupController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/postal-lookup', PostalLookupController::class)->name('postal.lookup');

foreach (
    [
        'admin' => [
            'login' => AdminLoginController::class,
            'twoFactor' => AdminTwoFactorController::class,
            'password' => AdminPasswordController::class,
            'dashboard' => AdminDashboardController::class,
        ],
        'bp' => [
            'login' => BpLoginController::class,
            'twoFactor' => BpTwoFactorController::class,
            'password' => BpPasswordController::class,
            'dashboard' => BpDashboardController::class,
        ],
        'customer' => [
            'login' => CustomerLoginController::class,
            'twoFactor' => CustomerTwoFactorController::class,
            'password' => CustomerPasswordController::class,
            'dashboard' => CustomerDashboardController::class,
        ],
    ] as $guard => $controllers
) {
    Route::prefix($guard)->name("{$guard}.")->group(function () use ($guard, $controllers) {
        if (in_array($guard, ['admin', 'bp'], true)) {
            Route::get('/', function () use ($guard) {
                if (Auth::guard($guard)->check()) {
                    return redirect()->route("{$guard}.dashboard");
                }

                return redirect()->route("{$guard}.login");
            })->name('home');
        }

        Route::middleware("guest.guard:{$guard}")->group(function () use ($controllers) {
            Route::get('login', [$controllers['login'], 'create'])->name('login');
            Route::post('login', [$controllers['login'], 'store'])->name('login.store');
        });

        Route::get('two-factor/challenge', [$controllers['twoFactor'], 'showChallenge'])->name('two-factor.challenge');
        Route::post('two-factor/challenge', [$controllers['twoFactor'], 'storeChallenge'])->name('two-factor.challenge.store');
        Route::get('two-factor/setup', [$controllers['twoFactor'], 'showSetup'])->name('two-factor.setup');
        Route::post('two-factor/setup', [$controllers['twoFactor'], 'storeSetup'])->name('two-factor.setup.store');

        Route::middleware("auth.guard:{$guard}")->group(function () use ($guard, $controllers) {
            Route::get('password/change', [$controllers['password'], 'editPassword'])->name('password.edit');
            Route::post('password/change', [$controllers['password'], 'updatePassword'])->name('password.update');
            Route::post('logout', [$controllers['login'], 'destroy'])->name('logout');

            Route::middleware("password.changed:{$guard}")->group(function () use ($guard, $controllers) {
                Route::get('dashboard', $controllers['dashboard'])->name('dashboard');
                Route::get('two-factor/settings', [$controllers['twoFactor'], 'showSettings'])->name('two-factor.settings');
                Route::post('two-factor/settings', [$controllers['twoFactor'], 'enableFromSettings'])->name('two-factor.settings.enable');
                Route::post('two-factor/disable', [$controllers['twoFactor'], 'disable'])->name('two-factor.disable');

                if ($guard === 'customer') {
                    Route::get('contracts', [CustomerContractController::class, 'index'])->name('contracts.index');
                    Route::get('contracts/{contract}', [CustomerContractController::class, 'show'])->name('contracts.show');
                    Route::post('contracts/{contract}/messages', [CustomerContractController::class, 'storeMessage'])->name('contracts.messages.store');
                    Route::get('contract-items/{contractItem}/documents/{document}/download', [CustomerContractController::class, 'downloadDocument'])->name('contracts.items.documents.download');

                    Route::get('tickets/received', [CustomerInquiryController::class, 'received'])->name('tickets.received');
                    Route::get('tickets/issued', [CustomerInquiryController::class, 'issued'])->name('tickets.issued');
                    Route::get('tickets/create', [CustomerInquiryController::class, 'create'])->name('tickets.create');
                    Route::post('tickets', [CustomerInquiryController::class, 'store'])->name('tickets.store');
                    Route::get('tickets/{inquiry}', [CustomerInquiryController::class, 'show'])->name('tickets.show');
                    Route::post('tickets/{inquiry}/withdraw', [CustomerInquiryController::class, 'withdraw'])->name('tickets.withdraw');
                    Route::get('tickets/{inquiry}/attachments/{attachment}', [CustomerInquiryController::class, 'downloadAttachment'])->name('tickets.attachments.download');

                    Route::get('invoices', [CustomerInvoiceController::class, 'index'])->name('invoices.index');
                    Route::get('invoices/{invoice}', [CustomerInvoiceController::class, 'show'])->name('invoices.show');

                    Route::get('announcements', [CustomerAnnouncementController::class, 'index'])->name('announcements.index');
                    Route::get('announcements/{announcement}', [CustomerAnnouncementController::class, 'show'])->name('announcements.show');

                    Route::get('users', [CustomerUserController::class, 'index'])->name('users.index');
                    Route::get('users/create', [CustomerUserController::class, 'create'])->name('users.create');
                    Route::post('users', [CustomerUserController::class, 'store'])->name('users.store');
                    Route::get('users/{user}/edit', [CustomerUserController::class, 'edit'])->name('users.edit');
                    Route::put('users/{user}', [CustomerUserController::class, 'update'])->name('users.update');
                    Route::delete('users/{user}', [CustomerUserController::class, 'destroy'])->name('users.destroy');
                }

                if ($guard === 'admin') {
                    Route::get('users', [UserPrivilegeController::class, 'index'])->name('users.index');
                    Route::get('users/create', [UserPrivilegeController::class, 'create'])->name('users.create');
                    Route::post('users', [UserPrivilegeController::class, 'store'])->name('users.store');
                    Route::get('users/{user}/edit', [UserPrivilegeController::class, 'edit'])->name('users.edit');
                    Route::put('users/{user}', [UserPrivilegeController::class, 'update'])->name('users.update');
                    Route::delete('users/{user}', [UserPrivilegeController::class, 'destroy'])->name('users.destroy');
                    Route::post('users/{user}/force-password', [UserPrivilegeController::class, 'forcePassword'])->name('users.force-password');
                    Route::post('users/{user}/reset-2fa', [UserPrivilegeController::class, 'forceDisableTwoFactor'])->name('users.reset-2fa');
                    Route::post('users/{user}/clear-reset-2fa', [UserPrivilegeController::class, 'clearForceDisableTwoFactor'])->name('users.clear-reset-2fa');

                    Route::get('business-partners', [BusinessPartnerController::class, 'index'])->name('business-partners.index');
                    Route::get('business-partners/create', [BusinessPartnerController::class, 'create'])->name('business-partners.create');
                    Route::post('business-partners', [BusinessPartnerController::class, 'store'])->name('business-partners.store');
                    Route::get('business-partners/{businessPartner}', [BusinessPartnerController::class, 'show'])->name('business-partners.show');
                    Route::get('business-partners/{businessPartner}/edit', [BusinessPartnerController::class, 'edit'])->name('business-partners.edit');
                    Route::put('business-partners/{businessPartner}', [BusinessPartnerController::class, 'update'])->name('business-partners.update');
                    Route::put('business-partners/{businessPartner}/move', [BusinessPartnerController::class, 'move'])->name('business-partners.move');
                    Route::delete('business-partners/{businessPartner}', [BusinessPartnerController::class, 'destroy'])->name('business-partners.destroy');

                    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
                    Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
                    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
                    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
                    Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
                    Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
                    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

                    Route::get('customers/{customer}/sites/create', [SiteController::class, 'create'])->name('sites.create');
                    Route::post('customers/{customer}/sites', [SiteController::class, 'store'])->name('sites.store');
                    Route::get('sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');
                    Route::put('sites/{site}', [SiteController::class, 'update'])->name('sites.update');
                    Route::delete('sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');

                    Route::get('items', [ItemController::class, 'index'])->name('items.index');
                    Route::get('items/create', [ItemController::class, 'create'])->name('items.create');
                    Route::post('items', [ItemController::class, 'store'])->name('items.store');
                    Route::get('items/{item}', [ItemController::class, 'show'])->name('items.show');
                    Route::get('items/{item}/edit', [ItemController::class, 'edit'])->name('items.edit');
                    Route::put('items/{item}', [ItemController::class, 'update'])->name('items.update');
                    Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');

                    Route::get('prices/wholesale', [WholesalePriceController::class, 'index'])->name('prices.wholesale.index');
                    Route::post('prices/wholesale', [WholesalePriceController::class, 'store'])->name('prices.wholesale.store');
                    Route::get('customers/{customer}/prices', [CustomerPriceController::class, 'edit'])->name('customers.prices.edit');
                    Route::put('customers/{customer}/prices', [CustomerPriceController::class, 'update'])->name('customers.prices.update');

                    Route::post('items/{item}/documents', [ItemDocumentController::class, 'store'])->name('items.documents.store');
                    Route::delete('item-documents/{itemDocument}', [ItemDocumentController::class, 'destroy'])->name('items.documents.destroy');
                    Route::get('item-documents/{itemDocument}/download', [ItemDocumentController::class, 'download'])->name('items.documents.download');

                    Route::get('data-field-names', [DataFieldNameController::class, 'index'])->name('data-field-names.index');
                    Route::post('data-field-names', [DataFieldNameController::class, 'store'])->name('data-field-names.store');
                    Route::put('data-field-names/{dataFieldName}', [DataFieldNameController::class, 'update'])->name('data-field-names.update');

                    Route::get('item-types', [ItemTypeController::class, 'index'])->name('item-types.index');
                    Route::post('item-types', [ItemTypeController::class, 'store'])->name('item-types.store');
                    Route::put('item-types/{itemType}', [ItemTypeController::class, 'update'])->name('item-types.update');

                    Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
                    Route::get('contracts/create', [ContractController::class, 'create'])->name('contracts.create');
                    Route::post('contracts', [ContractController::class, 'store'])->name('contracts.store');
                    Route::get('contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
                    Route::delete('contracts/{contract}', [ContractController::class, 'destroy'])->name('contracts.destroy');
                    Route::put('contracts/{contract}/prices', [ContractController::class, 'updatePrices'])->name('contracts.prices');
                    Route::put('contracts/{contract}/billing', [ContractController::class, 'updateBilling'])->name('contracts.billing');
                    Route::post('contracts/{contract}/submit-approval', [ContractController::class, 'submitApproval'])->name('contracts.submit-approval');
                    Route::post('contracts/{contract}/activate', [ContractController::class, 'activate'])->name('contracts.activate');
                    Route::post('contracts/{contract}/revert-service', [ContractController::class, 'revertService'])->name('contracts.revert-service');
                    Route::post('contracts/{contract}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');
                    Route::get('contracts/{contract}/cancellation-suggestion', [ContractController::class, 'cancellationSuggestion'])->name('contracts.cancellation-suggestion');
                    Route::post('contracts/{contract}/messages', [ContractController::class, 'storeMessage'])->name('contracts.messages.store');
                    Route::post('contracts/{contract}/regenerate-documents', [ContractController::class, 'regenerateDocuments'])->name('contracts.regenerate-documents');
                    Route::put('contracts/{contract}/data', [ContractController::class, 'upsertContractData'])->name('contracts.data');
                    Route::put('contract-items/{contractItem}/data', [ContractController::class, 'upsertData'])->name('contracts.items.data');
                    Route::get('contract-items/{contractItem}/documents/{document}/download', [ContractController::class, 'downloadDocument'])->name('contracts.items.documents.download');
                    Route::delete('contract-items/{contractItem}/documents/{document}', [ContractController::class, 'destroyDocument'])->name('contracts.items.documents.destroy');

                    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
                    Route::post('applications/{application}/decide', [ApplicationController::class, 'decide'])->name('applications.decide');

                    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
                    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
                    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
                    Route::put('invoices/{invoice}/paid-amount', [InvoiceController::class, 'updatePaidAmount'])->name('invoices.paid-amount');
                    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
                    Route::get('kickbacks', [KickbackInvoiceController::class, 'index'])->name('kickbacks.index');
                    Route::get('kickbacks/{kickback}', [KickbackInvoiceController::class, 'show'])->name('kickbacks.show');
                    Route::post('kickbacks/{kickback}/mark-paid', [KickbackInvoiceController::class, 'markPaid'])->name('kickbacks.mark-paid');
                    Route::put('kickbacks/{kickback}/paid-amount', [KickbackInvoiceController::class, 'updatePaidAmount'])->name('kickbacks.paid-amount');
                    Route::post('kickbacks/{kickback}/withdraw', [KickbackInvoiceController::class, 'withdraw'])->name('kickbacks.withdraw');
                    Route::put('kickbacks/{kickback}/amounts', [KickbackInvoiceController::class, 'adjustAmounts'])->name('kickbacks.amounts');
                    Route::post('kickbacks/{kickback}/regenerate', [KickbackInvoiceController::class, 'regenerate'])->name('kickbacks.regenerate');
                    Route::get('billing-batch', [BillingBatchSettingsController::class, 'edit'])->name('billing-batch.edit');
                    Route::put('billing-batch', [BillingBatchSettingsController::class, 'update'])->name('billing-batch.update');
                    Route::post('billing-batch/run', [BillingBatchSettingsController::class, 'run'])->name('billing-batch.run');
                    Route::get('billing-batch/runs/{run}', [BillingBatchSettingsController::class, 'showRun'])->name('billing-batch.runs.show');

                    Route::get('tickets/received', [InquiryController::class, 'received'])->name('tickets.received');
                    Route::get('tickets/{inquiry}/inspect', [InquiryController::class, 'inspect'])->name('tickets.inspect');
                    Route::get('tickets/{inquiry}', [InquiryController::class, 'show'])->name('tickets.show');
                    Route::post('tickets/{inquiry}/start-progress', [InquiryController::class, 'startProgress'])->name('tickets.start-progress');
                    Route::post('tickets/{inquiry}/close', [InquiryController::class, 'close'])->name('tickets.close');
                    Route::post('tickets/{inquiry}/reopen', [InquiryController::class, 'reopen'])->name('tickets.reopen');
                    Route::delete('tickets/{inquiry}', [InquiryController::class, 'destroy'])->name('tickets.destroy');
                    Route::get('tickets/{inquiry}/attachments/{attachment}', [InquiryController::class, 'downloadAttachment'])->name('tickets.attachments.download');

                    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
                    Route::get('announcements/create', [AnnouncementController::class, 'create'])->name('announcements.create');
                    Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
                    Route::get('announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])->name('announcements.edit');
                    Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
                    Route::get('announcements/{announcement}', [AnnouncementController::class, 'show'])->name('announcements.show');
                    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

                    Route::get('bp-two-factor', [BpTwoFactorModeController::class, 'index'])->name('bp-two-factor.index');
                    Route::put('bp-two-factor/customers/{customer}', [BpTwoFactorModeController::class, 'updateCustomer'])->name('bp-two-factor.customers.update');
                    Route::put('bp-two-factor/{businessPartner}', [BpTwoFactorModeController::class, 'update'])->name('bp-two-factor.update');

                    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
                    Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
                }

                if ($guard === 'bp') {
                    Route::get('users', [BpUserController::class, 'index'])->name('users.index');
                    Route::get('users/create', [BpUserController::class, 'create'])->name('users.create');
                    Route::post('users', [BpUserController::class, 'store'])->name('users.store');
                    Route::get('users/{user}/edit', [BpUserController::class, 'edit'])->name('users.edit');
                    Route::put('users/{user}', [BpUserController::class, 'update'])->name('users.update');
                    Route::delete('users/{user}', [BpUserController::class, 'destroy'])->name('users.destroy');

                    Route::get('business-partners', [BpBusinessPartnerController::class, 'index'])->name('business-partners.index');
                    Route::get('business-partners/create', [BpBusinessPartnerController::class, 'create'])->name('business-partners.create');
                    Route::post('business-partners', [BpBusinessPartnerController::class, 'store'])->name('business-partners.store');
                    Route::get('business-partners/{businessPartner}', [BpBusinessPartnerController::class, 'show'])->name('business-partners.show');
                    Route::get('business-partners/{businessPartner}/edit', [BpBusinessPartnerController::class, 'edit'])->name('business-partners.edit');
                    Route::put('business-partners/{businessPartner}', [BpBusinessPartnerController::class, 'update'])->name('business-partners.update');
                    Route::put('business-partners/{businessPartner}/move', [BpBusinessPartnerController::class, 'move'])->name('business-partners.move');
                    Route::delete('business-partners/{businessPartner}', [BpBusinessPartnerController::class, 'destroy'])->name('business-partners.destroy');

                    Route::get('customers', [BpCustomerController::class, 'index'])->name('customers.index');
                    Route::get('customers/create', [BpCustomerController::class, 'create'])->name('customers.create');
                    Route::post('customers', [BpCustomerController::class, 'store'])->name('customers.store');
                    Route::get('customers/{customer}', [BpCustomerController::class, 'show'])->name('customers.show');
                    Route::get('customers/{customer}/edit', [BpCustomerController::class, 'edit'])->name('customers.edit');
                    Route::put('customers/{customer}', [BpCustomerController::class, 'update'])->name('customers.update');
                    Route::delete('customers/{customer}', [BpCustomerController::class, 'destroy'])->name('customers.destroy');

                    Route::get('customers/{customer}/sites/create', [BpSiteController::class, 'create'])->name('sites.create');
                    Route::post('customers/{customer}/sites', [BpSiteController::class, 'store'])->name('sites.store');
                    Route::get('sites/{site}/edit', [BpSiteController::class, 'edit'])->name('sites.edit');
                    Route::put('sites/{site}', [BpSiteController::class, 'update'])->name('sites.update');
                    Route::delete('sites/{site}', [BpSiteController::class, 'destroy'])->name('sites.destroy');

                    Route::get('items', [BpItemController::class, 'index'])->name('items.index');
                    Route::get('items/create', [BpItemController::class, 'create'])->name('items.create');
                    Route::post('items', [BpItemController::class, 'store'])->name('items.store');
                    Route::get('items/{item}', [BpItemController::class, 'show'])->name('items.show');
                    Route::get('items/{item}/edit', [BpItemController::class, 'edit'])->name('items.edit');
                    Route::put('items/{item}', [BpItemController::class, 'update'])->name('items.update');
                    Route::delete('items/{item}', [BpItemController::class, 'destroy'])->name('items.destroy');
                    Route::post('items/{item}/wholesale', [BpItemController::class, 'storeWholesale'])->name('items.wholesale.store');
                    Route::post('items/{item}/documents', [BpItemDocumentController::class, 'store'])->name('items.documents.store');
                    Route::delete('item-documents/{itemDocument}', [BpItemDocumentController::class, 'destroy'])->name('items.documents.destroy');
                    Route::get('item-documents/{itemDocument}/download', [BpItemDocumentController::class, 'download'])->name('items.documents.download');
                    Route::get('prices/wholesale', [BpWholesalePriceController::class, 'index'])->name('prices.wholesale.index');
                    Route::post('prices/wholesale', [BpWholesalePriceController::class, 'store'])->name('prices.wholesale.store');
                    Route::get('customers/{customer}/prices', [BpCustomerPriceController::class, 'edit'])->name('customers.prices.edit');
                    Route::put('customers/{customer}/prices', [BpCustomerPriceController::class, 'update'])->name('customers.prices.update');

                    Route::get('item-types', [BpItemTypeController::class, 'index'])->name('item-types.index');
                    Route::post('item-types', [BpItemTypeController::class, 'store'])->name('item-types.store');
                    Route::put('item-types/{itemType}', [BpItemTypeController::class, 'update'])->name('item-types.update');

                    Route::get('contracts', [BpContractController::class, 'index'])->name('contracts.index');
                    Route::get('contracts/create', [BpContractController::class, 'create'])->name('contracts.create');
                    Route::post('contracts', [BpContractController::class, 'store'])->name('contracts.store');
                    Route::get('contracts/{contract}', [BpContractController::class, 'show'])->name('contracts.show');
                    Route::delete('contracts/{contract}', [BpContractController::class, 'destroy'])->name('contracts.destroy');
                    Route::put('contracts/{contract}/prices', [BpContractController::class, 'updatePrices'])->name('contracts.prices');
                    Route::put('contracts/{contract}/billing', [BpContractController::class, 'updateBilling'])->name('contracts.billing');
                    Route::post('contracts/{contract}/submit-approval', [BpContractController::class, 'submitApproval'])->name('contracts.submit-approval');
                    Route::post('contracts/{contract}/price-change', [BpContractController::class, 'submitPriceChange'])->name('contracts.price-change');
                    Route::post('contracts/{contract}/activate', [BpContractController::class, 'activate'])->name('contracts.activate');
                    Route::post('contracts/{contract}/revert-service', [BpContractController::class, 'revertService'])->name('contracts.revert-service');
                    Route::post('contracts/{contract}/cancel', [BpContractController::class, 'cancel'])->name('contracts.cancel');
                    Route::get('contracts/{contract}/cancellation-suggestion', [BpContractController::class, 'cancellationSuggestion'])->name('contracts.cancellation-suggestion');
                    Route::post('contracts/{contract}/messages', [BpContractController::class, 'storeMessage'])->name('contracts.messages.store');
                    Route::post('contracts/{contract}/regenerate-documents', [BpContractController::class, 'regenerateDocuments'])->name('contracts.regenerate-documents');
                    Route::put('contracts/{contract}/data', [BpContractController::class, 'upsertContractData'])->name('contracts.data');
                    Route::put('contract-items/{contractItem}/data', [BpContractController::class, 'upsertData'])->name('contracts.items.data');
                    Route::get('contract-items/{contractItem}/documents/{document}/download', [BpContractController::class, 'downloadDocument'])->name('contracts.items.documents.download');
                    Route::delete('contract-items/{contractItem}/documents/{document}', [BpContractController::class, 'destroyDocument'])->name('contracts.items.documents.destroy');

                    Route::get('applications', [BpApplicationController::class, 'index'])->name('applications.index');
                    Route::post('applications/{application}/decide', [BpApplicationController::class, 'decide'])->name('applications.decide');

                    Route::get('invoices', [BpInvoiceController::class, 'index'])->name('invoices.index');
                    Route::get('invoices/{invoice}', [BpInvoiceController::class, 'show'])->name('invoices.show');
                    Route::post('invoices/{invoice}/mark-paid', [BpInvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
                    Route::post('invoices/{invoice}/cancel', [BpInvoiceController::class, 'cancel'])->name('invoices.cancel');
                    Route::get('kickbacks', [BpKickbackInvoiceController::class, 'index'])->name('kickbacks.index');
                    Route::get('kickbacks/{kickback}', [BpKickbackInvoiceController::class, 'show'])->name('kickbacks.show');
                    Route::post('kickbacks/{kickback}/mark-paid', [BpKickbackInvoiceController::class, 'markPaid'])->name('kickbacks.mark-paid');
                    Route::post('kickbacks/{kickback}/withdraw', [BpKickbackInvoiceController::class, 'withdraw'])->name('kickbacks.withdraw');
                    Route::post('kickbacks/{kickback}/regenerate', [BpKickbackInvoiceController::class, 'regenerate'])->name('kickbacks.regenerate');

                    Route::get('tickets/received', [BpInquiryController::class, 'received'])->name('tickets.received');
                    Route::get('tickets/issued', [BpInquiryController::class, 'issued'])->name('tickets.issued');
                    Route::get('tickets/create', [BpInquiryController::class, 'create'])->name('tickets.create');
                    Route::post('tickets', [BpInquiryController::class, 'store'])->name('tickets.store');
                    Route::get('tickets/{inquiry}', [BpInquiryController::class, 'show'])->name('tickets.show');
                    Route::post('tickets/{inquiry}/withdraw', [BpInquiryController::class, 'withdraw'])->name('tickets.withdraw');
                    Route::post('tickets/{inquiry}/start-progress', [BpInquiryController::class, 'startProgress'])->name('tickets.start-progress');
                    Route::post('tickets/{inquiry}/close', [BpInquiryController::class, 'close'])->name('tickets.close');
                    Route::post('tickets/{inquiry}/reopen', [BpInquiryController::class, 'reopen'])->name('tickets.reopen');
                    Route::delete('tickets/{inquiry}', [BpInquiryController::class, 'destroy'])->name('tickets.destroy');
                    Route::get('tickets/{inquiry}/attachments/{attachment}', [BpInquiryController::class, 'downloadAttachment'])->name('tickets.attachments.download');

                    Route::get('announcements', [BpAnnouncementController::class, 'index'])->name('announcements.index');
                    Route::get('announcements/create', [BpAnnouncementController::class, 'create'])->name('announcements.create');
                    Route::post('announcements', [BpAnnouncementController::class, 'store'])->name('announcements.store');
                    Route::get('announcements/{announcement}/edit', [BpAnnouncementController::class, 'edit'])->name('announcements.edit');
                    Route::put('announcements/{announcement}', [BpAnnouncementController::class, 'update'])->name('announcements.update');
                    Route::get('announcements/{announcement}', [BpAnnouncementController::class, 'show'])->name('announcements.show');
                    Route::delete('announcements/{announcement}', [BpAnnouncementController::class, 'destroy'])->name('announcements.destroy');
                }
            });
        });
    });
}
