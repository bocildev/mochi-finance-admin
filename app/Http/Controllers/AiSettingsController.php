<?php

namespace App\Http\Controllers;

use App\Services\GeminiPlannerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AiSettingsController extends Controller
{
    public function __construct(private GeminiPlannerService $gemini) {}

    public function index(Request $request)
    {
        $pendingUpgradesCount = 0;
        $settingsTableReady = false;

        try {
            if (Schema::hasTable('upgrade_requests')) {
                $pendingUpgradesCount = (int) DB::table('upgrade_requests')->where('status', 'PENDING')->count();
            }
            $settingsTableReady = Schema::hasTable('system_settings');
        } catch (Throwable) {
            $settingsTableReady = false;
        }

        return view('admin.ai-settings', [
            'user' => $request->attributes->get('iam_user'),
            'pendingUpgradesCount' => $pendingUpgradesCount,
            'pwaUrl' => config('iam.pwa_url'),
            'iamIssuer' => config('iam.issuer'),
            'settingsTableReady' => $settingsTableReady,
            'hasApiKey' => $this->gemini->hasApiKey(),
            'maskedKey' => $this->gemini->getMaskedApiKey(),
            'keySource' => $this->gemini->getApiKeySource(),
            'defaultModel' => $this->gemini->getDefaultModel(),
            'models' => $this->gemini->availableModels(),
            'testResult' => $request->session()->get('ai_test_result'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (! Schema::hasTable('system_settings')) {
            return redirect()
                ->route('admin.ai-settings')
                ->withErrors(['ai' => 'Tabel system_settings belum dibuat. Jalankan Migrate terlebih dahulu di menu Maintenance & Debugger.']);
        }

        $allowedModelIds = array_column($this->gemini->availableModels(), 'id');
        $data = $request->validate([
            'google_api_key' => 'nullable|string|max:512',
            'clear_api_key' => 'nullable|boolean',
            'default_model' => 'required|string|in:'.implode(',', $allowedModelIds),
        ]);

        $this->gemini->setDefaultModel($data['default_model']);

        if ($request->boolean('clear_api_key')) {
            $this->gemini->setApiKey(null);
            $msg = 'API Key tersimpan di database dihapus & model default diperbarui ke '.$data['default_model'].'.';
        } elseif (! empty(trim((string) ($data['google_api_key'] ?? '')))) {
            $this->gemini->setApiKey($data['google_api_key']);
            $msg = 'Google Gemini API Key terenkripsi berhasil disimpan dan model default diset ke '.$data['default_model'].'.';
        } else {
            $msg = 'Model default Google AI berhasil diperbarui menjadi '.$data['default_model'].'.';
        }

        return redirect()->route('admin.ai-settings')->with('status', $msg);
    }

    public function test(Request $request): RedirectResponse
    {
        $model = $request->input('model');
        $result = $this->gemini->testConnection(is_string($model) ? $model : null);

        $request->session()->flash('ai_test_result', $result);

        return redirect()
            ->route('admin.ai-settings')
            ->with($result['ok'] ? 'status' : 'error', $result['message']);
    }
}
