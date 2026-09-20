<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Contract\Support\ReservedReplaceCodes;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\DataFieldName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DataFieldNameController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'item.manage');

        return view('admin.data-field-names.index', [
            'names' => DataFieldName::query()->orderBy('name')->paginate(50),
            'reservedCodes' => ReservedReplaceCodes::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        DataFieldName::query()->create([
            'name' => $validated['name'],
            'replace_code' => $validated['replace_code'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', '名称マスタを追加しました。');
    }

    public function update(Request $request, DataFieldName $dataFieldName): RedirectResponse
    {
        $validated = $this->validated($request, $dataFieldName);

        $dataFieldName->update([
            'name' => $validated['name'],
            'replace_code' => $validated['replace_code'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', '名称マスタを更新しました。');
    }

    /**
     * @return array{name: string, replace_code: string}
     */
    private function validated(Request $request, ?DataFieldName $existing = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('data_field_names', 'name')->ignore($existing?->id),
            ],
            'replace_code' => [
                'required',
                'string',
                'max:64',
                'regex:'.ReservedReplaceCodes::pattern(),
                Rule::unique('data_field_names', 'replace_code')->ignore($existing?->id),
            ],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'replace_code.regex' => '置換コードは半角英小文字で始め、英小文字・数字・_ のみ使用できます。',
        ]);

        if (ReservedReplaceCodes::isReserved($validated['replace_code'])) {
            throw ValidationException::withMessages([
                'replace_code' => 'この置換コードは予約語のため登録できません。',
            ]);
        }

        return $validated;
    }
}
