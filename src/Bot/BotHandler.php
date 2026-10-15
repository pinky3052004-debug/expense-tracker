<?php
declare(strict_types=1);
namespace App\Bot;

use App\Services\{CategoryService, ExpenseService, GeminiOcr, LinkService, PendingReceiptService, SupabaseStorage};
use App\Util;

final class BotHandler
{
    private TelegramClient $tg;
    private LinkService $links;
    private ExpenseService $expenses;
    private CategoryService $categories;
    private PendingReceiptService $pending;
    private SupabaseStorage $storage;
    private GeminiOcr $ocr;

    public function __construct()
    {
        $this->tg = new TelegramClient();
        $this->links = new LinkService();
        $this->expenses = new ExpenseService();
        $this->categories = new CategoryService();
        $this->pending = new PendingReceiptService();
        $this->storage = new SupabaseStorage();
        $this->ocr = new GeminiOcr();
    }

    public function handle(array $update): void
    {
        $chat = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
        try {
            if (isset($update['callback_query'])) { $this->onCallback($update['callback_query']); return; }
            if (isset($update['message'])) $this->onMessage($update['message']);
        } catch (\Throwable $e) {
            error_log((string)$e);
            if ($chat) $this->tg->send($chat, '⚠️ အမှားတစ်ခုဖြစ်သွားပါတယ်။ နောက်တစ်ခါ ပြန်ကြိုးစားကြည့်ပါ။');
        }
    }

    private function onMessage(array $m): void
    {
        if (($m['chat']['type'] ?? '') !== 'private') return;   // group ထဲက message တွေ လျစ်လျူရှု
        $chat = $m['chat']['id'];
        $tgId = (int)$m['from']['id'];
        $text = trim($m['text'] ?? '');

        if (preg_match('#^/start#', $text)) {
            $this->tg->send($chat, "မင်္ဂလာပါ! 👋\nWeb app မှာ Settings → Telegram ကနေ code ထုတ်ပြီး\n/link CODE လို့ ပို့ပါ။");
            return;
        }
        if (preg_match('#^/link(?:@\w+)?\s+([A-Za-z0-9]{4,10})#', $text, $x)) {
            $ok = $this->links->redeem($x[1], $tgId);
            $this->tg->send($chat, $ok
                ? "✅ ချိတ်ပြီးပါပြီ!\n/add 5000 lunch ဒါမှမဟုတ် ဘောင်ချာပုံ ပို့ကြည့်ပါ။ /help"
                : '❌ Code မှားနေတယ် (သို့) သက်တမ်းကုန်သွားပြီ။ Web မှာ code အသစ်ထုတ်ပါ။');
            return;
        }

        $profile = $this->links->profileByTelegram($tgId);
        if (!$profile) {
            $this->tg->send($chat, "Account မချိတ်ရသေးပါ။\nWeb app ကနေ code ထုတ်ပြီး /link CODE ပို့ပါ။");
            return;
        }
        $uid = $profile['id'];

        if (!empty($m['photo']))                     { $this->onPhoto($chat, $uid, $profile, $m['photo']); return; }
        if (preg_match('#^/add(?:@\w+)?(?:\s+(.*))?$#su', $text, $x)) { $this->onAdd($chat, $uid, $profile, trim($x[1] ?? '')); return; }
        if (preg_match('#^/today#', $text))          { $this->onToday($chat, $uid); return; }
        if (preg_match('#^/month#', $text))          { $this->onMonth($chat, $uid); return; }

        $this->tg->send($chat, "အသုံးပြုနည်း\n/add 5000 lunch — စာနဲ့ထည့်\n📷 ဘောင်ချာဓာတ်ပုံ ပို့ — အလိုအလျောက်ဖတ်\n/today — ဒီနေ့ အသုံးစရိတ်\n/month — ဒီလ အနှစ်ချုပ်");
    }

    // ---- /add 5000 lunch
    private function onAdd(int $chat, string $uid, array $profile, string $args): void
    {
        if (!preg_match('/^([\d.,]+)\s*(.*)$/su', $args, $x) || !is_numeric($amt = str_replace(',', '', $x[1]))) {
            $this->tg->send($chat, 'ဥပမာ: /add 5000 lunch');
            return;
        }
        $note = trim($x[2]);
        $cats = $this->categories->list($uid);
        $catId = CategoryService::guess($cats, $note);

        $this->expenses->create($uid, [
            'amount' => (float)$amt, 'currency' => $profile['default_currency'],
            'category_id' => $catId, 'note' => $note ?: null, 'source' => 'telegram',
        ]);
        $catName = $this->catLabel($cats, $catId);
        $this->tg->send($chat, '✅ ' . Util::money($amt, $profile['default_currency']) . " · $catName" . ($note ? " · $note" : ''));
    }

    // ---- ဓာတ်ပုံ → OCR → confirm
    private function onPhoto(int $chat, string $uid, array $profile, array $photos): void
    {
        $best = end($photos);                         // အကြီးဆုံး size
        $this->tg->send($chat, '🔍 ဘောင်ချာကို ဖတ်နေပါတယ်...');

        $bytes = $this->tg->downloadFile($best['file_id']);
        $path  = $uid . '/' . Util::uuid() . '.jpg';
        $this->storage->upload($path, $bytes, 'image/jpeg');

        $cats = $this->categories->list($uid);
        try {
            $d = $this->ocr->scan($bytes, 'image/jpeg', array_column($cats, 'name'));
        } catch (\Throwable $e) {
            error_log((string)$e);
            $this->storage->delete($path);
            $this->tg->send($chat, '😕 ဖတ်လို့မရပါဘူး။ /add 5000 lunch လို့ ကိုယ်တိုင်ထည့်ပါ။');
            return;
        }
        if (empty($d['amount'])) {
            $this->storage->delete($path);
            $this->tg->send($chat, '😕 Total ကို မတွေ့ပါဘူး။ ပုံပိုရှင်းအောင် ပြန်ရိုက်ကြည့်ပါ၊ ဒါမှမဟုတ် /add နဲ့ ထည့်ပါ။');
            return;
        }

        $d['currency']    = $d['currency'] ?: $profile['default_currency'];
        $d['category_id'] = CategoryService::matchId($cats, $d['category'] ?? null)
                         ?? CategoryService::matchId($cats, 'Other');
        $id = $this->pending->create($uid, $d, $path);

        $msg = "🧾 ဘောင်ချာ ဖတ်ထားတာ\n"
            . 'ဆိုင်: ' . ($d['merchant'] ?: '—') . "\n"
            . 'စုစုပေါင်း: ' . Util::money($d['amount'], $d['currency']) . "\n"
            . 'ရက်စွဲ: ' . (new \DateTimeImmutable($d['spent_at']))->format('d M Y') . "\n"
            . 'Category: ' . $this->catLabel($cats, $d['category_id']) . "\n\nမှန်ပါသလား?";

        $this->tg->send($chat, $msg, ['reply_markup' => ['inline_keyboard' => [[
            ['text' => '✅ သိမ်းမယ်', 'callback_data' => "ok:$id"],
            ['text' => '❌ ပယ်ဖျက်',  'callback_data' => "no:$id"],
        ]]]]);
    }

    private function onCallback(array $cb): void
    {
        $chat = $cb['message']['chat']['id'];
        $mid  = (int)$cb['message']['message_id'];
        $profile = $this->links->profileByTelegram((int)$cb['from']['id']);
        if (!$profile) { $this->tg->answerCallback($cb['id'], 'Account မချိတ်ရသေးပါ'); return; }

        [$act, $id] = array_pad(explode(':', (string)($cb['data'] ?? ''), 2), 2, '');
        if (!in_array($act, ['ok', 'no'], true) || !preg_match('/^[0-9a-f-]{36}$/', $id)) {
            $this->tg->answerCallback($cb['id']);
            return;
        }

        $row = $this->pending->take($id, $profile['id']);
        if (!$row) { $this->tg->answerCallback($cb['id'], 'လုပ်ပြီးသား (သို့) သက်တမ်းကုန်ပြီ'); return; }

        if ($act === 'no') {
            $this->storage->delete($row['receipt_path']);
            $this->tg->answerCallback($cb['id'], 'ပယ်ဖျက်ပြီး');
            $this->tg->edit($chat, $mid, '❌ ပယ်ဖျက်လိုက်ပါပြီ');
            return;
        }

        $d = $row['draft'];
        $this->expenses->create($profile['id'], [
            'amount' => $d['amount'], 'currency' => $d['currency'], 'category_id' => $d['category_id'] ?? null,
            'merchant' => $d['merchant'] ?? null, 'spent_at' => $d['spent_at'],
            'receipt_path' => $row['receipt_path'], 'source' => 'ocr',
        ]);
        $this->tg->answerCallback($cb['id'], 'သိမ်းပြီး ✅');
        $this->tg->edit($chat, $mid, '✅ သိမ်းပြီးပါပြီ — ' . Util::money($d['amount'], $d['currency'])
            . (!empty($d['merchant']) ? " · {$d['merchant']}" : ''));
    }

    private function onToday(int $chat, string $uid): void
    {
        $start = new \DateTimeImmutable('today');
        $rows = $this->expenses->list($uid, ['from' => $start->format('c'), 'to' => $start->modify('+1 day')->format('c'), 'limit' => 50]);
        if (!$rows) { $this->tg->send($chat, 'ဒီနေ့ စရိတ်မရှိသေးပါ။'); return; }

        $lines = []; $total = 0.0;
        foreach (array_reverse($rows) as $r) {
            $total += (float)$r['amount'];
            $label = $r['merchant'] ?: ($r['note'] ?: '');
            $lines[] = '• ' . Util::money($r['amount']) . ' · ' . ($r['category_name'] ?? '—') . ($label ? " · $label" : '');
        }
        $this->tg->send($chat, "📅 ဒီနေ့\n" . implode("\n", $lines) . "\n\nစုစုပေါင်း: " . Util::money($total, $rows[0]['currency']));
    }

    private function onMonth(int $chat, string $uid): void
    {
        $s = $this->expenses->summary($uid, date('Y-m'));
        if (!$s['by_category']) { $this->tg->send($chat, 'ဒီလ စရိတ်မရှိသေးပါ။'); return; }
        $lines = array_map(fn($r) => '• ' . $r['category'] . ': ' . Util::money($r['total']) . " ({$r['count']})", $s['by_category']);
        $this->tg->send($chat, "🗓 {$s['month']}\n" . implode("\n", $lines) . "\n\nစုစုပေါင်း: " . Util::money($s['total']));
    }

    private function catLabel(array $cats, ?string $id): string
    {
        foreach ($cats as $c) if ($c['id'] === $id) return trim(($c['icon'] ?? '') . ' ' . $c['name']);
        return '—';
    }
}
