<?php
declare(strict_types=1);
namespace App\Services;

use App\Database;

final class LinkService
{
    public function createCode(string $userId): array
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM link_codes WHERE user_id = :u OR expires_at < now()')->execute(['u' => $userId]);

        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // 0/O/1/I မပါ
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];

        $pdo->prepare("INSERT INTO link_codes (code, user_id, expires_at) VALUES (:c,:u, now() + interval '10 minutes')")
            ->execute(['c' => $code, 'u' => $userId]);
        return ['code' => $code, 'expires_in' => 600];
    }

    public function redeem(string $code, int $telegramId): bool
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('DELETE FROM link_codes WHERE code = :c AND expires_at > now() RETURNING user_id');
            $st->execute(['c' => strtoupper($code)]);
            $row = $st->fetch();
            if (!$row) { $pdo->rollBack(); return false; }

            // Telegram account တစ်ခုကို web account တစ်ခုနဲ့ပဲ ချိတ်ခွင့်
            $pdo->prepare('UPDATE profiles SET telegram_id = NULL WHERE telegram_id = :t AND id <> :u')
                ->execute(['t' => $telegramId, 'u' => $row['user_id']]);
            $pdo->prepare('UPDATE profiles SET telegram_id = :t WHERE id = :u')
                ->execute(['t' => $telegramId, 'u' => $row['user_id']]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function profileByTelegram(int $telegramId): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, default_currency FROM profiles WHERE telegram_id = :t');
        $st->execute(['t' => $telegramId]);
        return $st->fetch() ?: null;
    }

    public function isLinked(string $userId): bool
    {
        $st = Database::pdo()->prepare('SELECT telegram_id FROM profiles WHERE id = :u');
        $st->execute(['u' => $userId]);
        return (bool)($st->fetch()['telegram_id'] ?? null);
    }

    public function unlink(string $userId): void
    {
        Database::pdo()->prepare('UPDATE profiles SET telegram_id = NULL WHERE id = :u')->execute(['u' => $userId]);
    }
}
