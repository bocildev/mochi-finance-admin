<?php

namespace App\Http\Controllers;

use App\Services\IamSsoService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private IamSsoService $sso) {}

    public function showLogin(Request $request)
    {
        if ($request->session()->has('iam_user')) {
            return redirect()->route('admin.dashboard');
        }

        // Silent SSO Auto-Check: bila belum dicek di sesi ini dan tidak sedang dari error/logout, cek otomatis ke IAM
        if (
            ! app()->runningUnitTests()
            && ! $request->session()->has('errors')
            && ! $request->session()->get('silent_sso_checked')
            && ! $request->boolean('manual')
        ) {
            return $this->redirect($request, true);
        }

        return view('auth.login', [
            'issuer' => $this->sso->issuer(),
            'clientId' => $this->sso->clientId(),
            'pwaUrl' => config('iam.pwa_url'),
        ]);
    }

    public function loginSubmit(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $result = $this->sso->directLogin($data['email'], $data['password']);
        } catch (\Throwable $e) {
            return back()->withErrors([
                'sso' => $e->getMessage(),
            ])->withInput($request->only('email'));
        }

        $userRow = $result['user'];
        $claims = $result['claims'];

        $request->session()->regenerate();
        $request->session()->forget('silent_sso_checked');
        $request->session()->put('iam_access_token', $result['tokens']['access_token']);
        $request->session()->put('iam_user', [
            'sub' => $userRow->sub,
            'name' => $userRow->name,
            'email' => $userRow->email,
            'roles' => $claims->roles,
            'permissions' => $claims->permissions,
            'tenant_id' => $claims->tenantId,
            'last_sync_at' => $userRow->last_sync_at,
        ]);

        return redirect()->route('admin.dashboard')->with('status', 'Selamat datang kembali, '.$userRow->name.'!');
    }

    public function redirect(Request $request, bool $silent = false)
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = Str::random(32);

        $redirectUri = $request->getSchemeAndHttpHost().'/auth/callback';

        $request->session()->put('oidc_pkce_verifier', $verifier);
        $request->session()->put('oidc_oauth_state', $state);
        $request->session()->put('oidc_redirect_uri', $redirectUri);

        $params = [
            'response_type' => 'code',
            'client_id' => $this->sso->clientId(),
            'redirect_uri' => $redirectUri,
            'scope' => 'openid profile email',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        if ($silent || $request->boolean('silent')) {
            $params['prompt'] = 'none';
        }

        return redirect()->away($this->sso->issuer().'/oauth/authorize?'.http_build_query($params));
    }

    public function callback(Request $request)
    {
        if ($request->filled('error')) {
            $err = (string) $request->query('error');
            if ($err === 'login_required') {
                $request->session()->flash('silent_sso_checked', true);

                return redirect('/login?manual=1');
            }

            return redirect('/login?manual=1')->withErrors([
                'sso' => 'OIDC Error: '.$request->query('error_description', $err),
            ]);
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $expectedState = (string) $request->session()->pull('oidc_oauth_state', '');
        $verifier = (string) $request->session()->pull('oidc_pkce_verifier', '');
        $redirectUri = (string) $request->session()->pull('oidc_redirect_uri', $request->getSchemeAndHttpHost().'/auth/callback');

        if (! $code || ! $verifier || ($expectedState !== '' && ! hash_equals($expectedState, $state))) {
            return redirect('/login?manual=1')->withErrors([
                'sso' => 'State atau PKCE verifier tidak valid. Silakan ulangi proses login.',
            ]);
        }

        try {
            $result = $this->sso->exchangeAuthorizationCode($code, $verifier, $redirectUri);
        } catch (\Throwable $e) {
            return redirect('/login?manual=1')->withErrors([
                'sso' => $e->getMessage(),
            ]);
        }

        $userRow = $result['user'];
        $claims = $result['claims'];

        $request->session()->regenerate();
        $request->session()->forget('silent_sso_checked');
        $request->session()->put('iam_access_token', $result['tokens']['access_token']);
        $request->session()->put('iam_user', [
            'sub' => $userRow->sub,
            'name' => $userRow->name,
            'email' => $userRow->email,
            'roles' => $claims->roles,
            'permissions' => $claims->permissions,
            'tenant_id' => $claims->tenantId,
            'last_sync_at' => $userRow->last_sync_at,
        ]);

        return redirect()->route('admin.dashboard')->with('status', 'Berhasil masuk sebagai '.$userRow->name.'!');
    }

    public function logout(Request $request)
    {
        $accessToken = (string) $request->session()->get('iam_access_token', '');
        if ($accessToken !== '') {
            try {
                \Illuminate\Support\Facades\Http::timeout(4)->acceptJson()->post($this->sso->issuer().'/oauth/revoke', [
                    'client_id' => $this->sso->clientId(),
                    'access_token' => $accessToken,
                ]);
            } catch (\Throwable) {
                // Abaikan bila gagal menghubungi IAM
            }
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('silent_sso_checked', true);

        return redirect('/login?manual=1')->with('status', 'Anda telah keluar dari sesi Backoffice Mochi Finance.');
    }
}
