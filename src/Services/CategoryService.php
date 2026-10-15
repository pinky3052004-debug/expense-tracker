<?php
declare(strict_types=1);
namespace App\Services;

use App\Database;

final class CategoryService
{
    private const ALIASES = [
        'food'      => ['lunch','dinner','breakfast','coffee','tea','food','snack','meal','restaurant','ထမင်း','စားစရာ','ကော်ဖီ','လက်ဖက်ရည်'],
        'transport' => ['taxi','bus','grab','fuel','gas','petrol','train','parking','transport','ကား','ဆီ'],
        'shopping'  => ['shopping','clothes','shoes','market','mall','ဈေး'],
        'bills'     => ['bill','electric','internet','phone','rent','water','ဖုန်း','မီတာ','အိမ်ငှား'],
        'health'    => ['medicine','doctor','hospital','pharmacy','health','ဆေး'],
    ];

    public function list(string $userId): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM categories WHERE user_id = :u ORDER BY name');
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    }

    public function create(string $userId, string $name, ?string $icon): array
    {
        if (trim($name) === '') throw new \InvalidArgumentException('name is required');
        $st = Database::pdo()->prepare('INSERT INTO categories (user_id, name, icon) VALUES (:u,:n,:i) RETURNING *');
        $st->execute(['u' => $userId, 'n' => trim($name), 'i' => $icon]);
        return $st->fetch();
    }

    public function delete(string $userId, string $id): bool
    {
        $st = Database::pdo()->prepare('DELETE FROM categories WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0;
    }

    /** category နာမည်နဲ့ id ရှာ */
    public static function matchId(array $cats, ?string $name): ?string
    {
        if (!$name) return null;
        $n = mb_strtolower(trim($name));
        foreach ($cats as $c) if (mb_strtolower($c['name']) === $n) return $c['id'];
        return null;
    }

    /** စာသား (note) ထဲက စကားလုံးကြည့်ပြီး category ခန့်မှန်း၊ မတွေ့ရင် Other */
    public static function guess(array $cats, string $text): ?string
    {
        $t = mb_strtolower($text);
        foreach ($cats as $c) {
            if ($t !== '' && str_contains($t, mb_strtolower($c['name']))) return $c['id'];
        }
        foreach (self::ALIASES as $name => $words) {
            foreach ($words as $w) {
                if (str_contains($t, $w) && ($id = self::matchId($cats, $name))) return $id;
            }
        }
        return self::matchId($cats, 'Other');
    }
}
