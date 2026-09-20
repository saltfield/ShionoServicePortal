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
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\ItemController;
use App\Http\Controllers\Admin\ItemDocumentController;
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
use App\Http\Controllers\Bp\ItemController as BpItemController;
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
use App\Http\Controllers\PostalLookupController;
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
                    Route::get('contract-items/{contractItem}/documents/{document}/download', [CustomerContractController::class, 'downloadDocument'])->name('contracts.items.documents.download');

                    Route::get('inquiries', [CustomerInquiryController::class, 'index'])->name('inquiries.index');
                    Route::get('inquiries/create', [CustomerInquiryController::class, 'create'])->name('inquiries.create');
                    Route::post('inquiries', [CustomerInquiryController::class, 'store'])->name('inquiries.store');
                    Route::get('inquiries/{inquiry}', [CustomerInquiryController::class, 'show'])->name('inquiries.show');

                    Route::get('invoices', [CustomerInvoiceController::class, 'index'])->name('invoices.index');
                    Route::get('invoices/{invoice}', [CustomerInvoiceController::class, 'show'])->name('invoices.show');

                    Route::get('announcements', [CustomerAnnouncementController::class, 'index'])->name('announcements.index');
                    Route::get('announcements/{announcement}', [CustomerAnnouncementController::class, 'show'])->name('announcements.show');
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

                    Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
                    Route::get('contracts/create', [ContractController::class, 'create'])->name('contracts.create');
                    Route::post('contracts', [ContractController::class, 'store'])->name('contracts.store');
                    Route::get('contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
                    Route::delete('contracts/{contract}', [ContractController::class, 'destroy'])->name('contracts.destroy');
                    Route::put('contracts/{contract}/prices', [ContractController::class, 'updatePrices'])->name('contracts.prices');
                    Route::post('contracts/{contract}/submit-approval', [ContractController::class, 'submitApproval'])->name('contracts.submit-approval');
                    Route::post('contracts/{contract}/activate', [ContractController::class, 'activate'])->name('contracts.activate');
                    Route::post('contracts/{contract}/regenerate-documents', [ContractController::class, 'regenerateDocuments'])->name('contracts.regenerate-documents');
                    Route::put('contract-items/{contractItem}/data', [ContractController::class, 'upsertData'])->name('contracts.items.data');
                    Route::get('contract-items/{contractItem}/documents/{document}/download', [ContractController::class, 'downloadDocument'])->name('contracts.items.documents.download');

                    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
                    Route::post('applications/{application}/decide', [ApplicationController::class, 'decide'])->name('applications.decide');

                    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
                    Route::get('invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
                    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
                    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
                    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
                    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');

                    Route::get('inquiries', [InquiryController::class, 'index'])->name('inquiries.index');
                    Route::get('inquiries/create', [InquiryController::class, 'create'])->name('inquiries.create');
                    Route::post('inquiries', [InquiryController::class, 'store'])->name('inquiries.store');
                    Route::get('inquiries/{inquiry}', [InquiryController::class, 'show'])->name('inquiries.show');
                    Route::post('inquiries/{inquiry}/close', [InquiryController::class, 'close'])->name('inquiries.close');
                    Route::post('inquiries/{inquiry}/reopen', [InquiryController::class, 'reopen'])->name('inquiries.reopen');
                    Route::delete('inquiries/{inquiry}', [InquiryController::class, 'destroy'])->name('inquiries.destroy');

                    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
                    Route::get('announcements/create', [AnnouncementController::class, 'create'])->name('announcements.create');
                    Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
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
                    Route::get('prices/wholesale', [BpWholesalePriceController::class, 'index'])->name('prices.wholesale.index');
                    Route::post('prices/wholesale', [BpWholesalePriceController::class, 'store'])->name('prices.wholesale.store');
                    Route::get('customers/{customer}/prices', [BpCustomerPriceController::class, 'edit'])->name('customers.prices.edit');
                    Route::put('customers/{customer}/prices', [BpCustomerPriceController::class, 'update'])->name('customers.prices.update');

                    Route::get('contracts', [BpContractController::class, 'index'])->name('contracts.index');
                    Route::get('contracts/create', [BpContractController::class, 'create'])->name('contracts.create');
                    Route::post('contracts', [BpContractController::class, 'store'])->name('contracts.store');
                    Route::get('contracts/{contract}', [BpContractController::class, 'show'])->name('contracts.show');
                    Route::delete('contracts/{contract}', [BpContractController::class, 'destroy'])->name('contracts.destroy');
                    Route::put('contracts/{contract}/prices', [BpContractController::class, 'updatePrices'])->name('contracts.prices');
                    Route::post('contracts/{contract}/submit-approval', [BpContractController::class, 'submitApproval'])->name('contracts.submit-approval');
                    Route::post('contracts/{contract}/price-change', [BpContractController::class, 'submitPriceChange'])->name('contracts.price-change');
                    Route::post('contracts/{contract}/activate', [BpContractController::class, 'activate'])->name('contracts.activate');
                    Route::post('contracts/{contract}/regenerate-documents', [BpContractController::class, 'regenerateDocuments'])->name('contracts.regenerate-documents');
                    Route::put('contract-items/{contractItem}/data', [BpContractController::class, 'upsertData'])->name('contracts.items.data');
                    Route::get('contract-items/{contractItem}/documents/{document}/download', [BpContractController::class, 'downloadDocument'])->name('contracts.items.documents.download');

                    Route::get('applications', [BpApplicationController::class, 'index'])->name('applications.index');
                    Route::post('applications/{application}/decide', [BpApplicationController::class, 'decide'])->name('applications.decide');

                    Route::get('invoices', [BpInvoiceController::class, 'index'])->name('invoices.index');
                    Route::get('invoices/create', [BpInvoiceController::class, 'create'])->name('invoices.create');
                    Route::post('invoices', [BpInvoiceController::class, 'store'])->name('invoices.store');
                    Route::get('invoices/{invoice}', [BpInvoiceController::class, 'show'])->name('invoices.show');
                    Route::post('invoices/{invoice}/mark-paid', [BpInvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
                    Route::post('invoices/{invoice}/cancel', [BpInvoiceController::class, 'cancel'])->name('invoices.cancel');

                    Route::get('inquiries', [BpInquiryController::class, 'index'])->name('inquiries.index');
                    Route::get('inquiries/create', [BpInquiryController::class, 'create'])->name('inquiries.create');
                    Route::post('inquiries', [BpInquiryController::class, 'store'])->name('inquiries.store');
                    Route::get('inquiries/{inquiry}', [BpInquiryController::class, 'show'])->name('inquiries.show');
                    Route::post('inquiries/{inquiry}/close', [BpInquiryController::class, 'close'])->name('inquiries.close');
                    Route::post('inquiries/{inquiry}/reopen', [BpInquiryController::class, 'reopen'])->name('inquiries.reopen');
                    Route::delete('inquiries/{inquiry}', [BpInquiryController::class, 'destroy'])->name('inquiries.destroy');

                    Route::get('announcements', [BpAnnouncementController::class, 'index'])->name('announcements.index');
                    Route::get('announcements/create', [BpAnnouncementController::class, 'create'])->name('announcements.create');
                    Route::post('announcements', [BpAnnouncementController::class, 'store'])->name('announcements.store');
                    Route::get('announcements/{announcement}', [BpAnnouncementController::class, 'show'])->name('announcements.show');
                    Route::delete('announcements/{announcement}', [BpAnnouncementController::class, 'destroy'])->name('announcements.destroy');
                }
            });
        });
    });
}
