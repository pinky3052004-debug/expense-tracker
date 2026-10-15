<?php
declare(strict_types=1);
namespace App\Services;

use App\Http\Client;

final class GeminiOcr
{
    /**
     * ဘောင်ချာပုံ → draft array (အမြဲ user confirm လုပ်ခိုင်းရမယ်)
     * @return array{merchant:?string, amount:?float, currency:?string, spent_at:string, category:?string, items:array}
     */
    public function scan(string $bytes, string $mime, array $categoryNames = []): array
    {
        $model = getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash';
        $cats  = $categoryNames ? implode(', ', $categoryNames) : 'Food, Transport, Shopping, Bills, Health, Other';

        $prompt = "You are reading a shop receipt (Burmese, English or mixed). Extract:\n"
            . "- merchant: shop name\n"
            . "- total: the FINAL amount paid (after tax/discount), a plain number without separators\n"
            . "- currency: ISO code (MMK if kyat/Ks, THB, SGD, USD...). null if unclear\n"
            . "- date: purchase date as YYYY-MM-DD, null if not visible\n"
            . "- category: exactly one of [$cats]\n"
            . "- items: line items with name and price\n"
            . "Never guess numbers that are not visible; use null instead.";

        $payload = [
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'merchant' => ['type' => 'STRING', 'nullable' => true],
                        'total'    => ['type' => 'NUMBER', 'nullable' => true],
                        'currency' => ['type' => 'STRING', 'nullable' => true],
                        'date'     => ['type' => 'STRING', 'nullable' => true],
                        'category' => ['type' => 'STRING', 'nullable' => true],
                        'items'    => ['type' => 'ARRAY', 'items' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'name'  => ['type' => 'STRING'],
                                'price' => ['type' => 'NUMBER', 'nullable' => true],
                            ],
                            'required' => ['name'],
                        ]],
                    ],
                ],
            ],
        ];

        [$code, $res] = Client::request(
            'POST',
            "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent",
            ['Content-Type: application/json', 'x-goog-api-key: ' . getenv('GEMINI_API_KEY')],
            json_encode($payload),
            60
        );
        if ($code !== 200) throw new \RuntimeException("Gemini error $code: " . substr($res, 0, 300));

        $text = json_decode($res, true)['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $d = json_decode($text, true);
        if (!is_array($d)) throw new \RuntimeException('Gemini returned invalid JSON');

        $amount = isset($d['total']) && is_numeric($d['total']) && $d['total'] > 0 ? (float)$d['total'] : null;
        $cur = isset($d['currency']) ? strtoupper(trim((string)$d['currency'])) : '';

        return [
            'merchant' => $d['merchant'] ?? null,
            'amount'   => $amount,
            'currency' => preg_match('/^[A-Z]{3}$/', $cur) ? $cur : null,
            'spent_at' => self::date($d['date'] ?? null),
            'category' => $d['category'] ?? null,
            'items'    => is_array($d['items'] ?? null) ? $d['items'] : [],
        ];
    }

    private static function date(?string $s): string
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $now = new \DateTimeImmutable('now', $tz);
        if ($s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            try {
                $d = new \DateTimeImmutable("$s 12:00:00", $tz);
                // အနာဂတ်ရက် / ၁ နှစ်ထက်ပိုဟောင်းရင် မယုံဘူး
                if ($d <= $now->modify('+1 day') && $d >= $now->modify('-1 year')) return $d->format('c');
            } catch (\Throwable) {}
        }
        return $now->format('c');
    }
}
