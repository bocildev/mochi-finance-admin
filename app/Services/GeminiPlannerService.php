<?php

namespace App\Services;

use Carbon\Carbon;
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
     * Allowed Google Gemini models (100% Google-only policy, matching Google AI Studio quotas).
     */
    public const ALLOWED_MODELS = [
        'gemini-3.5-flash-lite' => [
            'id' => 'gemini-3.5-flash-lite',
            'name' => 'Gemini 3.5 Flash Lite',
            'badge' => 'REKOMENDASI · 15 RPM / 500 RPD',
            'description' => 'Cepat, hemat kuota harian (500 RPD), cocok untuk analisa anggaran & gaji harian.',
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

    public function availableModels(): array
    {
        return array_values(self::ALLOWED_MODELS);
    }

    public function isValidModel(?string $model): bool
    {
        return $model !== null && array_key_exists($model, self::ALLOWED_MODELS);
    }

    public function getDefaultModel(): string
    {
        try {
            if (Schema::hasTable('system_settings')) {
                $val = DB::table('system_settings')->where('key', 'google_gemini_default_model')->value('value');
                if ($this->isValidModel($val)) {
                    return $val;
                }
            }
        } catch (Throwable) {
            // Ignore when DB/settings table not yet migrated
        }

        $envModel = (string) env('GEMINI_DEFAULT_MODEL', self::DEFAULT_MODEL);

        return $this->isValidModel($envModel) ? $envModel : self::DEFAULT_MODEL;
    }

    public function setDefaultModel(string $model): void
    {
        if (! $this->isValidModel($model)) {
            throw new RuntimeException('Model AI Google tidak valid.');
        }

        $now = Carbon::now();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'google_gemini_default_model'],
            [
                'value' => $model,
                'is_encrypted' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function getApiKey(): string
    {
        try {
            if (Schema::hasTable('system_settings')) {
                $row = DB::table('system_settings')->where('key', 'google_gemini_api_key')->first();
                if ($row && ! empty($row->value)) {
                    $decrypted = $row->is_encrypted
                        ? Crypt::decryptString($row->value)
                        : (string) $row->value;
                    if (trim($decrypted) !== '') {
                        return trim($decrypted);
                    }
                }
            }
        } catch (Throwable) {
            // Fallback to env if decryption fails or DB not ready
        }

        return trim((string) config('services.gemini.api_key', env('GEMINI_API_KEY', '')));
    }

    public function hasApiKey(): bool
    {
        return $this->getApiKey() !== '';
    }

    public function getApiKeySource(): string
    {
        try {
            if (Schema::hasTable('system_settings')) {
                $row = DB::table('system_settings')->where('key', 'google_gemini_api_key')->first();
                if ($row && ! empty($row->value)) {
                    return 'database';
                }
            }
        } catch (Throwable) {
            // ignore
        }

        if (trim((string) config('services.gemini.api_key', env('GEMINI_API_KEY', ''))) !== '') {
            return 'env';
        }

        return 'none';
    }

    public function getMaskedApiKey(): ?string
    {
        $key = $this->getApiKey();
        if ($key === '') {
            return null;
        }
        $len = strlen($key);
        if ($len <= 10) {
            return str_repeat('•', $len);
        }

        return substr($key, 0, 6).str_repeat('•', 8).substr($key, -4);
    }

    public function setApiKey(?string $apiKey): void
    {
        $trimmed = trim((string) $apiKey);
        if ($trimmed === '') {
            DB::table('system_settings')->where('key', 'google_gemini_api_key')->delete();

            return;
        }

        $now = Carbon::now();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'google_gemini_api_key'],
            [
                'value' => Crypt::encryptString($trimmed),
                'is_encrypted' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function testConnection(?string $preferredModel = null): array
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            return [
                'ok' => false,
                'model' => null,
                'message' => 'Google API Key belum dikonfigurasi. Simpan key terlebih dahulu di form di bawah atau via GEMINI_API_KEY di .env.',
            ];
        }

        $model = $this->isValidModel($preferredModel) ? $preferredModel : $this->getDefaultModel();
        $t0 = microtime(true);

        try {
            $res = $this->callGemini($apiKey, $model, 'Balas JSON valid: {"ok":true,"status":"terhubung"}', 64);
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
     * and return structured flexible budget recommendations.
     */
    public function analyzeMonthlyPlan(string $month, array $context, ?string $preferredModel = null): array
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            throw new RuntimeException('Google Gemini API Key belum disetel oleh Admin di Backoffice (menu Pengaturan AI Gemini).');
        }

        $primaryModel = $this->isValidModel($preferredModel) ? $preferredModel : $this->getDefaultModel();
        $fallbackChain = array_values(array_unique(array_merge([$primaryModel], array_keys(self::ALLOWED_MODELS))));

        $prompt = $this->buildPrompt($month, $context);
        $lastError = 'Tidak ada respons dari Google Gemini API.';

        foreach ($fallbackChain as $index => $modelId) {
            try {
                $res = $this->callGemini($apiKey, $modelId, $prompt, 2048);

                if ($res->status() === 401 || $res->status() === 403) {
                    $errMsg = $res->json('error.message') ?? 'API Key Google tidak valid atau tidak memiliki izin.';
                    throw new RuntimeException("Autentikasi Google Gemini gagal ({$res->status()}): {$errMsg}");
                }

                if ($res->status() === 429 || $res->status() >= 500) {
                    $lastError = $res->json('error.message') ?? "Model {$modelId} sedang limit/sibuk (HTTP {$res->status()}).";

                    continue;
                }

                if (! $res->successful()) {
                    $lastError = $res->json('error.message') ?? "HTTP {$res->status()} dari {$modelId}";

                    continue;
                }

                $rawText = (string) data_get($res->json(), 'candidates.0.content.parts.0.text', '');
                $parsed = $this->parseAiJson($rawText);
                if (! is_array($parsed)) {
                    $lastError = "Format JSON dari {$modelId} tidak valid.";

                    continue;
                }

                return $this->normalizeAnalysisOutput($parsed, $context, $modelId, $index > 0);
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException("Seluruh model Google Gemini gagal merespons: {$lastError}");
    }

    private function callGemini(string $apiKey, string $modelId, string $prompt, int $maxTokens)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent";

        return Http::timeout(25)
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
   - Pengeluaran aktual per kategori sejauh ini (`spentByCategory`) dan target celengan (`savingGoals`).
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
            'modelDisplayName' => self::ALLOWED_MODELS[$modelUsed]['name'] ?? $modelUsed,
            'fallbackUsed' => $fallbackUsed,
            'generatedAt' => Carbon::now()->toIso8601String(),
        ];
    }
}
