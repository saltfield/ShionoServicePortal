<?php

namespace App\Support;

use App\Domains\Iam\Services\RbacService;
use App\Models\User;
use Illuminate\Support\Facades\Route;

final class PortalNavigation
{
    public static function guard(): string
    {
        if (request()->routeIs('admin.*') || request()->is('admin', 'admin/*')) {
            return 'admin';
        }
        if (request()->routeIs('bp.*') || request()->is('bp', 'bp/*')) {
            return 'bp';
        }
        if (request()->routeIs('customer.*') || request()->is('customer', 'customer/*')) {
            return 'customer';
        }

        foreach (['admin', 'bp', 'customer'] as $guard) {
            if (auth($guard)->check()) {
                return $guard;
            }
        }

        return 'admin';
    }

    public static function areaLabel(string $guard): string
    {
        return match ($guard) {
            'admin' => '管理者',
            'bp' => 'BP',
            'customer' => 'カスタマー',
            default => 'ポータル',
        };
    }

    public static function user(): ?User
    {
        $user = auth(self::guard())->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return list<array{label: string, route: string, permission?: string|null}>
     */
    public static function menuItems(string $guard): array
    {
        return match ($guard) {
            'admin' => [
                ['label' => '問い合わせ', 'route' => 'admin.inquiries.index', 'permission' => 'inquiry.view'],
                ['label' => 'お知らせ', 'route' => 'admin.announcements.index', 'permission' => null],
                ['label' => 'BP', 'route' => 'admin.business-partners.index', 'permission' => 'bp.view'],
                ['label' => 'カスタマー', 'route' => 'admin.customers.index', 'permission' => 'customer.view'],
                ['label' => '品目', 'route' => 'admin.items.index', 'permission' => 'item.manage'],
                ['label' => '卸価格', 'route' => 'admin.prices.wholesale.index', 'permission' => 'price.wholesale.edit'],
                ['label' => '契約', 'route' => 'admin.contracts.index', 'permission' => 'contract.view'],
                ['label' => '請求', 'route' => 'admin.invoices.index', 'permission' => 'invoice.view'],
                ['label' => '価格申請', 'route' => 'admin.applications.index', 'permission' => 'contract.approve'],
                ['label' => 'データ名称', 'route' => 'admin.data-field-names.index', 'permission' => 'item.manage'],
                ['label' => 'ユーザー', 'route' => 'admin.users.index', 'permission' => 'iam.user.manage'],
                ['label' => '2FAモード', 'route' => 'admin.bp-two-factor.index', 'permission' => 'admin.bp.two_factor.manage'],
                ['label' => '監査ログ', 'route' => 'admin.audit-logs.index', 'permission' => 'audit.log.view'],
            ],
            'bp' => [
                ['label' => '問い合わせ', 'route' => 'bp.inquiries.index', 'permission' => 'inquiry.view'],
                ['label' => 'お知らせ', 'route' => 'bp.announcements.index', 'permission' => null],
                ['label' => '配下BP', 'route' => 'bp.business-partners.index', 'permission' => 'bp.view'],
                ['label' => 'カスタマー', 'route' => 'bp.customers.index', 'permission' => 'customer.view'],
                ['label' => '品目', 'route' => 'bp.items.index', 'permission' => ['price.wholesale.edit', 'price.customer.edit']],
                ['label' => '卸価格', 'route' => 'bp.prices.wholesale.index', 'permission' => 'price.wholesale.edit'],
                ['label' => '契約', 'route' => 'bp.contracts.index', 'permission' => 'contract.view'],
                ['label' => '請求', 'route' => 'bp.invoices.index', 'permission' => 'invoice.view'],
                ['label' => '価格申請', 'route' => 'bp.applications.index', 'permission' => 'contract.approve'],
                ['label' => 'ユーザー', 'route' => 'bp.users.index', 'permission' => 'iam.user.manage'],
            ],
            'customer' => [
                ['label' => '契約', 'route' => 'customer.contracts.index', 'permission' => 'contract.view'],
                ['label' => '請求', 'route' => 'customer.invoices.index', 'permission' => 'invoice.view'],
                ['label' => '問い合わせ', 'route' => 'customer.inquiries.index', 'permission' => 'inquiry.view'],
                ['label' => 'お知らせ', 'route' => 'customer.announcements.index', 'permission' => null],
            ],
            default => [],
        };
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public static function visibleMenu(string $guard, ?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $rbac = app(RbacService::class);
        $items = [];

        foreach (self::menuItems($guard) as $item) {
            if (! Route::has($item['route'])) {
                continue;
            }
            $permission = $item['permission'] ?? null;
            if (is_string($permission) && ! $rbac->hasPermission($user, $permission)) {
                continue;
            }
            if (is_array($permission) && ! $rbac->hasAnyPermission($user, $permission)) {
                continue;
            }
            $items[] = [
                'label' => $item['label'],
                'url' => route($item['route']),
            ];
        }

        return $items;
    }
}
