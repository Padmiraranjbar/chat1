<?php
declare(strict_types=1);
$configFile=dirname(__DIR__).'/config.php';
if(!is_file($configFile)){http_response_code(500);exit('ابتدا config.php را تنظیم کنید.');}
$c=require $configFile;
$db=new PDO("mysql:host={$c['host']};dbname={$c['database']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
require dirname(__DIR__).'/app/Models/ChartOfAccounts.php'; ensureChartOfAccounts($db);

if(empty($_SESSION['chart_admin'])){http_response_code(403);echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><main style="font-family:tahoma;max-width:720px;margin:40px auto;padding:20px"><h2>ورود مدیر لازم است</h2><p>برای جلوگیری از ورود اطلاعات غیرمجاز، ابتدا از بخش کدینگ حساب‌ها وارد حساب مدیر شوید.</p><a href="index.php?route=accounts">ورود مدیر</a></main></html>';exit;}
/* Data-exchange tables are created with fixed SQL only; uploaded values are always bound parameters. */
$db->exec("CREATE TABLE IF NOT EXISTS payment_documents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 document_type ENUM('receivable','payable') NOT NULL,
 cheque_number VARCHAR(100) NOT NULL DEFAULT '',
 due_date DATE NOT NULL,
 party_name VARCHAR(190) NOT NULL DEFAULT '',
 bank_name VARCHAR(190) NOT NULL DEFAULT '',
 amount DECIMAL(15,2) NOT NULL,
 status ENUM('pending','deposited','paid','returned','cancelled') NOT NULL DEFAULT 'pending',
 notes VARCHAR(500) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_payment_due_status (due_date,status), INDEX idx_payment_cheque (cheque_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS bank_statement_lines (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 statement_date DATE NOT NULL,
 description VARCHAR(255) NOT NULL DEFAULT '',
 reference_no VARCHAR(100) NOT NULL DEFAULT '',
 amount DECIMAL(15,2) NOT NULL,
 match_status ENUM('unmatched','matched') NOT NULL DEFAULT 'unmatched',
 matched_journal_line_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_bank_date_amount (statement_date,amount), INDEX idx_bank_match (match_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');header('Cache-Control: no-store, private');
function ex_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
if(isset($_GET['template'])){
 $templates=[
  'products'=>['products-template.csv',"name,sku,purchase_price,sale_price\r\n"],
  'transactions'=>['transactions-template.csv',"txn_type,txn_date,product_sku,quantity,unit_price,payment_method,party_name\r\n"],
  'accounts'=>['accounts-template.csv',"code,title,type,level,parent_code\r\n"],
  'receivable-documents'=>['receivable-documents-template.csv',"document_type,cheque_number,due_date,party_name,bank_name,amount,status,notes\r\n"],
  'payable-documents'=>['payable-documents-template.csv',"document_type,cheque_number,due_date,party_name,bank_name,amount,status,notes\r\n"],
  'bank-statement'=>['bank-statement-template.csv',"statement_date,description,reference_no,amount\r\n"]
 ];
 $key=(string)$_GET['template'];if(!isset($templates[$key])){http_response_code(404);exit('قالب پیدا نشد.');}
 header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$templates[$key][0].'"');echo "\xEF\xBB\xBF".$templates[$key][1];exit;
}
$_SESSION['exchange_csrf']??=bin2hex(random_bytes(32));$message='';$success=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['exchange_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('درخواست نامعتبر است.');
  if(!isset($_FILES['csv_file'])||$_FILES['csv_file']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('فایل CSV را انتخاب کنید.');
  if($_FILES['csv_file']['size']<1||$_FILES['csv_file']['size']>5*1024*1024)throw new RuntimeException('حجم فایل باید بین ۱ بایت و ۵ مگابایت باشد.');
  if(strtolower(pathinfo((string)($_FILES['csv_file']['name']??''),PATHINFO_EXTENSION))!=='csv')throw new RuntimeException('فقط فایل با پسوند CSV مجاز است.');
  if(!is_uploaded_file($_FILES['csv_file']['tmp_name']))throw new RuntimeException('فایل بارگذاری‌شده معتبر نیست.');
  $fp=fopen($_FILES['csv_file']['tmp_name'],'rb');if(!$fp)throw new RuntimeException('فایل خوانده نشد.');
  $first=fgets($fp);if($first===false)throw new RuntimeException('فایل خالی است.');
  $first=preg_replace('/^\xEF\xBB\xBF/','',$first);$headers=array_map(static fn($v)=>trim((string)$v),str_getcsv(trim($first)));
  $kind=(string)($_POST['import_type']??'products');$count=0;
  // Upgrade older installations before starting the data transaction.
  $columns=[];foreach($db->query('SHOW COLUMNS FROM transactions')->fetchAll() as $col)$columns[$col['Field']]=true;
  if(!isset($columns['cost_total']))$db->exec('ALTER TABLE transactions ADD COLUMN cost_total DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total');
  $db->beginTransaction();
  while(($row=fgetcsv($fp))!==false){
   if($count>=10000)throw new RuntimeException('حداکثر ۱۰۰۰۰ ردیف در هر فایل مجاز است.');
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
   }elseif(in_array($kind,['receivable-documents','payable-documents'],true)){
    foreach(['document_type','cheque_number','due_date','party_name','bank_name','amount','status','notes'] as $key)if(!array_key_exists($key,$r))throw new RuntimeException('ستون‌های سند: document_type, cheque_number, due_date, party_name, bank_name, amount, status, notes');
    $docType=$kind==='receivable-documents'?'receivable':'payable';
    $due=trim((string)$r['due_date']);$amountRaw=trim((string)$r['amount']);$amount=filter_var($amountRaw,FILTER_VALIDATE_FLOAT);
    $status=trim((string)$r['status']);if($status==='')$status='pending';
    if(trim((string)$r['document_type'])!==$docType||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$due)||!checkdate((int)substr($due,5,2),(int)substr($due,8,2),(int)substr($due,0,4))||$amount===false||$amount<=0||!in_array($status,['pending','deposited','paid','returned','cancelled'],true))throw new RuntimeException('نوع سند، تاریخ سررسید، مبلغ یا وضعیت نامعتبر است.');
    $q=$db->prepare('INSERT INTO payment_documents(document_type,cheque_number,due_date,party_name,bank_name,amount,status,notes) VALUES(?,?,?,?,?,?,?,?)');
    $q->execute([$docType,substr(trim((string)$r['cheque_number']),0,100),$due,substr(trim((string)$r['party_name']),0,190),substr(trim((string)$r['bank_name']),0,190),(float)$amount,$status,substr(trim((string)$r['notes']),0,500)]);
   }elseif($kind==='bank-statement'){
    foreach(['statement_date','description','reference_no','amount'] as $key)if(!array_key_exists($key,$r))throw new RuntimeException('ستون‌های گردش بانک: statement_date, description, reference_no, amount');
    $date=trim((string)$r['statement_date']);$amountRaw=trim((string)$r['amount']);$amount=filter_var($amountRaw,FILTER_VALIDATE_FLOAT);
    if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))||$amount===false||!is_finite((float)$amount))throw new RuntimeException('تاریخ یا مبلغ گردش بانک نامعتبر است.');
    $q=$db->prepare('INSERT INTO bank_statement_lines(statement_date,description,reference_no,amount) VALUES(?,?,?,?)');
    $q->execute([$date,substr(trim((string)$r['description']),0,255),substr(trim((string)$r['reference_no']),0,100),(float)$amount]);
    $bankRowId=(int)$db->lastInsertId();
    $match=$db->prepare("SELECT l.id FROM journal_lines l JOIN journal_entries e ON e.id=l.entry_id JOIN accounts a ON a.id=l.account_id WHERE e.entry_date=? AND ROUND(l.debit-l.credit,2)=ROUND(?,2) AND (a.title LIKE '%بانک%' OR a.title LIKE '%صندوق%' OR a.title LIKE '%bank%' OR a.title LIKE '%cash%') AND NOT EXISTS (SELECT 1 FROM bank_statement_lines b2 WHERE b2.matched_journal_line_id=l.id) ORDER BY l.id LIMIT 1");
    $match->execute([$date,(float)$amount]);$matchedLine=$match->fetchColumn();
    if($matchedLine){$u=$db->prepare("UPDATE bank_statement_lines SET match_status='matched',matched_journal_line_id=? WHERE id=?");$u->execute([(int)$matchedLine,$bankRowId]);}
   }else throw new RuntimeException('نوع ورود اطلاعات معتبر نیست.');
   $count++;
  }
  fclose($fp);$db->commit();$message='ورود اطلاعات موفق بود. تعداد ردیف‌ها: '.$count;$success=true;
 }catch(Throwable $e){if(isset($fp)&&is_resource($fp))fclose($fp);if($db->inTransaction())$db->rollBack();error_log('CSV import error: '.$e->getMessage());$message=$e instanceof RuntimeException?$e->getMessage():'ورود اطلاعات انجام نشد؛ فایل را بررسی کنید.';}
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود اطلاعات از اکسل</title><link rel="stylesheet" href="assets/css/app.css"></head><body><main><header class="app-header"><div><h1>ورود اطلاعات از اکسل</h1><p>در Excel فایل را با فرمت CSV UTF-8 ذخیره کنید.</p></div><a href="index.php">بازگشت به داشبورد</a></header>
<?php if($message):?><p class="msg <?=$success?'':'error-msg'?>"><?=ex_h($message)?></p><?php endif;?>
<section><h2>بارگذاری اطلاعات</h2><p>قبل از ورود فایل، نسخه پشتیبان بگیرید. اگر یک ردیف خطا داشته باشد، ورود کل فایل لغو می‌شود.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=ex_h($_SESSION['exchange_csrf'])?>"><label>نوع اطلاعات <select name="import_type"><option value="products">کالاها</option><option value="transactions">خرید و فروش</option><option value="accounts">کدینگ حساب‌ها</option><option value="receivable-documents">اسناد دریافتنی</option><option value="payable-documents">اسناد پرداختنی</option><option value="bank-statement">گردش حساب بانکی</option></select></label><label>فایل CSV <input type="file" name="csv_file" accept=".csv,text/csv" required></label><button type="submit">بررسی و وارد کردن</button></form></section>
<section><h2>قالب‌های آماده</h2><p>فایل‌های خام فقط سرفصل دارند. قالب را دانلود کنید، ردیف‌های خود را در Excel اضافه کنید و با فرمت CSV UTF-8 ذخیره کنید. نام ستون‌ها را تغییر ندهید.</p><p><a href="index.php?route=data-exchange&amp;template=products">قالب کالاها</a> | <a href="index.php?route=data-exchange&amp;template=transactions">قالب خرید و فروش</a> | <a href="index.php?route=data-exchange&amp;template=accounts">قالب کدینگ حساب‌ها</a> | <a href="index.php?route=data-exchange&amp;template=receivable-documents">قالب اسناد دریافتنی</a> | <a href="index.php?route=data-exchange&amp;template=payable-documents">قالب اسناد پرداختنی</a> | <a href="index.php?route=data-exchange&amp;template=bank-statement">قالب گردش بانک</a></p>
<h3>نوع‌های مجاز در فایل خرید/فروش</h3><p>txn_type: purchase یا sale؛ payment_method: cash یا credit؛ تاریخ به شکل YYYY-MM-DD؛ برای هر کالا product_sku یکتا لازم است.</p>
<p><a class="button-link" href="index.php?route=print&amp;report=documents">چاپ/خروجی اسناد دریافتنی و پرداختنی</a> <a class="button-link" href="index.php?route=print&amp;report=bank-reconciliation">گزارش مغایرت‌گیری بانکی</a></p><p class="report-note">قالب‌های اسناد دریافتنی/پرداختنی در جدول اسناد ذخیره می‌شوند. گردش بانک فعلاً وارد می‌شود و برای مغایرت‌گیری باید تطبیق‌ها در مرحله بعد بررسی شوند. این نسخه CSV سازگار با Excel را می‌پذیرد، نه فایل XLSX مستقیم.</p></section></main></body></html>