<?php
declare(strict_types=1);
namespace App\Http;

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body;
    public array $params = [];

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->path   = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/') ?: '/';
        $this->query  = $_GET;
        $raw = file_get_contents('php://input');
        $isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json');
        $this->body = ($isJson && $raw) ? (json_decode($raw, true) ?? []) : $_POST;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function bearer(): ?string
    {
        $h = $this->header('Authorization');
        return ($h && preg_match('/^Bearer\s+(.+)$/i', $h, $m)) ? $m[1] : null;
    }

    public function file(string $name): ?array
    {
        return $_FILES[$name] ?? null;
    }
}
