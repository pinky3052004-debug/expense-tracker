<?php
declare(strict_types=1);
namespace App\Auth;

use Firebase\JWT\{JWT, Key};

final class SupabaseAuth
{
    /** Token မှန်ရင် user uuid ပြန်၊ မမှန်ရင် null */
    public static function userId(?string $token): ?string
    {
        if (!$token) return null;

        // Legacy HS256 secret ရှိရင် local verify (မြန်)
        $secret = getenv('SUPABASE_JWT_SECRET');
        if ($secret) {
            try {
                $p = JWT::decode($token, new Key($secret, 'HS256'));
                return $p->sub ?? null;
            } catch (\Throwable) { return null; }
        }

        // မဟုတ်ရင် (asymmetric signing keys) Supabase Auth ကို တိုက်ရိုက်မေး
        $ch = curl_init(rtrim(getenv('SUPABASE_URL'), '/') . '/auth/v1/user');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . getenv('SUPABASE_ANON_KEY'),
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200 ? (json_decode($res, true)['id'] ?? null) : null;
    }
}
