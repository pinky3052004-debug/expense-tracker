<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set(getenv('APP_TZ') ?: 'Asia/Yangon');

use App\Http\{Request, Response, Router};

$origin = getenv('CORS_ORIGIN') ?: '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$router = new Router();
$router->add('GET', '/health', fn() => Response::json(['ok' => true]));

$role = getenv('APP_ROLE') ?: 'api';
require __DIR__ . "/../routes/{$role}.php";   // routes/api.php | routes/bot.php

try {
    $router->dispatch(new Request());
} catch (\InvalidArgumentException $e) {
    Response::json(['error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    error_log((string)$e);
    Response::json(['error' => 'Server error'], 500);
}
