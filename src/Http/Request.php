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
        if (isset($_SERVER[$key])) return $_SERVER[$key];

        if (strcasecmp($name, 'Authorization') === 0 && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, $name) === 0) return $v;
            }
        }
        return null;
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
