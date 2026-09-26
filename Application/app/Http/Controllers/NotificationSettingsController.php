<?php

namespace App\Http\Controllers;

use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Services\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationSettingsController extends Controller
{
    public function edit(Request $request, NotificationDispatcher $dispatcher): View
    {
        $guard = $this->guardFromRequest($request);
        $user = $request->user($guard);

        return view('notification.settings', [
            'guard' => $guard,
            'preferences' => $dispatcher->preferencesFor($user),
            'types' => NotificationType::cases(),
            'updateRoute' => route($guard.'.notification-settings.update'),
            'dashboardRoute' => route($guard.'.dashboard'),
        ]);
    }

    public function update(Request $request, NotificationDispatcher $dispatcher): RedirectResponse
    {
        $guard = $this->guardFromRequest($request);
        $user = $request->user($guard);

        $raw = $request->input('preferences', []);
        $dispatcher->syncPreferences(
            $user,
            $dispatcher->enabledMapFromRequest(is_array($raw) ? $raw : [])
        );

        return redirect()
            ->route($guard.'.notification-settings.edit')
            ->with('status', '通知設定を保存しました。');
    }

    private function guardFromRequest(Request $request): string
    {
        foreach (['admin', 'bp', 'customer'] as $guard) {
            if ($request->user($guard)) {
                return $guard;
            }
        }

        abort(401);
    }
}
