<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Iam\Services\AdminPrivilegeService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BpTwoFactorModeController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'admin.bp.two_factor.manage', [
            'resource_type' => 'bp',
        ]);

        $tab = $request->input('tab', 'bp');
        if (! in_array($tab, ['bp', 'customer'], true)) {
            $tab = 'bp';
        }

        $modes = TwoFactorMode::cases();

        if ($tab === 'customer') {
            $managingBpId = $request->filled('managing_bp_id') ? (int) $request->input('managing_bp_id') : null;
            $cn = trim((string) $request->input('cn', ''));
            $cnName = trim((string) $request->input('cn_name', ''));

            $customers = Customer::query()
                ->with('managingBp')
                ->when($managingBpId === null, fn ($query) => $query->whereRaw('1 = 0'))
                ->when($managingBpId !== null, function ($query) use ($managingBpId, $cn, $cnName) {
                    $query->where('managing_bp_id', $managingBpId);

                    if ($cn !== '') {
                        $normalized = IdentifierNormalizer::normalize($cn);
                        $query->where('code', 'like', "%{$normalized}%");
                    }

                    if ($cnName !== '') {
                        $query->where('name', 'like', "%{$cnName}%");
                    }
                })
                ->orderBy('code')
                ->paginate(20)
                ->withQueryString();

            return view('admin.bp-two-factor.index', [
                'tab' => $tab,
                'modes' => $modes,
                'partners' => null,
                'customers' => $customers,
                'managingPartners' => BusinessPartner::query()->orderBy('depth')->orderBy('code')->get(['id', 'code', 'name']),
                'filters' => [
                    'managing_bp_id' => $managingBpId,
                    'cn' => $cn,
                    'cn_name' => $cnName,
                ],
            ]);
        }

        $bpn = trim((string) $request->input('bpn', ''));
        $bpName = trim((string) $request->input('bp_name', ''));

        $partners = BusinessPartner::query()
            ->when($bpn !== '', function ($query) use ($bpn) {
                $normalized = IdentifierNormalizer::normalize($bpn);
                $query->where('code', 'like', "%{$normalized}%");
            })
            ->when($bpName !== '', fn ($query) => $query->where('name', 'like', "%{$bpName}%"))
            ->orderBy('depth')
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return view('admin.bp-two-factor.index', [
            'tab' => $tab,
            'modes' => $modes,
            'partners' => $partners,
            'customers' => null,
            'managingPartners' => collect(),
            'filters' => [
                'bpn' => $bpn,
                'bp_name' => $bpName,
                'managing_bp_id' => null,
                'cn' => '',
                'cn_name' => '',
            ],
        ]);
    }

    public function update(Request $request, BusinessPartner $businessPartner, AdminPrivilegeService $privileges): RedirectResponse
    {
        $validated = $request->validate([
            'two_factor_mode' => ['required', Rule::enum(TwoFactorMode::class)],
        ]);

        $privileges->updateBpTwoFactorMode(
            $request->user('admin'),
            $businessPartner,
            TwoFactorMode::from($validated['two_factor_mode'])
        );

        return back()->with('status', "{$businessPartner->code} の2FAモードを更新しました。");
    }

    public function updateCustomer(Request $request, Customer $customer, AdminPrivilegeService $privileges): RedirectResponse
    {
        $validated = $request->validate([
            'two_factor_mode' => ['required', Rule::enum(TwoFactorMode::class)],
        ]);

        $privileges->updateCustomerTwoFactorMode(
            $request->user('admin'),
            $customer,
            TwoFactorMode::from($validated['two_factor_mode'])
        );

        return back()->with('status', "{$customer->code} の2FAモードを更新しました。");
    }
}
