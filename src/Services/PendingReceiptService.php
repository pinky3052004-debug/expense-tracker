<?php
declare(strict_types=1);
namespace App\Services;

use App\Database;

final class PendingReceiptService
{
    public function create(string $userId, array $draft, string $path): string
    {
        Database::pdo()->prepare('DELETE FROM pending_receipts WHERE created_at < now() - interval \'1 day\'')->execute();
        $st = Database::pdo()->prepare('INSERT INTO pending_receipts (user_id, draft, receipt_path) VALUES (:u,:d,:p) RETURNING id');
        $st->execute(['u' => $userId, 'd' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'p' => $path]);
        return $st->fetch()['id'];
    }

    /** ယူပြီးရင် ဖျက်တယ် (atomic) → ခလုတ်နှစ်ခါနှိပ်လည်း expense နှစ်ခါမဝင်ဘူး */
    public function take(string $id, string $userId): ?array
    {
        $st = Database::pdo()->prepare('DELETE FROM pending_receipts WHERE id = :id AND user_id = :u RETURNING draft, receipt_path');
        $st->execute(['id' => $id, 'u' => $userId]);
        $row = $st->fetch();
        if (!$row) return null;
        return ['draft' => json_decode($row['draft'], true), 'receipt_path' => $row['receipt_path']];
    }
}
