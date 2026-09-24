<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\ItemType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemTypeController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'item.manage');

        return view('admin.item-types.index', [
            'types' => ItemType::query()
                ->whereNull('owning_bp_id')
                ->orderBy('name')
                ->paginate(50),
            'routePrefix' => 'admin',
        ]);
    }

    public function store(Request $request, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->authorize($request->user('admin'), 'item.manage');
        $validated = $this->validated($request);

        ItemType::query()->create([
            'name' => $validated['name'],
            'message' => $validated['message'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'owning_bp_id' => null,
        ]);

        return back()->with('status', '品目種別を追加しました。');
    }

    public function update(Request $request, ItemType $itemType, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->authorize($request->user('admin'), 'item.manage');
        abort_unless($itemType->owning_bp_id === null, 404);

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
