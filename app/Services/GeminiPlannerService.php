<?php

namespace App\Services;

use Carbon\Carbon;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class GeminiPlannerService
{
    public const DEFAULT_MODEL = 'gemini-3.5-flash-lite';

    /**
     * Allowed primary Google Gemini models shown to users (100% Google-only policy).
     */
    public const ALLOWED_MODELS = [
        'gemini-3.5-flash-lite' => [
            'id' => 'gemini-3.5-flash-lite',
            'name' => 'Gemini 3.5 Flash Lite',
            'badge' => 'REKOMENDASI · 15 RPM / 500 RPD',
            'description' => 'Cepat, hemat kuota harian (500 RPD), cocok untuk analisa anggaran & gaji.',
        ],
        'gemini-3.1-flash-lite' => [
            'id' => 'gemini-3.1-flash-lite',
            'name' => 'Gemini 3.1 Flash Lite',
            'badge' => 'LITE FALLBACK · 15 RPM / 500 RPD',
            'description' => 'Alternatif ringan dengan kuota 500 request per hari.',
        ],
        'gemini-3.8-flash' => [
            'id' => 'gemini-3.8-flash',
            'name' => 'Gemini 3.8 Flash',
            'badge' => 'DEEP REASONING · 5 RPM / 20 RPD',
            'description' => 'Penalaran lebih mendalam untuk simulasi pembagian hutang & multi-target.',
        ],
        'gemini-3-flash-preview' => [
            'id' => 'gemini-3-flash-preview',
            'name' => 'Gemini 3 Flash',
            'badge' => 'STANDARD FLASH · 5 RPM / 20 RPD',
            'description' => 'Model Gemini 3 Flash standar untuk perencanaan finansial seimbang.',
        ],
    ];

    /**
     * Additional official Google Gemini Flash-Lite aliases tried automatically when
     * primary models experience temporary 503 high-demand spikes.
     */
    private const INTERNAL_FALLBACK_ALIASES = [
        'gemini-flash-lite-latest' => 'Gemini Flash Lite (Latest Alias)',
        'gemini-3.1-flash-lite-preview' => 'Gemini 3.1 Flash Lite Preview',
    ];

    public function availableModels(): array
    {
        return array_values(self::ALLOWED_MODELS);
    }

    public function isValidModel(?string $model): bool
    {
        return $model !== null && array_key_exists($model, self::ALLOWED_MODELS);
    }

    public function getDefaultModel(string $userSub): string
    {
        try {
            if (Schema::hasTable('finance_user_ai_settings')) {
                $val = DB::table('finance_user_ai_settings')
                    ->where('user_sub', $userSub)
                    ->value('default_model');
                if ($this->isValidModel($val)) {
                    return $val;
                }
            }
        } catch (Throwable) {
            // Ignore when migration not yet applied
        }

        return self::DEFAULT_MODEL;
    }

    public function setDefaultModel(string $userSub, string $model): void
    {
        if (! $this->isValidModel($model)) {
            throw new RuntimeException('Model AI Google tidak valid.');
        }

        $now = Carbon::now();
        $existing = DB::table('finance_user_ai_settings')->where('user_sub', $userSub)->first();

        if ($existing) {
            DB::table('finance_user_ai_settings')->where('id', $existing->id)->update([
                'default_model' => $model,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('finance_user_ai_settings')->insert([
                'user_sub' => $userSub,
                'google_api_key_encrypted' => null,
                'default_model' => $model,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function getApiKey(string $userSub): string
    {
        try {
            if (Schema::hasTable('finance_user_ai_settings')) {
                $encrypted = DB::table('finance_user_ai_settings')
                    ->where('user_sub', $userSub)
                    ->value('google_api_key_encrypted');

                if (! empty($encrypted)) {
                    $decrypted = Crypt::decryptString((string) $encrypted);
                    if (trim($decrypted) !== '') {
                        return trim($decrypted);
                    }
                }
            }
        } catch (Throwable) {
            // Invalid payload or unmigrated table
        }

        return '';
    }

    public function hasApiKey(string $userSub): bool
    {
        return $this->getApiKey($userSub) !== '';
    }

    public function getMaskedApiKey(string $userSub): ?string
    {
        $key = $this->getApiKey($userSub);
        if ($key === '') {
            return null;
        }
        $len = strlen($key);
        if ($len <= 10) {
            return str_repeat('•', $len);
        }

        return substr($key, 0, 6).str_repeat('•', 8).substr($key, -4);
    }

    public function setApiKey(string $userSub, ?string $apiKey, ?string $defaultModel = null): void
    {
        $trimmed = trim((string) $apiKey);
        $now = Carbon::now();
        $existing = DB::table('finance_user_ai_settings')->where('user_sub', $userSub)->first();
        $resolvedModel = $this->isValidModel($defaultModel)
            ? $defaultModel
            : ($existing && $this->isValidModel($existing->default_model) ? $existing->default_model : self::DEFAULT_MODEL);

        $encryptedValue = $trimmed === '' ? null : Crypt::encryptString($trimmed);

        if ($existing) {
            DB::table('finance_user_ai_settings')->where('id', $existing->id)->update([
                'google_api_key_encrypted' => $encryptedValue,
                'default_model' => $resolvedModel,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('finance_user_ai_settings')->insert([
                'user_sub' => $userSub,
                'google_api_key_encrypted' => $encryptedValue,
                'default_model' => $resolvedModel,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function getUserSettingsSummary(string $userSub): array
    {
        return [
            'configured' => $this->hasApiKey($userSub),
            'maskedKey' => $this->getMaskedApiKey($userSub),
            'defaultModel' => $this->getDefaultModel($userSub),
            'models' => $this->availableModels(),
        ];
    }

    public function testConnection(string $userSub, ?string $preferredModel = null, ?string $overrideApiKey = null): array
    {
        $override = trim((string) $overrideApiKey);
        $apiKey = $override !== '' ? $override : $this->getApiKey($userSub);

        if ($apiKey === '') {
            return [
                'ok' => false,
                'model' => null,
                'message' => 'Google API Key kamu belum diatur. Tempel API Key Google AI Studio milikmu terlebih dahulu.',
            ];
        }

        $model = $this->isValidModel($preferredModel) ? $preferredModel : $this->getDefaultModel($userSub);
        $t0 = microtime(true);

        try {
            $res = $this->callGemini($apiKey, $model, 'Balas JSON valid: {"ok":true,"status":"terhubung"}', 64, 12);
            $latencyMs = (int) round((microtime(true) - $t0) * 1000);

            if (! $res->successful()) {
                $errJson = $res->json();
                $errMsg = $errJson['error']['message'] ?? ("HTTP {$res->status()}");

                return [
                    'ok' => false,
                    'model' => $model,
                    'message' => "Gagal terhubung ke Google Gemini ({$model}): {$errMsg}",
                ];
            }

            return [
                'ok' => true,
                'model' => $model,
                'message' => "Koneksi Google Gemini ({$model}) berhasil! Latensi: {$latencyMs} ms.",
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'model' => $model,
                'message' => 'Gagal menghubungi Google Gemini API: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Analyze monthly salary + recurring commitments + month-specific debts + actual spending
     * using the user's own encrypted Google Gemini API Key.
     */
    public function analyzeMonthlyPlan(string $userSub, string $month, array $context, ?string $preferredModel = null): array
    {
        $apiKey = $this->getApiKey($userSub);
        if ($apiKey === '') {
            throw new RuntimeException('Google Gemini API Key akun kamu belum disetel. Klik "Atur API Key Gemini Saya" untuk memasukkan API key milikmu.');
        }

        $primaryModel = $this->isValidModel($preferredModel) ? $preferredModel : $this->getDefaultModel($userSub);
        $fallbackChain = array_values(array_unique(array_merge(
            [$primaryModel],
            array_keys(self::ALLOWED_MODELS),
            array_keys(self::INTERNAL_FALLBACK_ALIASES)
        )));

        $prompt = $this->buildPrompt($month, $context);
        $startedAt = microtime(true);
        $attemptErrors = [];
        $sawHighDemand = false;

        foreach ($fallbackChain as $index => $modelId) {
            $elapsed = microtime(true) - $startedAt;
            if ($index > 0 && $elapsed >= 22.0) {
                $attemptErrors[] = "{$modelId}: dilewati (batas waktu server cPanel mendekati 25s)";
                break;
            }

            try {
                $res = $this->callGemini($apiKey, $modelId, $prompt, 2048, 12);

                if ($res->status() === 401 || $res->status() === 403 || $res->status() === 400) {
                    $errMsg = $res->json('error.message') ?? 'API Key Google tidak valid atau permintaan ditolak.';
                    if ($res->status() === 401 || $res->status() === 403 || stripos($errMsg, 'API key') !== false) {
                        throw new RuntimeException("Autentikasi Google Gemini gagal ({$res->status()}): {$errMsg}");
                    }
                    $attemptErrors[] = "{$modelId}: HTTP {$res->status()} ({$errMsg})";

                    continue;
                }

                if ($res->status() === 429 || $res->status() >= 500) {
                    $errMsg = (string) ($res->json('error.message') ?? "HTTP {$res->status()}");
                    if (stripos($errMsg, 'high demand') !== false || $res->status() === 503) {
                        $sawHighDemand = true;
                        $attemptErrors[] = "{$modelId}: sibuk (503 High Demand)";
                    } else {
                        $attemptErrors[] = "{$modelId}: HTTP {$res->status()} ({$errMsg})";
                    }

                    if (microtime(true) - $startedAt < 14.0) {
                        usleep(350000);
                    }

                    continue;
                }

                if (! $res->successful()) {
                    $errMsg = (string) ($res->json('error.message') ?? "HTTP {$res->status()}");
                    $attemptErrors[] = "{$modelId}: {$errMsg}";

                    continue;
                }

                $rawText = $this->extractCandidateText($res->json());
                $parsed = $this->parseAiJson($rawText);
                if (! is_array($parsed)) {
                    $attemptErrors[] = "{$modelId}: format JSON respons tidak valid";

                    continue;
                }

                return $this->normalizeAnalysisOutput($parsed, $context, $modelId, $index > 0);
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'timed out') !== false || stripos($msg, 'Failed to open stream') !== false) {
                    $sawHighDemand = true;
                    $attemptErrors[] = "{$modelId}: timeout / koneksi terputus";
                } else {
                    $attemptErrors[] = "{$modelId}: {$msg}";
                }
            }
        }

        $detail = implode(' | ', $attemptErrors);
        if ($sawHighDemand) {
            throw new RuntimeException(
                "Server Google Gemini sedang padat (503 High Demand / Timeout) di semua model cadangan. Tunggu beberapa detik lalu coba klik Analisa lagi. Detail: {$detail}"
            );
        }

        throw new RuntimeException("Seluruh model Google Gemini gagal merespons. Detail: {$detail}");
    }

    private function callGemini(string $apiKey, string $modelId, string $prompt, int $maxTokens, int $timeoutSeconds = 12)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent";

        $pending = Http::timeout($timeoutSeconds)->connectTimeout(6);

        if (function_exists('curl_exec')) {
            // Use single-request CurlHandler so cPanel servers with disabled curl_multi_exec
            // do not fall back to PHP fopen() StreamHandler.
            $pending->setHandler(new CurlHandler());
            $curlOptions = [];
            if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
                $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
            if ($curlOptions !== []) {
                $pending->withOptions(['curl' => $curlOptions]);
            }
        } else {
            $pending->withOptions([
                'version' => '1.1',
                'http_errors' => false,
                'verify' => true,
            ]);
        }

        return $pending
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($url, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $prompt]],
                    ],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => 0.35,
                    'maxOutputTokens' => $maxTokens,
                ],
            ]);
    }

    private function extractCandidateText(?array $responseJson): string
    {
        $parts = (array) data_get($responseJson, 'candidates.0.content.parts', []);
        foreach ($parts as $part) {
            if (! empty($part['thought'])) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text']) && trim($part['text']) !== '') {
                return $part['text'];
            }
        }

        return (string) data_get($responseJson, 'candidates.0.content.parts.0.text', '');
    }

    private function buildPrompt(string $month, array $context): string
    {
        $jsonContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
Kamu adalah Mochi AI Financial Planner, penasihat keuangan pribadi yang praktis, ramah, dan realistis untuk pengguna di Indonesia (mata uang IDR / Rupiah).

Berikut data keuangan pengguna untuk bulan {$month}:
{$jsonContext}

Tugasmu:
1. Analisa gaji bulanan (`monthlySalary`) dan pemasukan/saldo dompet terhadap seluruh kewajiban bulan {$month}:
   - Langganan bulanan aktif (`subscriptionsMonthly`)
   - Cicilan rutin aktif (`installmentsMonthly`)
   - Hutang khusus yang jatuh tempo di bulan {$month} (`debtsForSelectedMonth`)
   - Pengeluaran aktual per kategori sejauh ini (`spentByCategoryThisMonth`) dan target celengan (`savingGoals`).
2. Perhitungkan sisa dana setelah seluruh kewajiban wajib (langganan + cicilan + hutang bulan ini) untuk menyusun rekomendasi batas anggaran bulanan per kategori belanja (`expenseCategories`) agar pengguna tetap bisa memenuhi kebutuhan pokok, punya ruang fleksibel, dan bisa menabung.
3. Pertimbangkan `strategyNotes` dari pengguna bila diisi.
4. Pastikan total `recommendedLimit` seluruh kategori belanja masuk akal (jangan melebihi sisa dana bebas, atau jika gaji defisit terhadap kewajiban, beri peringatan jelas dan sarankan anggaran hemat minimal).
5. Gunakan HANYA `categoryId` yang persis ada pada `expenseCategories` di input.

Balas STRICT JSON valid (tanpa markdown) dengan struktur persis seperti ini:
{
  "summary": "Ringkasan analisa 2-4 kalimat dalam bahasa Indonesia yang jelas dan menyebutkan angka Rupiah kunci.",
  "healthStatus": "sehat | waspada | defisit",
  "allocationRatio": {
    "needsPct": 50,
    "wantsPct": 20,
    "savingsAndDebtPct": 30
  },
  "actionPlan": [
    "Langkah konkret 1...",
    "Langkah konkret 2...",
    "Langkah konkret 3..."
  ],
  "recommendedBudgets": [
    {
      "categoryId": "cat-food",
      "categoryName": "Makanan & Minuman",
      "recommendedLimit": 1500000,
      "reason": "Alasan singkat alokasi kategori ini..."
    }
  ]
}
PROMPT;
    }

    private function parseAiJson(string $rawText): ?array
    {
        $clean = trim($rawText);
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $clean) ?? $clean;
        $decoded = json_decode(trim($clean), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $clean, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function normalizeAnalysisOutput(array $parsed, array $context, string $modelUsed, bool $fallbackUsed): array
    {
        $validCategories = collect($context['expenseCategories'] ?? [])->keyBy('id');

        $health = strtolower((string) ($parsed['healthStatus'] ?? 'waspada'));
        if (! in_array($health, ['sehat', 'waspada', 'defisit'], true)) {
            $health = 'waspada';
        }

        $ratio = is_array($parsed['allocationRatio'] ?? null) ? $parsed['allocationRatio'] : [];
        $needsPct = max(0, min(100, (int) ($ratio['needsPct'] ?? 50)));
        $wantsPct = max(0, min(100, (int) ($ratio['wantsPct'] ?? 20)));
        $savingsPct = max(0, min(100, (int) ($ratio['savingsAndDebtPct'] ?? 30)));

        $actions = collect($parsed['actionPlan'] ?? [])
            ->filter(fn ($a) => is_string($a) && trim($a) !== '')
            ->map(fn ($a) => trim($a))
            ->values()
            ->all();

        $recommendations = [];
        $seenIds = [];
        foreach ((array) ($parsed['recommendedBudgets'] ?? []) as $rec) {
            if (! is_array($rec) || empty($rec['categoryId'])) {
                continue;
            }
            $catId = (string) $rec['categoryId'];
            $cat = $validCategories->get($catId);
            if (! $cat || isset($seenIds[$catId])) {
                continue;
            }
            $seenIds[$catId] = true;
            $limit = max(0, (int) round((float) ($rec['recommendedLimit'] ?? 0)));
            $recommendations[] = [
                'categoryId' => $catId,
                'categoryName' => (string) ($cat['name'] ?? $rec['categoryName'] ?? $catId),
                'recommendedLimit' => $limit,
                'reason' => trim((string) ($rec['reason'] ?? 'Rekomendasi alokasi bulanan.')),
            ];
        }

        $displayName = self::ALLOWED_MODELS[$modelUsed]['name']
            ?? self::INTERNAL_FALLBACK_ALIASES[$modelUsed]
            ?? $modelUsed;

        return [
            'summary' => trim((string) ($parsed['summary'] ?? 'Analisa rencana keuangan selesai dibuat.')),
            'healthStatus' => $health,
            'allocationRatio' => [
                'needsPct' => $needsPct,
                'wantsPct' => $wantsPct,
                'savingsAndDebtPct' => $savingsPct,
            ],
            'actionPlan' => $actions,
            'recommendedBudgets' => $recommendations,
            'modelUsed' => $modelUsed,
            'modelDisplayName' => $displayName,
            'fallbackUsed' => $fallbackUsed,
            'generatedAt' => Carbon::now()->toIso8601String(),
        ];
    }
}
