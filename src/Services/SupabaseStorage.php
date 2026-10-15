<?php
declare(strict_types=1);
namespace App\Services;

use App\Http\Client;

/** Service key နဲ့ private "receipts" bucket ကို server ကနေ သုံး */
final class SupabaseStorage
{
    private string $base;
    private string $key;

    public function __construct()
    {
        $this->base = rtrim((string)getenv('SUPABASE_URL'), '/') . '/storage/v1';
        $this->key  = (string)getenv('SUPABASE_SERVICE_KEY');
    }

    private function h(array $extra = []): array
    {
        return array_merge(["apikey: {$this->key}", "Authorization: Bearer {$this->key}"], $extra);
    }

    private static function enc(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public function upload(string $path, string $bytes, string $mime): void
    {
        [$c, $r] = Client::request('POST', "{$this->base}/object/receipts/" . self::enc($path),
            $this->h(["Content-Type: $mime"]), $bytes);
        if ($c >= 300) throw new \RuntimeException("Storage upload failed: $c $r");
    }

    public function download(string $path): string
    {
        [$c, $r] = Client::request('GET', "{$this->base}/object/receipts/" . self::enc($path), $this->h());
        if ($c >= 300) throw new \RuntimeException("Storage download failed: $c");
        return $r;
    }

    public function delete(string $path): void
    {
        Client::request('DELETE', "{$this->base}/object/receipts/" . self::enc($path), $this->h());
    }

    public function signedUrl(string $path, int $ttl = 3600): string
    {
        [$c, $r] = Client::request('POST', "{$this->base}/object/sign/receipts/" . self::enc($path),
            $this->h(['Content-Type: application/json']), json_encode(['expiresIn' => $ttl]));
        $j = json_decode($r, true);
        if ($c >= 300 || empty($j['signedURL'])) throw new \RuntimeException("Sign failed: $c $r");
        return $this->base . $j['signedURL'];
    }
}
