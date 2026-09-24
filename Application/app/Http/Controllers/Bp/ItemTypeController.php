<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Controller;
use App\Models\ItemType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemTypeController extends Controller
{
    public function index(Request $request, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);

        return view('admin.item-types.index', [
            'types' => ItemType::query()
                ->where('owning_bp_id', $bp->id)
                ->orderBy('name')
                ->paginate(50),
            'routePrefix' => 'bp',
        ]);
    }

    public function store(Request $request, RbacService $rbac): RedirectResponse
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);

        $validated = $this->validated($request);

        ItemType::query()->create([
            'name' => $validated['name'],
            'message' => $validated['message'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'owning_bp_id' => $bp->id,
        ]);

        return back()->with('status', '品目種別を追加しました。');
    }

    public function update(Request $request, ItemType $itemType, RbacService $rbac): RedirectResponse
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);
        abort_unless((int) $itemType->owning_bp_id === (int) $bp->id, 404);

        $validated = $this->validated($request);
        $itemType->update([
            'name' => $validated['name'],
            'message' => $validated['message'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', '品目種別を更新しました。');
    }

    /**
     * @return array{name: string, message: ?string}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $message = isset($validated['message']) ? trim((string) $validated['message']) : '';
        $validated['message'] = $message === '' ? null : $message;

        return $validated;
    }
}
