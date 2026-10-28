<?php
declare(strict_types=1);
$configFile=dirname(__DIR__).'/config.php';
if(!is_file($configFile)){http_response_code(500);exit('ابتدا config.php را تنظیم کنید.');}
$c=require $configFile;
$db=new PDO("mysql:host={$c['host']};dbname={$c['database']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
require dirname(__DIR__).'/app/Models/ChartOfAccounts.php'; ensureChartOfAccounts($db);
if(empty($_SESSION['chart_admin'])){http_response_code(403);echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><main style="font-family:tahoma;max-width:720px;margin:40px auto;padding:20px"><h2>ورود مدیر لازم است</h2><p>برای جلوگیری از ورود اطلاعات غیرمجاز، ابتدا از بخش کدینگ حساب‌ها وارد حساب مدیر شوید.</p><a href="index.php?route=accounts">ورود مدیر</a></main></html>';exit;}
function ex_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
if(isset($_GET['template'])){
 $templates=[
  'products'=>['products-template.csv',"name,sku,purchase_price,sale_price\r\nPencil,SKU001,10000,15000\r\n"],
  'transactions'=>['transactions-template.csv',"txn_type,txn_date,product_sku,quantity,unit_price,payment_method,party_name\r\npurchase,2026-10-01,SKU001,10,10000,cash,Supplier\r\nsale,2026-10-02,SKU001,2,15000,cash,Customer\r\n"],
  'accounts'=>['accounts-template.csv',"code,title,type,level,parent_code\r\n10101,Sub cash,asset,detail,101\r\n"]
 ];
 $key=(string)$_GET['template'];if(!isset($templates[$key])){http_response_code(404);exit('قالب پیدا نشد.');}
 header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$templates[$key][0].'"');echo "\xEF\xBB\xBF".$templates[$key][1];exit;
}
$_SESSION['exchange_csrf']??=bin2hex(random_bytes(32));$message='';$success=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['exchange_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('درخواست نامعتبر است.');
  if(!isset($_FILES['csv_file'])||$_FILES['csv_file']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('فایل CSV را انتخاب کنید.');
  if($_FILES['csv_file']['size']>5*1024*1024)throw new RuntimeException('حداکثر حجم فایل ۵ مگابایت است.');
  $fp=fopen($_FILES['csv_file']['tmp_name'],'rb');if(!$fp)throw new RuntimeException('فایل خوانده نشد.');
  $first=fgets($fp);if($first===false)throw new RuntimeException('فایل خالی است.');
  $first=preg_replace('/^\xEF\xBB\xBF/','',$first);$headers=array_map(static fn($v)=>trim((string)$v),str_getcsv(trim($first)));
  $kind=(string)($_POST['import_type']??'products');$count=0;
  // Upgrade older installations before starting the data transaction.
  $columns=[];foreach($db->query('SHOW COLUMNS FROM transactions')->fetchAll() as $col)$columns[$col['Field']]=true;
  if(!isset($columns['cost_total']))$db->exec('ALTER TABLE transactions ADD COLUMN cost_total DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total');
  $db->beginTransaction();
  while(($row=fgetcsv($fp))!==false){
   if(count($row)===1&&trim((string)$row[0])==='')continue;
   if(count($row)!==count($headers))throw new RuntimeException('تعداد ستون‌های یکی از ردیف‌ها با سرفصل‌ها برابر نیست.');
   $r=array_combine($headers,$row);
   if($kind==='products'){
    foreach(['name','sku','purchase_price','sale_price'] as $key)if(!array_key_exists($key,$r))throw new RuntimeException('ستون‌های کالا: name, sku, purchase_price, sale_price');
    $name=trim((string)$r['name']);if($name==='')throw new RuntimeException('نام کالا خالی است.');
    $q=$db->prepare('INSERT INTO products(name,sku,stock,purchase_price,sale_price) VALUES(?,?,0,?,?)');$q->execute([$name,trim((string)$r['sku'])?:null,max(0,(float)$r['purchase_price']),max(0,(float)$r['sale_price'])]);
   }elseif($kind==='transactions'){
    foreach(['txn_type','txn_date','product_sku','quantity','unit_price','payment_method','party_name'] as $key)if(!array_key_exists($key,$r))throw new RuntimeException('ستون‌های خرید/فروش: txn_type, txn_date, product_sku, quantity, unit_price, payment_method, party_name');
    $type=trim((string)$r['txn_type']);$date=trim((string)$r['txn_date']);$qty=(float)$r['quantity'];$price=(float)$r['unit_price'];$method=trim((string)$r['payment_method'])==='credit'?'credit':'cash';
    if(!in_array($type,['purchase','sale'],true)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||$qty<=0||$price<=0)throw new RuntimeException('نوع، تاریخ، تعداد یا قیمت در فایل خرید/فروش نامعتبر است.');
    $q=$db->prepare('SELECT * FROM products WHERE sku=? FOR UPDATE');$q->execute([trim((string)$r['product_sku'])]);$prod=$q->fetch();if(!$prod)throw new RuntimeException('کالایی با کد '.$r['product_sku'].' پیدا نشد؛ ابتدا کالاها را وارد کنید.');
    if($type==='sale'&&(float)$prod['stock']<$qty)throw new RuntimeException('موجودی برای فروش کالا '.$prod['name'].' کافی نیست.');
    $total=round($qty*$price,2);$cost=$type==='sale'?round($qty*(float)$prod['purchase_price'],2):0;
    $q=$db->prepare('INSERT INTO transactions(txn_type,txn_date,product_id,quantity,unit_price,total,cost_total,payment_method,party_name) VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$type,$date,$prod['id'],$qty,$price,$total,$cost,$method,trim((string)$r['party_name'])]);$tid=(int)$db->lastInsertId();
    $q=$db->prepare('UPDATE products SET stock=stock+?,purchase_price=CASE WHEN ?=1 THEN ? ELSE purchase_price END WHERE id=?');$q->execute([$type==='purchase'?$qty:-$qty,$type==='purchase'?1:0,$price,$prod['id']]);
    $debit=$type==='purchase'?5:($method==='cash'?1:2);$credit=$type==='purchase'?($method==='cash'?1:2):4;
    $q=$db->prepare('INSERT INTO journal_entries(entry_date,description,source_type,source_id) VALUES(?,?,?,?)');$q->execute([$date,($type==='purchase'?'خرید کالا: ':'فروش کالا: ').$prod['name'],$type,$tid]);$entry=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO journal_lines(entry_id,account_id,debit,credit) VALUES(?,?,?,?)');$q->execute([$entry,$debit,$total,0]);$q->execute([$entry,$credit,0,$total]);
   }elseif($kind==='accounts'){
    foreach(['code','title','type','level','parent_code'] as $key)if(!array_key_exists($key,$r))throw new RuntimeException('ستون‌های حساب‌ها: code, title, type, level, parent_code');
    $code=trim((string)$r['code']);$title=trim((string)$r['title']);$type=trim((string)$r['type']);$level=trim((string)$r['level']);$parentCode=trim((string)$r['parent_code']);
    if($code===''||$title===''||!in_array($type,['asset','liability','equity','income','expense'],true)||!in_array($level,['group','general','subsidiary','detail'],true))throw new RuntimeException('کد، عنوان، نوع یا سطح حساب نامعتبر است.');
    $parentId=null;if($parentCode!==''){$q=$db->prepare('SELECT id FROM accounts WHERE code=?');$q->execute([$parentCode]);$parentId=$q->fetchColumn();if(!$parentId)throw new RuntimeException('حساب والد با کد '.$parentCode.' باید پیش از حساب فرزند وارد شود.');}
    $q=$db->prepare('INSERT INTO accounts(code,title,type,parent_id,level,is_postable,is_active) VALUES(?,?,?,?,?,?,1)');$q->execute([$code,$title,$type,$parentId,$level,$level==='detail'?1:0]);
   }else throw new RuntimeException('نوع ورود اطلاعات معتبر نیست.');
   $count++;
  }
  fclose($fp);$db->commit();$message='ورود اطلاعات موفق بود. تعداد ردیف‌ها: '.$count;$success=true;
 }catch(Throwable $e){if(isset($fp)&&is_resource($fp))fclose($fp);if($db->inTransaction())$db->rollBack();error_log('CSV import error: '.$e->getMessage());$message=$e instanceof RuntimeException?$e->getMessage():'ورود اطلاعات انجام نشد؛ فایل را بررسی کنید.';}
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود اطلاعات از اکسل</title><link rel="stylesheet" href="assets/css/app.css"></head><body><main><header class="app-header"><div><h1>ورود اطلاعات از اکسل</h1><p>در Excel فایل را با فرمت CSV UTF-8 ذخیره کنید.</p></div><a href="index.php">بازگشت به داشبورد</a></header>
<?php if($message):?><p class="msg <?=$success?'':'error-msg'?>"><?=ex_h($message)?></p><?php endif;?>
<section><h2>بارگذاری اطلاعات</h2><p>قبل از ورود فایل، نسخه پشتیبان بگیرید. اگر یک ردیف خطا داشته باشد، ورود کل فایل لغو می‌شود.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=ex_h($_SESSION['exchange_csrf'])?>"><label>نوع اطلاعات <select name="import_type"><option value="products">کالاها</option><option value="transactions">خرید و فروش</option><option value="accounts">کدینگ حساب‌ها</option></select></label><label>فایل CSV <input type="file" name="csv_file" accept=".csv,text/csv" required></label><button type="submit">بررسی و وارد کردن</button></form></section>
<section><h2>قالب‌های آماده</h2><p>قالب را دانلود کنید، در Excel تکمیل و با فرمت CSV UTF-8 ذخیره کنید. نام ستون‌ها را تغییر ندهید.</p><p><a href="index.php?route=data-exchange&amp;template=products">قالب کالاها</a> | <a href="index.php?route=data-exchange&amp;template=transactions">قالب خرید و فروش</a> | <a href="index.php?route=data-exchange&amp;template=accounts">قالب کدینگ حساب‌ها</a></p>
<h3>نوع‌های مجاز در فایل خرید/فروش</h3><p>txn_type: purchase یا sale؛ payment_method: cash یا credit؛ تاریخ به شکل YYYY-MM-DD؛ برای هر کالا product_sku یکتا لازم است.</p>
<p class="report-note">این نسخه CSV سازگار با Excel را می‌پذیرد، نه فایل XLSX مستقیم. ورود چک‌ها و مغایرت بانکی تا زمان ساخته‌شدن آن بخش‌ها فعال نیست.</p></section></main></body></html>