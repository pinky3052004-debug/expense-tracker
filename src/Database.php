<?php
declare(strict_types=1);
namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;

        $u = parse_url(getenv('DATABASE_URL') ?: '');
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            $u['host'], $u['port'] ?? 5432, ltrim($u['path'], '/'));

        self::$pdo = new PDO($dsn, urldecode($u['user']), urldecode($u['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true, // transaction pooler (6543) နဲ့ သုံးလို့ရအောင်
        ]);
        return self::$pdo;
    }
}
