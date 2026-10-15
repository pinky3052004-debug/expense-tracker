<?php
declare(strict_types=1);

use App\Bot\BotHandler;
use App\Http\{Request, Response};

/** @var \App\Http\Router $router */

$router->add('POST', '/telegram/webhook', function (Request $r) {
    if (!hash_equals((string)getenv('TELEGRAM_WEBHOOK_SECRET'), (string)$r->header('X-Telegram-Bot-Api-Secret-Token'))) {
        Response::json(['error' => 'Forbidden'], 403);
    }

    // Telegram ကို ချက်ချင်း 200 ပြန်ပြီးမှ OCR စတဲ့ အလုပ်ကြီးတွေ ဆက်လုပ် (retry / duplicate မဖြစ်အောင်)
    ignore_user_abort(true);
    set_time_limit(90);
    $out = json_encode(['ok' => true]);
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($out));
    header('Connection: close');
    echo $out;
    while (ob_get_level() > 0) ob_end_flush();
    flush();

    (new BotHandler())->handle($r->body);
    exit;
});
