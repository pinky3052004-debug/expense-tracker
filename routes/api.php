<?php
declare(strict_types=1);

use App\Auth\SupabaseAuth;
use App\Http\{Request, Response};
use App\Services\{CategoryService, ExpenseService, GeminiOcr, LinkService, SupabaseStorage};
use App\Util;

/** @var \App\Http\Router $router */

$auth = function (Request $r): string {
    $uid = SupabaseAuth::userId($r->bearer());
    if (!$uid) Response::json(['error' => 'Unauthorized'], 401);
    return $uid;
};
$expenses   = new ExpenseService();
$categories = new CategoryService();
$links      = new LinkService();

// ---- Expenses
$router->add('GET', '/api/expenses', fn(Request $r) =>
    Response::json($expenses->list($auth($r), $r->query)));

$router->add('POST', '/api/expenses', fn(Request $r) =>
    Response::json($expenses->create($auth($r), $r->body), 201));

$router->add('PATCH', '/api/expenses/{id}', function (Request $r) use ($auth, $expenses) {
    $row = $expenses->update($auth($r), $r->params['id'], $r->body);
    $row ? Response::json($row) : Response::json(['error' => 'Not found'], 404);
});

$router->add('DELETE', '/api/expenses/{id}', function (Request $r) use ($auth, $expenses) {
    $expenses->delete($auth($r), $r->params['id'])
        ? Response::json(['deleted' => true])
        : Response::json(['error' => 'Not found'], 404);
});

$router->add('GET', '/api/summary', fn(Request $r) =>
    Response::json($expenses->summary($auth($r), $r->query['month'] ?? date('Y-m'))));

// ---- Categories
$router->add('GET', '/api/categories', fn(Request $r) =>
    Response::json($categories->list($auth($r))));

$router->add('POST', '/api/categories', fn(Request $r) =>
    Response::json($categories->create($auth($r), (string)($r->body['name'] ?? ''), $r->body['icon'] ?? null), 201));

$router->add('DELETE', '/api/categories/{id}', function (Request $r) use ($auth, $categories) {
    $categories->delete($auth($r), $r->params['id'])
        ? Response::json(['deleted' => true])
        : Response::json(['error' => 'Not found'], 404);
});

// ---- Receipts: multipart "image" → Storage သို့တင် → Gemini OCR → draft ပြန်
//      Client က draft ကို user ကိုပြ/ပြင်ခိုင်းပြီး POST /api/expenses (source:"ocr", receipt_path) နဲ့ သိမ်း
$router->add('POST', '/api/receipts/scan', function (Request $r) use ($auth, $categories) {
    $uid = $auth($r);
    $f = $r->file('image');
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) Response::json(['error' => 'image upload failed'], 400);
    if ($f['size'] > 5 * 1024 * 1024)          Response::json(['error' => 'image too large (max 5MB)'], 413);

    $mime = mime_content_type($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) Response::json(['error' => 'only jpeg/png/webp allowed'], 415);

    $bytes = file_get_contents($f['tmp_name']);
    $path  = "$uid/" . Util::uuid() . ".$ext";
    (new SupabaseStorage())->upload($path, $bytes, $mime);

    $cats = $categories->list($uid);
    try {
        $draft = (new GeminiOcr())->scan($bytes, $mime, array_column($cats, 'name'));
        $draft['category_id'] = CategoryService::matchId($cats, $draft['category'] ?? null);
    } catch (\Throwable $e) {
        error_log((string)$e);
        $draft = null;   // OCR မရရင်လည်း ပုံသိမ်းပြီးသား၊ user က ကိုယ်တိုင်ဖြည့်လို့ရ
    }
    Response::json(['receipt_path' => $path, 'draft' => $draft]);
});

$router->add('GET', '/api/receipts/url', function (Request $r) use ($auth) {
    $uid  = $auth($r);
    $path = (string)($r->query['path'] ?? '');
    if (!str_starts_with($path, "$uid/") || str_contains($path, '..')) Response::json(['error' => 'Forbidden'], 403);
    Response::json(['url' => (new SupabaseStorage())->signedUrl($path, 3600)]);
});

// ---- Telegram linking
$router->add('GET',    '/api/telegram/status',    fn(Request $r) => Response::json(['linked' => $links->isLinked($auth($r))]));
$router->add('POST',   '/api/telegram/link-code', fn(Request $r) => Response::json($links->createCode($auth($r))));
$router->add('DELETE', '/api/telegram/link',      function (Request $r) use ($auth, $links) {
    $links->unlink($auth($r));
    Response::json(['unlinked' => true]);
});

// ---- Profile (default currency + Telegram link state)
$router->add('GET', '/api/profile', function (Request $r) use ($auth) {
    $st = \App\Database::pdo()->prepare('SELECT default_currency, (telegram_id IS NOT NULL) AS telegram_linked FROM profiles WHERE id = :u');
    $st->execute(['u' => $auth($r)]);
    $row = $st->fetch();
    if (!$row) Response::json(['error' => 'Profile not found'], 404);
    $row['telegram_linked'] = (bool)$row['telegram_linked'];
    Response::json($row);
});

$router->add('PATCH', '/api/profile', function (Request $r) use ($auth) {
    $uid = $auth($r);
    $cur = strtoupper(trim((string)($r->body['default_currency'] ?? '')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) throw new \InvalidArgumentException('default_currency must be a 3-letter code');
    \App\Database::pdo()->prepare('UPDATE profiles SET default_currency = :c WHERE id = :u')->execute(['c' => $cur, 'u' => $uid]);
    $st = \App\Database::pdo()->prepare('SELECT default_currency, (telegram_id IS NOT NULL) AS telegram_linked FROM profiles WHERE id = :u');
    $st->execute(['u' => $uid]);
    $row = $st->fetch();
    $row['telegram_linked'] = (bool)$row['telegram_linked'];
    Response::json($row);
});
