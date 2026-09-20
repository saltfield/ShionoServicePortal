<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class PostalLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! Auth::guard('admin')->check() && ! Auth::guard('bp')->check() && ! Auth::guard('customer')->check()) {
            abort(401);
        }

        $zipcode = preg_replace('/\D+/', '', (string) $request->query('zipcode', '')) ?? '';

        if (strlen($zipcode) !== 7) {
            return response()->json([
                'ok' => false,
                'message' => '郵便番号は7桁で入力してください。',
            ], 422);
        }

        $response = Http::timeout(5)
            ->acceptJson()
            ->get('https://zipcloud.ibsnet.co.jp/api/search', [
                'zipcode' => $zipcode,
            ]);

        if (! $response->successful()) {
            return response()->json([
                'ok' => false,
                'message' => '住所の取得に失敗しました。',
            ], 502);
        }

        $results = $response->json('results') ?? [];

        if ($results === [] || $results === null) {
            return response()->json([
                'ok' => false,
                'message' => '該当する住所が見つかりませんでした。',
            ], 404);
        }

        $first = $results[0];

        return response()->json([
            'ok' => true,
            'postal_code' => substr($zipcode, 0, 3).'-'.substr($zipcode, 3),
            'address' => ($first['address1'] ?? '').($first['address2'] ?? '').($first['address3'] ?? ''),
            'prefecture' => $first['address1'] ?? '',
            'city' => $first['address2'] ?? '',
            'town' => $first['address3'] ?? '',
        ]);
    }
}
