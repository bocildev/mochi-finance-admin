<?php

namespace App\Http\Middleware;

use App\Services\IamSsoService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequireSsoSession
{
    public function __construct(private IamSsoService $sso) {}

    public function handle(Request $request, Closure $next, ?string $requiredPermission = null): Response
    {
        $sessionUser = $request->session()->get('iam_user');
        if (! $sessionUser || empty($sessionUser['sub'])) {
            return redirect()->route('login');
        }

        $accessToken = (string) $request->session()->get('iam_access_token', '');
        if ($accessToken !== '' && ! $this->sso->isSessionActiveInIam($accessToken)) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('silent_sso_checked', true);

            return redirect()->route('login', ['manual' => 1])->withErrors([
                'sso' => 'Sesi Anda telah dicabut oleh Administrator atau Anda telah keluar dari SSO.',
            ]);
        }

        $dbUser = DB::table('finance_users')->where('sub', $sessionUser['sub'])->first();
        if (! $dbUser || $dbUser->status !== 'active') {
            $request->session()->forget(['iam_user', 'iam_access_token']);

            return redirect()->route('login')->withErrors([
                'sso' => 'Akun SSO Anda telah dinonaktifkan atau ditangguhkan.',
            ]);
        }

        $roles = json_decode($dbUser->roles ?: '[]', true) ?: [];
        $permissions = json_decode($dbUser->permissions ?: '[]', true) ?: [];

        $userContext = [
            'sub' => $dbUser->sub,
            'name' => $dbUser->name,
            'email' => $dbUser->email,
            'plan_tier' => $dbUser->plan_tier ?? 'free',
            'roles' => $roles,
            'permissions' => $permissions,
            'tenant_id' => $dbUser->tenant_id,
            'last_sync_at' => $dbUser->last_sync_at,
        ];

        $request->session()->put('iam_user', $userContext);
        $request->attributes->set('iam_user', $userContext);

        // Proteksi khusus halaman Admin: bila user adalah klien (free_user / pro_user) dan bukan admin
        if ($requiredPermission && ! $this->hasPermission($permissions, $requiredPermission) && $userContext['plan_tier'] !== 'admin') {
            return response()->view('auth.restricted', [
                'user' => $userContext,
                'pwaUrl' => config('iam.pwa_url'),
            ], 403);
        }

        return $next($request);
    }

    private function hasPermission(array $permissions, string $required): bool
    {
        foreach ($permissions as $perm) {
            if ($perm === '*' || $perm === $required) {
                return true;
            }
            if (str_ends_with($perm, '.*') && str_starts_with($required, substr($perm, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
