<?php
declare(strict_types=1);
session_start();
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) { http_response_code(500); exit('ابتدا config.php را تنظیم کنید.'); }
$c = require $configFile;
$db = new PDO("mysql:host={$c['host']};dbname={$c['database']};charset=utf8mb4", $c['username'], $c['password'], [
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$db->exec("CREATE TABLE IF NOT EXISTS fiscal_years (
 id BIGINT PRIMARY KEY AUTO_INCREMENT, title VARCHAR(100) NOT NULL, start_date DATE NOT NULL,
 end_date DATE NOT NULL, status ENUM('open','closed') NOT NULL DEFAULT 'open',
 opening_entry_id BIGINT NULL, closing_entry_id BIGINT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_fiscal_dates(start_date,end_date)
) ENGINE=InnoDB");
$db->exec("INSERT IGNORE INTO accounts(id,code,title,type) VALUES(6,'302','سود و زیان انباشته','equity')");
function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$_SESSION['fy_csrf'] ??= bin2hex(random_bytes(32));
$msg='';
function addEntry(PDO $db,string $date,string $desc,string $kind,int $sourceId,array $lines): int {
 $q=$db->prepare('INSERT INTO journal_entries(entry_date,description,source_type,source_id) VALUES(?,?,?,?)');
 $q->execute([$date,$desc,$kind,$sourceId]); $id=(int)$db->lastInsertId();
 $q=$db->prepare('INSERT INTO journal_lines(entry_id,account_id,debit,credit) VALUES(?,?,?,?)');
 $debit=$credit=0.0;
 foreach($lines as $line){ $debit+=(float)$line[1]; $credit+=(float)$line[2]; }
 if(abs($debit-$credit)>0.01) throw new RuntimeException('سند افتتاحیه/اختتامیه تراز نیست.');
 foreach($lines as $line) $q->execute([$id,$line[0],$line[1],$line[2]]);
 return $id;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try {
  if(!hash_equals($_SESSION['fy_csrf'],$_POST['csrf']??'')) throw new RuntimeException('درخواست نامعتبر است؛ صفحه را تازه کنید.');
  $action=$_POST['action']??'';
  if($action==='create'){
   $title=trim($_POST['title']??''); $start=$_POST['start_date']??''; $end=$_POST['end_date']??'';
   if($title==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$end) || $start>$end) throw new RuntimeException('عنوان و بازه تاریخ را درست وارد کنید.');
   $q=$db->prepare("SELECT COUNT(*) FROM fiscal_years WHERE NOT(end_date<? OR start_date>?)"); $q->execute([$start,$end]);
   if((int)$q->fetchColumn()>0) throw new RuntimeException('بازه این سال مالی با سال مالی دیگری هم‌پوشانی دارد.');
   $db->beginTransaction();
   $q=$db->prepare("INSERT INTO fiscal_years(title,start_date,end_date) VALUES(?,?,?)"); $q->execute([$title,$start,$end]); $fy=(int)$db->lastInsertId();
   $prior=date('Y-m-d',strtotime($start.' -1 day'));
   $q=$db->prepare("SELECT a.id,a.type,COALESCE(x.d,0) d,COALESCE(x.c,0) c FROM accounts a LEFT JOIN (SELECT l.account_id,SUM(l.debit) d,SUM(l.credit) c FROM journal_lines l JOIN journal_entries e ON e.id=l.entry_id WHERE e.entry_date<=? GROUP BY l.account_id) x ON x.account_id=a.id WHERE a.type IN ('asset','liability','equity') ORDER BY a.id");
   $q->execute([$prior]); $lines=[];
   foreach($q as $a){$net=round((float)$a['d']-(float)$a['c'],2); if(abs($net)<0.01) continue; $lines[]=[(int)$a['id'],$net<0?0:abs($net),$net>0?0:abs($net)];}
   if($lines){$sumD=array_sum(array_column($lines,1));$sumC=array_sum(array_column($lines,2));$diff=round($sumD-$sumC,2);if(abs($diff)>0.01){$lines[]=[6,$diff<0?abs($diff):0,$diff>0?abs($diff):0];}
    $entry=addEntry($db,$start,'سند افتتاحیه '.$title,'opening',$fy,$lines);
    $u=$db->prepare('UPDATE fiscal_years SET opening_entry_id=? WHERE id=?');$u->execute([$entry,$fy]);
   }
   $db->commit(); $msg='سال مالی ایجاد شد'.($lines?' و سند افتتاحیه مانده حساب‌های دائمی ثبت شد.':'؛ چون مانده قبلی پیدا نشد، سند افتتاحیه لازم نبود.');
  } elseif($action==='close'){
   $id=(int)($_POST['id']??0); $db->beginTransaction();
   $q=$db->prepare("SELECT * FROM fiscal_years WHERE id=? FOR UPDATE");$q->execute([$id]);$fy=$q->fetch();
   if(!$fy) throw new RuntimeException('سال مالی پیدا نشد.');
   if($fy['status']==='closed') throw new RuntimeException('این سال مالی قبلاً بسته شده است.');
   $q=$db->prepare("SELECT a.id,a.type,COALESCE(SUM(l.debit),0) d,COALESCE(SUM(l.credit),0) c FROM accounts a JOIN journal_lines l ON l.account_id=a.id JOIN journal_entries e ON e.id=l.entry_id WHERE e.entry_date BETWEEN ? AND ? AND a.type IN ('income','expense') GROUP BY a.id");
   $q->execute([$fy['start_date'],$fy['end_date']]);$lines=[];$net=0.0;
   foreach($q as $a){$bal=round((float)$a['c']-(float)$a['d'],2); if($a['type']==='expense') $bal=-$bal; $net+=$bal;
    $amount=abs($bal); if($amount<0.01) continue;
    if($a['type']==='income') $lines[]=[(int)$a['id'],$bal>0?$amount:0,$bal<0?$amount:0];
    else $lines[]=[(int)$a['id'],$bal<0?$amount:0,$bal>0?$amount:0];
   }
   $net=round($net,2);
   if(abs($net)>=0.01){$lines[]= $net>0?[6,0,$net]:[6,abs($net),0];}
   if($lines){$entry=addEntry($db,$fy['end_date'],'بستن حساب‌های موقت سال مالی '.$fy['title'],'closing',$id,$lines);}
   else {$entry=null;}
   $u=$db->prepare("UPDATE fiscal_years SET status='closed',closing_entry_id=? WHERE id=?");$u->execute([$entry,$id]);
   $db->commit();$msg='سال مالی بسته شد. سود/زیان خالص محاسبه و حساب‌های درآمد و هزینه بسته شدند.';
  }
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$msg='خطا: '.$e->getMessage();}
}
$years=$db->query('SELECT * FROM fiscal_years ORDER BY start_date DESC')->fetchAll();
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مدیریت سال مالی</title>
<style>body{font-family:Tahoma,Arial;background:#f4f6fa;color:#202938;margin:0}main{max-width:1000px;margin:auto;padding:20px}section{background:#fff;padding:18px;margin:14px 0;border-radius:12px;box-shadow:0 2px 10px #0001}input,button{padding:10px;margin:5px;border:1px solid #ccd2dc;border-radius:6px}button{background:#174ea6;color:white;cursor:pointer}table{width:100%;border-collapse:collapse}td,th{padding:9px;border-bottom:1px solid #ddd;text-align:right}.msg{background:#e7f5e8;padding:12px}</style></head><body><main>
<h1>افتتاح و پایان سال مالی</h1><p><a href="index.php">بازگشت به صفحه حسابداری</a></p><?php if($msg):?><p class="msg"><?=esc($msg)?></p><?php endif;?>
<section><h2>افتتاح سال مالی جدید</h2><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['fy_csrf'])?>"><input type="hidden" name="action" value="create"><input name="title" required placeholder="عنوان، مثلاً سال مالی ۱۴۰۵"><label>شروع: <input type="date" name="start_date" required></label><label>پایان: <input type="date" name="end_date" required></label><button>ایجاد سال مالی و سند افتتاحیه</button></form><p>مانده حساب‌های دائمی ثبت‌شده تا روز قبل از شروع، به‌صورت خودکار به سند افتتاحیه منتقل می‌شود.</p></section>
<section><h2>سال‌های مالی</h2><table><tr><th>عنوان</th><th>شروع</th><th>پایان</th><th>وضعیت</th><th>سند افتتاحیه</th><th>سند اختتامیه</th><th>عملیات</th></tr><?php foreach($years as $y):?><tr><td><?=esc($y['title'])?></td><td><?=esc($y['start_date'])?></td><td><?=esc($y['end_date'])?></td><td><?= $y['status']==='closed'?'بسته':'باز' ?></td><td><?=esc($y['opening_entry_id']??'—')?></td><td><?=esc($y['closing_entry_id']??'—')?></td><td><?php if($y['status']==='open'):?><form method="post" onsubmit="return confirm('پس از بستن سال، این عملیات برگشت‌پذیر نیست. ادامه می‌دهید؟')"><input type="hidden" name="csrf" value="<?=esc($_SESSION['fy_csrf'])?>"><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?=esc($y['id'])?>"><button>بستن سال مالی</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></table></section>
<p><strong>توجه:</strong> پیش از بستن سال در داده‌های واقعی، پشتیبان بگیرید و مانده‌ها را با حسابدار بررسی کنید. این ماژول هنوز ثبت خرید/فروش در صفحه اصلی را به سال مالی باز محدود نمی‌کند.</p></main></body></html>