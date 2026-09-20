<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemController extends Controller
{
    public function index(Request $request, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless(
            $rbac->hasPermission($actor, 'price.wholesale.edit')
            || $rbac->hasPermission($actor, 'price.customer.edit'),
            403
        );

        $items = Item::query()
            ->with('requiredItem')
            ->where('is_active', true)
            ->orderBy('code')
            ->paginate(20);

        return view('admin.items.index', [
            'items' => $items,
            'routePrefix' => 'bp',
            'readOnly' => true,
        ]);
    }
}
