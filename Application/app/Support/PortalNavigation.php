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
     * @return list<array{
     *     label: string,
     *     route?: string,
     *     permission?: string|list<string>|null,
     *     children?: list<array{label: string, route: string, permission?: string|list<string>|null}>
     * }>
     */
    public static function menuItems(string $guard): array
    {
        return match ($guard) {
            'admin' => [
                ['label' => 'BP管理', 'route' => 'admin.business-partners.index', 'permission' => 'bp.view'],
                ['label' => 'カスタマー管理', 'route' => 'admin.customers.index', 'permission' => 'customer.view'],
                [
                    'label' => 'チケット管理',
                    'children' => [
                        ['label' => '受領チケット', 'route' => 'admin.tickets.received', 'permission' => 'inquiry.view'],
                    ],
                ],
                [
                    'label' => '品目管理',
                    'children' => [
                        ['label' => '品目', 'route' => 'admin.items.index', 'permission' => 'item.manage'],
                        ['label' => '品目種別', 'route' => 'admin.item-types.index', 'permission' => 'item.manage'],
                        ['label' => '卸価格', 'route' => 'admin.prices.wholesale.index', 'permission' => 'price.wholesale.edit'],
                    ],
                ],
                [
                    'label' => 'マスタ管理',
                    'children' => [
                        ['label' => 'お知らせ', 'route' => 'admin.announcements.index', 'permission' => null],
                        ['label' => '契約一覧', 'route' => 'admin.contracts.index', 'permission' => 'contract.view'],
                        ['label' => '請求一覧', 'route' => 'admin.invoices.index', 'permission' => 'invoice.view'],
                        ['label' => 'キックバック', 'route' => 'admin.kickbacks.index', 'permission' => 'invoice.view'],
                        ['label' => '自動請求設定', 'route' => 'admin.billing-batch.edit', 'permission' => 'invoice.manage'],
                        ['label' => '価格申請', 'route' => 'admin.applications.index', 'permission' => 'contract.approve'],
                        ['label' => 'データ名称', 'route' => 'admin.data-field-names.index', 'permission' => 'item.manage'],
                        ['label' => '2FAモード', 'route' => 'admin.bp-two-factor.index', 'permission' => 'admin.bp.two_factor.manage'],
                    ],
                ],
                ['label' => 'ユーザー管理', 'route' => 'admin.users.index', 'permission' => 'iam.user.manage'],
                ['label' => '監査ログ', 'route' => 'admin.audit-logs.index', 'permission' => 'audit.log.view'],
            ],
            'bp' => [
                ['label' => 'BP管理', 'route' => 'bp.business-partners.index', 'permission' => 'bp.view'],
                ['label' => 'カスタマー管理', 'route' => 'bp.customers.index', 'permission' => 'customer.view'],
                [
                    'label' => 'チケット管理',
                    'children' => [
                        ['label' => '受領チケット', 'route' => 'bp.tickets.received', 'permission' => 'inquiry.view'],
                        ['label' => 'チケット発行', 'route' => 'bp.tickets.issued', 'permission' => 'inquiry.view'],
                    ],
                ],
                [
                    'label' => '品目管理',
                    'children' => [
                        ['label' => '品目', 'route' => 'bp.items.index', 'permission' => ['price.wholesale.edit', 'price.customer.edit', 'contract.create']],
                        ['label' => '品目種別', 'route' => 'bp.item-types.index', 'permission' => 'contract.create'],
                        ['label' => '卸価格', 'route' => 'bp.prices.wholesale.index', 'permission' => 'price.wholesale.edit'],
                    ],
                ],
                [
                    'label' => 'マスタ管理',
                    'children' => [
                        ['label' => 'お知らせ', 'route' => 'bp.announcements.index', 'permission' => null],
                        ['label' => '契約一覧', 'route' => 'bp.contracts.index', 'permission' => 'contract.view'],
                        ['label' => '請求一覧', 'route' => 'bp.invoices.index', 'permission' => 'invoice.view'],
                        ['label' => 'キックバック', 'route' => 'bp.kickbacks.index', 'permission' => 'invoice.view'],
                        ['label' => '価格申請', 'route' => 'bp.applications.index', 'permission' => 'contract.approve'],
                    ],
                ],
                ['label' => 'ユーザー管理', 'route' => 'bp.users.index', 'permission' => 'iam.user.manage'],
            ],
            'customer' => [
                [
                    'label' => 'チケット管理',
                    'children' => [
                        ['label' => '受領チケット', 'route' => 'customer.tickets.received', 'permission' => 'inquiry.view'],
                        ['label' => 'チケット発行', 'route' => 'customer.tickets.issued', 'permission' => 'inquiry.view'],
                    ],
                ],
                [
                    'label' => 'マスタ管理',
                    'children' => [
                        ['label' => '契約一覧', 'route' => 'customer.contracts.index', 'permission' => 'contract.view'],
                        ['label' => '請求一覧', 'route' => 'customer.invoices.index', 'permission' => 'invoice.view'],
                        ['label' => 'お知らせ', 'route' => 'customer.announcements.index', 'permission' => null],
                    ],
                ],
                ['label' => 'ユーザー管理', 'route' => 'customer.users.index', 'permission' => 'iam.user.manage'],
            ],
            default => [],
        };
    }

    /**
     * @return list<array{type: 'link', label: string, url: string}|array{type: 'group', label: string, children: list<array{label: string, url: string}>}>
     */
    public static function visibleMenu(string $guard, ?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $rbac = app(RbacService::class);
        $items = [];

        foreach (self::menuItems($guard) as $item) {
            if (isset($item['children']) && is_array($item['children'])) {
                $children = [];
                foreach ($item['children'] as $child) {
                    $resolved = self::resolveVisibleLink($rbac, $user, $child);
                    if ($resolved !== null) {
                        $children[] = $resolved;
                    }
                }
                if ($children === []) {
                    continue;
                }
                $items[] = [
                    'type' => 'group',
                    'label' => $item['label'],
                    'children' => $children,
                ];
                continue;
            }

            $resolved = self::resolveVisibleLink($rbac, $user, $item);
            if ($resolved !== null) {
                $items[] = [
                    'type' => 'link',
                    'label' => $resolved['label'],
                    'url' => $resolved['url'],
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array{label: string, route?: string, permission?: string|list<string>|null}  $item
     * @return array{label: string, url: string}|null
     */
    private static function resolveVisibleLink(RbacService $rbac, User $user, array $item): ?array
    {
        $route = $item['route'] ?? null;
        if (! is_string($route) || ! Route::has($route)) {
            return null;
        }

        $permission = $item['permission'] ?? null;
        if (is_string($permission) && ! $rbac->hasPermission($user, $permission)) {
            return null;
        }
        if (is_array($permission) && ! $rbac->hasAnyPermission($user, $permission)) {
            return null;
        }

        return [
            'label' => $item['label'],
            'url' => route($route),
        ];
    }
}
