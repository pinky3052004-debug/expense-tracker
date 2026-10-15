<?php
declare(strict_types=1);
namespace App\Bot;

use App\Http\Client;

final class TelegramClient
{
    private string $token;

    public function __construct()
    {
        $this->token = (string)getenv('TELEGRAM_BOT_TOKEN');
    }

    public function call(string $method, array $params): array
    {
        [, $r] = Client::request('POST', "https://api.telegram.org/bot{$this->token}/$method",
            ['Content-Type: application/json'], json_encode($params, JSON_UNESCAPED_UNICODE), 20);
        return json_decode($r, true) ?? [];
    }

    public function send(int|string $chat, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', ['chat_id' => $chat, 'text' => $text] + $extra);
    }

    public function edit(int|string $chat, int $messageId, string $text): void
    {
        $this->call('editMessageText', ['chat_id' => $chat, 'message_id' => $messageId, 'text' => $text]);
    }

    public function answerCallback(string $id, string $text = ''): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text]);
    }

    public function downloadFile(string $fileId): string
    {
        $info = $this->call('getFile', ['file_id' => $fileId]);
        $path = $info['result']['file_path'] ?? null;
        if (!$path) throw new \RuntimeException('Telegram getFile failed');
        [$c, $bytes] = Client::request('GET', "https://api.telegram.org/file/bot{$this->token}/$path");
        if ($c !== 200) throw new \RuntimeException("Telegram download failed: $c");
        return $bytes;
    }
}
