<?php
declare(strict_types=1);
$configFile=dirname(__DIR__).'/config.php';
if(!is_file($configFile)){http_response_code(500);exit('ابتدا config.php را تنظیم کنید.');}
$c=require $configFile;
$db=new PDO("mysql:host={$c['host']};dbname={$c['database']};charset=utf8mb4",$c['username'],$c['password'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
require dirname(__DIR__) . '/app/Models/ChartOfAccounts.php';
ensureChartOfAccounts($db);
function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$report=$_GET['report']??'journal';
$from=$_GET['from']??date('Y-01-01');$to=$_GET['to']??date('Y-m-d');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)||$from>$to){http_response_code(400);exit('بازه تاریخ نامعتبر است.');}
$settings=$db->query('SELECT * FROM settings WHERE id=1')->fetch()?:['business_name'=>'کسب‌وکار من','currency'=>'تومان','company_address'=>'','phone'=>'','email'=>'','logo_path'=>'','header_text'=>''];
$rows=[];$title='دفتر روزنامه';
if($report==='trial'){
 $title='تراز آزمایشی';
 $q=$db->prepare('SELECT a.code,a.title,COALESCE(SUM(l.debit),0) debit,COALESCE(SUM(l.credit),0) credit FROM accounts a LEFT JOIN journal_lines l ON l.account_id=a.id LEFT JOIN journal_entries e ON e.id=l.entry_id AND e.entry_date BETWEEN ? AND ? WHERE a.is_postable=1 GROUP BY a.id ORDER BY a.code');$q->execute([$from,$to]);$rows=$q->fetchAll();
}elseif($report==='transactions'){
 $title='گزارش خرید و فروش';
 $q=$db->prepare('SELECT t.id,t.txn_date,t.txn_type,p.name product,t.quantity,t.unit_price,t.total,t.payment_method,t.party_name FROM transactions t JOIN products p ON p.id=t.product_id WHERE t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date,t.id');$q->execute([$from,$to]);$rows=$q->fetchAll();
}else{
 $q=$db->prepare('SELECT e.id,e.entry_date,e.description,e.source_type,a.code,a.title,l.debit,l.credit FROM journal_entries e JOIN journal_lines l ON l.entry_id=e.id JOIN accounts a ON a.id=l.account_id WHERE e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date,e.id,l.id');$q->execute([$from,$to]);$rows=$q->fetchAll();
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?></title>
<link rel="stylesheet" href="assets/css/app.css"><link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#174ea6"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="حسابداری"><script src="assets/js/pwa.js" defer></script></head><body><main>
<section class="no-print"><h2>مرکز چاپ و گزارش‌ها</h2><form method="get"><label>نوع گزارش <select name="report"><option value="journal" <?=$report==='journal'?'selected':''?>>دفتر روزنامه</option><option value="trial" <?=$report==='trial'?'selected':''?>>تراز آزمایشی</option><option value="transactions" <?=$report==='transactions'?'selected':''?>>خرید و فروش</option></select></label><label>از تاریخ <input type="date" name="from" value="<?=h($from)?>" required></label><label>تا تاریخ <input type="date" name="to" value="<?=h($to)?>" required></label><button>نمایش گزارش</button></form><div class="actions"><button type="button" onclick="window.print()">چاپ / ذخیره PDF</button><a href="index.php">بازگشت</a></div></section>
<div class="paper"><div class="head"><div class="letterhead"><?php if(!empty($settings['logo_path'])): ?><img class="letterhead-logo" src="<?=h($settings['logo_path'])?>" alt="آرم شرکت"><?php endif; ?><div class="letterhead-info"><h2><?=h($settings['business_name'])?></h2><?php if(!empty($settings['header_text'])): ?><div><?=h($settings['header_text'])?></div><?php endif; ?><?php if(!empty($settings['company_address'])): ?><div><?=h($settings['company_address'])?></div><?php endif; ?><?php if(!empty($settings['phone'])): ?><div>تلفن: <?=h($settings['phone'])?></div><?php endif; ?><?php if(!empty($settings['email'])): ?><div><?=h($settings['email'])?></div><?php endif; ?></div></div><h2><?=h($settings['business_name'])?></h2><h3><?=h($title)?></h3><div>بازه گزارش: <?=h($from)?> تا <?=h($to)?></div><div class="muted">تاریخ تهیه: <?=date('Y-m-d H:i')?> | واحد پول: <?=h($settings['currency'])?></div></div>
<?php if($report==='trial'):?><table><thead><tr><th>کد حساب</th><th>نام حساب</th><th>بدهکار</th><th>بستانکار</th></tr></thead><tbody><?php $td=$tc=0;foreach($rows as $r):$td+=(float)$r['debit'];$tc+=(float)$r['credit'];?><tr><td><?=h($r['code'])?></td><td><?=h($r['title'])?></td><td><?=number_format((float)$r['debit'],2)?></td><td><?=number_format((float)$r['credit'],2)?></td></tr><?php endforeach;?></tbody><tfoot><tr><th colspan="2">جمع</th><th><?=number_format($td,2)?></th><th><?=number_format($tc,2)?></th></tr></tfoot></table>
<?php elseif($report==='transactions'):?><table><thead><tr><th>شماره</th><th>تاریخ</th><th>نوع</th><th>کالا</th><th>تعداد</th><th>قیمت واحد</th><th>مبلغ</th><th>پرداخت</th><th>طرف حساب</th></tr></thead><tbody><?php $total=0;foreach($rows as $r):$total+=(float)$r['total'];?><tr><td><?=h($r['id'])?></td><td><?=h($r['txn_date'])?></td><td><?=$r['txn_type']==='sale'?'فروش':'خرید'?></td><td><?=h($r['product'])?></td><td><?=h($r['quantity'])?></td><td><?=number_format((float)$r['unit_price'],2)?></td><td><?=number_format((float)$r['total'],2)?></td><td><?=$r['payment_method']==='cash'?'نقدی':'نسیه'?></td><td><?=h($r['party_name'])?></td></tr><?php endforeach;?></tbody><tfoot><tr><th colspan="6">جمع مبالغ</th><th><?=number_format($total,2)?></th><th colspan="2"></th></tr></tfoot></table>
<?php else:?><table><thead><tr><th>شماره سند</th><th>تاریخ</th><th>شرح</th><th>کد</th><th>حساب</th><th>بدهکار</th><th>بستانکار</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=h($r['id'])?></td><td><?=h($r['entry_date'])?></td><td><?=h($r['description'])?></td><td><?=h($r['code'])?></td><td><?=h($r['title'])?></td><td><?=number_format((float)$r['debit'],2)?></td><td><?=number_format((float)$r['credit'],2)?></td></tr><?php endforeach;?></tbody></table><?php endif;?>
<p class="muted">این گزارش از اطلاعات ثبت‌شده در نرم‌افزار تهیه شده است؛ صحت حسابداری داده‌ها باید بررسی شود.</p><div class="no-print"><button onclick="window.print()">چاپ / ذخیره PDF</button></div></div></main></body></html>