<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\LoginFlowService;
use App\Domains\Auth\Services\TwoFactorPolicyResolver;
use App\Domains\Auth\Services\TwoFactorService;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Requests\Auth\TwoFactorSetupRequest;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

trait HandlesTwoFactorAuth
{
    abstract protected function guardName(): string;

    abstract protected function userType(): UserType;

    public function showChallenge(LoginFlowService $loginFlow): View|RedirectResponse
    {
        $user = $loginFlow->pendingUser($this->guardName());

        if ($user === null || $loginFlow->pendingPurpose($this->guardName()) !== 'challenge') {
            return redirect()->route($this->guardName().'.login');
        }

        return view('auth.two-factor.challenge', [
            'guard' => $this->guardName(),
            'storeRoute' => route($this->guardName().'.two-factor.challenge.store'),
            'loginRoute' => route($this->guardName().'.login'),
        ]);
    }

    public function storeChallenge(
        TwoFactorChallengeRequest $request,
        LoginFlowService $loginFlow,
        TwoFactorService $twoFactor,
    ): RedirectResponse {
        $user = $loginFlow->pendingUser($this->guardName());

        if ($user === null || $loginFlow->pendingPurpose($this->guardName()) !== 'challenge') {
            return redirect()->route($this->guardName().'.login');
        }

        $secret = $twoFactor->plainSecret($user);

        if ($secret === null || ! $twoFactor->verify($secret, $request->validated('code'))) {
            return back()->withErrors(['code' => '認証コードが正しくありません。']);
        }

        $loginFlow->completePendingLogin($this->guardName(), $user);
        $request->session()->regenerate();

        return $loginFlow->redirectAfterAuthentication($this->guardName(), $user->fresh());
    }

    public function showSetup(LoginFlowService $loginFlow, TwoFactorService $twoFactor): View|RedirectResponse
    {
        $user = $loginFlow->pendingUser($this->guardName());

        if ($user === null || $loginFlow->pendingPurpose($this->guardName()) !== 'setup') {
            return redirect()->route($this->guardName().'.login');
        }

        $secret = $loginFlow->setupSecret($this->guardName()) ?? $twoFactor->generateSecret();
        $loginFlow->storeSetupSecret($this->guardName(), $secret);

        $qrUrl = $twoFactor->qrCodeUrl($user, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd));
        $qrSvg = $writer->writeString($qrUrl);

        return view('auth.two-factor.setup', [
            'guard' => $this->guardName(),
            'secret' => $secret,
            'qrSvg' => $qrSvg,
            'storeRoute' => route($this->guardName().'.two-factor.setup.store'),
            'loginRoute' => route($this->guardName().'.login'),
        ]);
    }

    public function storeSetup(
        TwoFactorSetupRequest $request,
        LoginFlowService $loginFlow,
        TwoFactorService $twoFactor,
    ): RedirectResponse {
        $user = $loginFlow->pendingUser($this->guardName());
        $secret = $loginFlow->setupSecret($this->guardName());

        if ($user === null || $secret === null) {
            return redirect()->route($this->guardName().'.login');
        }

        $twoFactor->confirmSetup($user, $secret, $request->validated('code'));
        $loginFlow->completePendingLogin($this->guardName(), $user->fresh());
        $request->session()->regenerate();

        return $loginFlow->redirectAfterAuthentication($this->guardName(), $user->fresh());
    }

    public function showSettings(
        Request $request,
        TwoFactorService $twoFactor,
        TwoFactorPolicyResolver $policy,
    ): View {
        $user = $request->user($this->guardName());
        $mode = $policy->resolve($user);
        $enabled = $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
        $canEnable = $policy->canSelfEnable($user) && ! $enabled;
        $canDisable = $policy->canSelfDisable($user) && $enabled;

        $secret = null;
        $qrSvg = null;

        if ($canEnable) {
            $secret = Session::get($this->accountSetupSecretKey()) ?? $twoFactor->generateSecret();
            Session::put($this->accountSetupSecretKey(), $secret);
            $qrUrl = $twoFactor->qrCodeUrl($user, $secret);
            $writer = new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd));
            $qrSvg = $writer->writeString($qrUrl);
        }

        return view('auth.two-factor.settings', [
            'guard' => $this->guardName(),
            'mode' => $mode,
            'enabled' => $enabled,
            'canEnable' => $canEnable,
            'canDisable' => $canDisable,
            'secret' => $secret,
            'qrSvg' => $qrSvg,
            'enableRoute' => route($this->guardName().'.two-factor.settings.enable'),
            'disableRoute' => route($this->guardName().'.two-factor.disable'),
            'dashboardRoute' => route($this->guardName().'.dashboard'),
        ]);
    }

    public function enableFromSettings(
        TwoFactorSetupRequest $request,
        TwoFactorService $twoFactor,
    ): RedirectResponse {
        $user = $request->user($this->guardName());
        $secret = Session::get($this->accountSetupSecretKey());

        if ($secret === null) {
            return redirect()
                ->route($this->guardName().'.two-factor.settings')
                ->withErrors(['code' => '設定セッションが切れました。もう一度お試しください。']);
        }

        $twoFactor->confirmSetup($user, $secret, $request->validated('code'));
        Session::forget($this->accountSetupSecretKey());

        return redirect()
            ->route($this->guardName().'.two-factor.settings')
            ->with('status', '二段階認証を有効化しました。');
    }

    public function disable(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $user = $request->user($this->guardName());
        $twoFactor->disable($user);
        Session::forget($this->accountSetupSecretKey());

        return redirect()
            ->route($this->guardName().'.two-factor.settings')
            ->with('status', '二段階認証を無効化しました。');
    }

    private function accountSetupSecretKey(): string
    {
        return 'auth.'.$this->guardName().'.account_2fa_secret';
    }
}
