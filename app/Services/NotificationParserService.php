<?php

namespace App\Services;

class NotificationParserService
{
    public const DEFAULT_CATEGORIES = [
        ['id' => 'cat-food', 'name' => 'Makanan & Minuman', 'type' => 'expense', 'icon' => 'Utensils', 'color' => '#f59e0b'],
        ['id' => 'cat-transport', 'name' => 'Transportasi', 'type' => 'expense', 'icon' => 'Car', 'color' => '#3b82f6'],
        ['id' => 'cat-shopping', 'name' => 'Belanja & Groceries', 'type' => 'expense', 'icon' => 'ShoppingBag', 'color' => '#ec4899'],
        ['id' => 'cat-bills', 'name' => 'Tagihan & Utilitas', 'type' => 'expense', 'icon' => 'Receipt', 'color' => '#8b5cf6'],
        ['id' => 'cat-entertainment', 'name' => 'Hiburan & Hobi', 'type' => 'expense', 'icon' => 'Film', 'color' => '#06b6d4'],
        ['id' => 'cat-health', 'name' => 'Kesehatan & Medis', 'type' => 'expense', 'icon' => 'HeartPulse', 'color' => '#ef4444'],
        ['id' => 'cat-salary', 'name' => 'Gaji & Pendapatan', 'type' => 'income', 'icon' => 'Briefcase', 'color' => '#10b981'],
        ['id' => 'cat-freelance', 'name' => 'Freelance & Side Job', 'type' => 'income', 'icon' => 'Laptop', 'color' => '#14b8a6'],
        ['id' => 'cat-invest', 'name' => 'Dividen & Investasi', 'type' => 'income', 'icon' => 'TrendingUp', 'color' => '#6366f1'],
        ['id' => 'cat-other-income', 'name' => 'Pemasukan Lainnya', 'type' => 'income', 'icon' => 'PlusCircle', 'color' => '#84cc16'],
        ['id' => 'cat-transfer', 'name' => 'Transfer Antar Akun', 'type' => 'expense', 'icon' => 'ArrowLeftRight', 'color' => '#64748b'],
    ];

    /**
     * Parse raw Indonesian bank/e-wallet SMS or push notification text.
     *
     * @return array{source: string, amount: float, type: string, merchant: string, notes: string, suggestedCategory: string}
     */
    public function parse(string $rawText, ?string $providedSource = null): array
    {
        $text = trim($rawText);
        $lower = mb_strtolower($text);

        $source = 'MANUAL';
        if ($providedSource && trim($providedSource) !== '') {
            $source = strtoupper(trim($providedSource));
        } elseif (str_contains($lower, 'bca') || str_contains($lower, 'm-bca') || str_contains($lower, 'klikbca')) {
            $source = 'BCA';
        } elseif (str_contains($lower, 'mandiri') || str_contains($lower, 'livin')) {
            $source = 'MANDIRI';
        } elseif (str_contains($lower, 'bri') || str_contains($lower, 'brimo')) {
            $source = 'BRI';
        } elseif (str_contains($lower, 'bni')) {
            $source = 'BNI';
        } elseif (str_contains($lower, 'gopay') || str_contains($lower, 'gojek')) {
            $source = 'GOPAY';
        } elseif (str_contains($lower, 'ovo')) {
            $source = 'OVO';
        } elseif (str_contains($lower, 'shopeepay') || str_contains($lower, 'shopee')) {
            $source = 'SHOPEEPAY';
        } elseif (str_contains($lower, 'dana')) {
            $source = 'DANA';
        }

        $type = 'expense';
        foreach (['masuk', 'kredit', 'diterima', 'cr ', 'transfer dari'] as $kw) {
            if (str_contains($lower, $kw)) {
                $type = 'income';
                break;
            }
        }

        $amount = 0.0;
        if (preg_match('/(?:rp\.?|idr)\s?([0-9]{1,3}(?:[.,][0-9]{3})*(?:[.,][0-9]{2})?)/i', $text, $m)) {
            $clean = preg_replace('/\s+/', '', $m[1]);
            if (str_contains($clean, '.') && str_contains($clean, ',')) {
                $clean = str_replace(',', '.', str_replace('.', '', $clean));
            } elseif (str_contains($clean, '.')) {
                $clean = str_replace('.', '', $clean);
            } elseif (str_contains($clean, ',')) {
                $clean = str_replace(',', '', $clean);
            }
            $amount = (float) $clean;
        }

        $merchant = '';
        if (preg_match('/(?:ke|di|kepada|merchant:?)\s+([A-Za-z0-9\s&\'\-]{3,40})/i', $text, $m)) {
            $merchant = trim((string) preg_replace('/\s+(ref|tgl|jam|pada|nomor).*$/i', '', trim($m[1])));
        }

        $suggestedCategory = $type === 'income' ? 'cat-salary' : 'cat-food';
        if (preg_match('/kopi|makan|resto|cafe|chatime|starbucks/i', $lower)) {
            $suggestedCategory = 'cat-food';
        } elseif (preg_match('/grab|gojek|maxim|bensin|pertamina|toll/i', $lower)) {
            $suggestedCategory = 'cat-transport';
        } elseif (preg_match('/alfa|indo|supermarket|tokopedia|shopee|belanja/i', $lower)) {
            $suggestedCategory = 'cat-shopping';
        } elseif (preg_match('/listrik|pln|wifi|indihome|pdam|bpjs/i', $lower)) {
            $suggestedCategory = 'cat-bills';
        }

        return [
            'source' => $source,
            'amount' => $amount,
            'type' => $type,
            'merchant' => $merchant !== '' ? $merchant : ($source !== 'MANUAL' ? "{$source} Transaction" : 'Transaksi'),
            'notes' => "Otomatis via {$source}: ".mb_substr($text, 0, 120),
            'suggestedCategory' => $suggestedCategory,
        ];
    }
}
