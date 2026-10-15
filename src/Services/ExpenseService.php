<?php
declare(strict_types=1);
namespace App\Services;

use App\Database;

/** API နဲ့ Bot နှစ်ခုလုံးက ဒီ class ကိုပဲ သုံးမယ် */
final class ExpenseService
{
    private const FIELDS = ['amount','currency','category_id','merchant','note','spent_at','receipt_path','source'];

    public function list(string $userId, array $f = []): array
    {
        $sql = 'SELECT e.*, c.name AS category_name, c.icon AS category_icon
                FROM expenses e LEFT JOIN categories c ON c.id = e.category_id
                WHERE e.user_id = :u';
        $p = ['u' => $userId];
        if (!empty($f['from']))        { $sql .= ' AND e.spent_at >= :from'; $p['from'] = $f['from']; }
        if (!empty($f['to']))          { $sql .= ' AND e.spent_at < :to';    $p['to']   = $f['to']; }
        if (!empty($f['category_id'])) { $sql .= ' AND e.category_id = :c';  $p['c']    = $f['category_id']; }
        $limit  = min((int)($f['limit'] ?? 50), 200);
        $offset = max((int)($f['offset'] ?? 0), 0);
        $sql .= " ORDER BY e.spent_at DESC LIMIT $limit OFFSET $offset";

        $st = Database::pdo()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    public function create(string $userId, array $d): array
    {
        if (!isset($d['amount']) || !is_numeric($d['amount']) || $d['amount'] < 0) {
            throw new \InvalidArgumentException('amount is required');
        }
        $d = array_intersect_key($d, array_flip(self::FIELDS));
        $d['user_id'] = $userId;

        $cols = implode(',', array_keys($d));
        $ph   = implode(',', array_map(fn($k) => ":$k", array_keys($d)));
        $st = Database::pdo()->prepare("INSERT INTO expenses ($cols) VALUES ($ph) RETURNING *");
        $st->execute($d);
        return $st->fetch();
    }

    public function update(string $userId, string $id, array $d): ?array
    {
        $d = array_intersect_key($d, array_flip(self::FIELDS));
        if (!$d) throw new \InvalidArgumentException('nothing to update');

        $set = implode(',', array_map(fn($k) => "$k = :$k", array_keys($d)));
        $d['id'] = $id; $d['user_id'] = $userId;
        $st = Database::pdo()->prepare("UPDATE expenses SET $set WHERE id = :id AND user_id = :user_id RETURNING *");
        $st->execute($d);
        return $st->fetch() ?: null;
    }

    public function delete(string $userId, string $id): bool
    {
        $st = Database::pdo()->prepare('DELETE FROM expenses WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0;
    }

    /** month = 'YYYY-MM' */
    public function summary(string $userId, string $month): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) throw new \InvalidArgumentException('month must be YYYY-MM');
        $from = "$month-01";
        $st = Database::pdo()->prepare(
            "SELECT COALESCE(c.name,'Uncategorized') AS category, SUM(e.amount) AS total, COUNT(*) AS count
             FROM expenses e LEFT JOIN categories c ON c.id = e.category_id
             WHERE e.user_id = :u AND e.spent_at >= :from::date AND e.spent_at < (:from2::date + interval '1 month')
             GROUP BY 1 ORDER BY total DESC");
        $st->execute(['u' => $userId, 'from' => $from, 'from2' => $from]);
        $rows = $st->fetchAll();
        return ['month' => $month, 'total' => array_sum(array_column($rows, 'total')), 'by_category' => $rows];
    }
}
