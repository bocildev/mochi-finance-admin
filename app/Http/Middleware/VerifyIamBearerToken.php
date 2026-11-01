<?php

namespace App\Http\Middleware;

use App\Services\IamSsoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyIamBearerToken
{
    public function __construct(private IamSsoService $sso) {}

    public function handle(Request $request, Closure $next, ?string $requiredPermission = null): Response
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin' => $request->header('Origin', '*'),
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept, X-Requested-With',
        ];

        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $corsHeaders);
        }

        $token = $request->bearerToken();
        if (! $token) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Bearer access token OIDC dari IAM-SSO-CORE wajib disertakan.',
            ], 401, $corsHeaders);
        }

        try {
            $claims = $this->sso->verifyAccessToken($token);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => $e->getMessage(),
            ], 401, $corsHeaders);
        }

        if (! $this->sso->isSessionActiveInIam($token)) {
            return response()->json([
                'error' => 'session_revoked',
                'message' => 'Sesi aplikasi ini telah dicabut atau sesi SSO Anda telah berakhir.',
            ], 401, $corsHeaders);
        }

        $userRow = $this->sso->syncFinanceUser($claims);
        if ($userRow->status !== 'active') {
            return response()->json([
                'error' => 'user_disabled',
                'message' => 'Akun pengguna sedang ditangguhkan oleh Administrator Mochi Finance.',
            ], 403, $corsHeaders);
        }

        $livePermissions = json_decode($userRow->permissions ?: '[]', true) ?: $claims->permissions;

        if ($requiredPermission && ! $this->hasPermission($livePermissions, $requiredPermission)) {
            return response()->json([
                'error' => 'insufficient_permissions',
                'tier' => $userRow->plan_tier ?? 'free',
                'required_permission' => $requiredPermission,
                'message' => 'Fitur ini khusus untuk member Mochi Pro ✨ (butuh permission: '.$requiredPermission.').',
            ], 403, $corsHeaders);
        }

        $request->attributes->set('iam_claims', $claims);
        $request->attributes->set('iam_user', $userRow);

        $response = $next($request);
        foreach ($corsHeaders as $k => $v) {
            $response->headers->set($k, $v);
        }

        return $response;
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
