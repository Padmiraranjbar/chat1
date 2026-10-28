<?php
declare(strict_types=1);
session_start();
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) { http_response_code(500); exit('ابتدا config.example.php را به config.php کپی و تنظیم کنید.'); }
$c = require $configFile;
$pdo = new PDO("mysql:host={$c['host']};dbname={$c['database']};charset=utf8mb4", $c['username'], $c['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function postJournal(PDO $db, string $kind, int $id, string $date, string $desc, int $debitAccount, int $creditAccount, float $amount): void {
    if ($amount <= 0) throw new RuntimeException('مبلغ باید بیشتر از صفر باشد.');
    $q=$db->prepare('INSERT INTO journal_entries(entry_date,description,source_type,source_id) VALUES(?,?,?,?)');
    $q->execute([$date,$desc,$kind,$id]); $entry=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO journal_lines(entry_id,account_id,debit,credit) VALUES(?,?,?,?)');
    $q->execute([$entry,$debitAccount,$amount,0]); $q->execute([$entry,$creditAccount,0,$amount]);
}
$msg='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) throw new RuntimeException('درخواست نامعتبر است؛ صفحه را تازه کنید.');
        $action=$_POST['action'] ?? '';
        if ($action==='product') {
            $q=$pdo->prepare('INSERT INTO products(name,sku,stock,purchase_price,sale_price) VALUES(?,?,?,?,?)');
            $q->execute([trim($_POST['name']),trim($_POST['sku']) ?: null,0,(float)$_POST['purchase_price'],(float)$_POST['sale_price']]);
            $msg='کالا ثبت شد.';
        } elseif (in_array($action,['purchase','sale'],true)) {
            $pid=(int)$_POST['product_id']; $qty=(float)$_POST['quantity']; $price=(float)$_POST['unit_price'];
            $date=$_POST['txn_date']; $method=$_POST['payment_method']==='credit'?'credit':'cash';
            if ($pid<1 || $qty<=0 || $price<=0) throw new RuntimeException('کالا، تعداد و قیمت را درست وارد کنید.');
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT * FROM products WHERE id=? FOR UPDATE'); $q->execute([$pid]); $p=$q->fetch();
            if (!$p) throw new RuntimeException('کالا پیدا نشد.');
            if ($action==='sale' && (float)$p['stock']<$qty) throw new RuntimeException('موجودی کالا کافی نیست.');
            $total=round($qty*$price,2); $type=$action;
            $q=$pdo->prepare('INSERT INTO transactions(txn_type,txn_date,product_id,quantity,unit_price,total,payment_method,party_name) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([$type,$date,$pid,$qty,$price,$total,$method,trim($_POST['party_name'] ?? '')]);
            $tid=(int)$pdo->lastInsertId();
            $delta=$action==='purchase'?$qty:-$qty;
            $q=$pdo->prepare('UPDATE products SET stock=stock+?, purchase_price=IF(?,purchase_price, purchase_price) WHERE id=?');
            $q->execute([$delta,0,$pid]);
            if ($action==='purchase') postJournal($pdo,$type,$tid,$date,'خرید کالا: '.$p['name'],$method==='cash'?5:5,$method==='cash'?1:2,$total);
            else postJournal($pdo,$type,$tid,$date,'فروش کالا: '.$p['name'],$method==='cash'?1:2,4,$total);
            $pdo->commit(); $msg='عملیات ثبت شد و سند حسابداری به‌صورت خودکار ایجاد شد.';
        } elseif ($action==='settings') {
            $q=$pdo->prepare('UPDATE settings SET business_name=?,business_type=?,currency=? WHERE id=1');
            $q->execute([trim($_POST['business_name']),$_POST['business_type'],trim($_POST['currency'])]);
            $msg='تنظیمات ذخیره شد.';
        }
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $msg='خطا: '.$e->getMessage(); }
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$products=$pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();
$settings=$pdo->query('SELECT * FROM settings WHERE id=1')->fetch() ?: ['business_name'=>'کسب‌وکار من','business_type'=>'store','currency'=>'تومان'];
$entries=$pdo->query('SELECT e.id,e.entry_date,e.description,a.title,l.debit,l.credit FROM journal_entries e JOIN journal_lines l ON l.entry_id=e.id JOIN accounts a ON a.id=l.account_id ORDER BY e.id DESC,l.id')->fetchAll();
$balances=$pdo->query('SELECT a.code,a.title,a.type,COALESCE(SUM(l.debit),0) debit,COALESCE(SUM(l.credit),0) credit FROM accounts a LEFT JOIN journal_lines l ON l.account_id=a.id GROUP BY a.id ORDER BY a.code')->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>حسابداری هوشمند</title>
<style>body{font-family:Tahoma,Arial;background:#f4f6fa;margin:0;color:#202938}main{max-width:1100px;margin:auto;padding:20px}section{background:white;padding:18px;margin:14px 0;border-radius:12px;box-shadow:0 2px 10px #0001}input,select,button{padding:10px;margin:5px;border:1px solid #ccd2dc;border-radius:6px}button{background:#174ea6;color:white;cursor:pointer}table{border-collapse:collapse;width:100%;overflow:auto}td,th{border-bottom:1px solid #ddd;padding:9px;text-align:right}.msg{background:#e7f5e8;padding:12px;border-radius:8px}</style></head><body><main>
<h1>حسابداری هوشمند</h1><?php if($msg): ?><p class="msg"><?=h($msg)?></p><?php endif; ?>
<section><h2>تنظیمات کسب‌وکار</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="settings"><input name="business_name" value="<?=h($settings['business_name'])?>" placeholder="نام کسب‌وکار"><select name="business_type"><?php foreach(['store'=>'فروشگاه','company'=>'شرکت','personal'=>'شخصی','custom'=>'سفارشی'] as $k=>$v): ?><option value="<?=$k?>" <?=$settings['business_type']===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select><input name="currency" value="<?=h($settings['currency'])?>" placeholder="واحد پول"><button>ذخیره</button></form></section>
<section><h2>تعریف کالا</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="product"><input name="name" required placeholder="نام کالا"><input name="sku" placeholder="کد کالا"><input type="number" step=".01" min="0" name="purchase_price" placeholder="قیمت خرید"><input type="number" step=".01" min="0" name="sale_price" placeholder="قیمت فروش"><button>ثبت کالا</button></form></section>
<?php foreach(['purchase'=>'خرید کالا','sale'=>'فروش کالا'] as $act=>$label): ?><section><h2><?=$label?></h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="<?=$act?>"><input type="date" name="txn_date" required value="<?=date('Y-m-d')?>"><select name="product_id" required><option value="">انتخاب کالا</option><?php foreach($products as $p): ?><option value="<?=$p['id']?>"><?=h($p['name'])?> (موجودی: <?=h($p['stock'])?>)</option><?php endforeach; ?></select><input type="number" step=".001" min=".001" name="quantity" required placeholder="تعداد"><input type="number" step=".01" min=".01" name="unit_price" required placeholder="قیمت واحد"><select name="payment_method"><option value="cash">نقدی</option><option value="credit">نسیه</option></select><input name="party_name" placeholder="نام مشتری/تأمین‌کننده"><button>ثبت <?=$label?></button></form></section><?php endforeach; ?>
<section><h2>تراز آزمایشی ساده</h2><table><tr><th>کد</th><th>حساب</th><th>بدهکار</th><th>بستانکار</th></tr><?php foreach($balances as $b): ?><tr><td><?=h($b['code'])?></td><td><?=h($b['title'])?></td><td><?=number_format((float)$b['debit'],2)?></td><td><?=number_format((float)$b['credit'],2)?></td></tr><?php endforeach; ?></table></section>
<section><h2>دفتر روزنامه (اسناد خودکار)</h2><table><tr><th>شماره سند</th><th>تاریخ</th><th>شرح</th><th>حساب</th><th>بدهکار</th><th>بستانکار</th></tr><?php foreach($entries as $e): ?><tr><td><?=h($e['id'])?></td><td><?=h($e['entry_date'])?></td><td><?=h($e['description'])?></td><td><?=h($e['title'])?></td><td><?=number_format((float)$e['debit'],2)?></td><td><?=number_format((float)$e['credit'],2)?></td></tr><?php endforeach; ?></table></section>
</main></body></html>