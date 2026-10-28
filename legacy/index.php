<?php
declare(strict_types=1);
$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) { http_response_code(500); exit('ابتدا config.example.php را به config.php کپی و تنظیم کنید.'); }
$c = require $configFile;
if (empty($_SESSION['chart_admin'])) { header('Location: index.php?route=accounts'); exit; }
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('Cache-Control: no-store, private');
$pdo = new PDO("mysql:host={$c['host']};dbname={$c['database']};charset=utf8mb4", $c['username'], $c['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
require dirname(__DIR__) . '/app/Models/ChartOfAccounts.php';
ensureChartOfAccounts($pdo);
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function postJournal(PDO $db, string $kind, int $id, string $date, string $desc, int $debitAccount, int $creditAccount, float $amount): void {
    if ($amount <= 0) throw new RuntimeException('مبلغ باید بیشتر از صفر باشد.');
    $q=$db->prepare('INSERT INTO journal_entries(entry_date,description,source_type,source_id) VALUES(?,?,?,?)');
    $q->execute([$date,$desc,$kind,$id]); $entry=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO journal_lines(entry_id,account_id,debit,credit) VALUES(?,?,?,?)');
    $q->execute([$entry,$debitAccount,$amount,0]); $q->execute([$entry,$creditAccount,0,$amount]);
}
// Add company identity fields to older installations without losing existing settings.
$companyColumns = [
 'company_address' => "VARCHAR(255) NOT NULL DEFAULT ''",
 'phone' => "VARCHAR(60) NOT NULL DEFAULT ''",
 'email' => "VARCHAR(190) NOT NULL DEFAULT ''",
 'logo_path' => "VARCHAR(255) NOT NULL DEFAULT ''",
 'header_text' => "VARCHAR(255) NOT NULL DEFAULT ''"
];
$existingColumns = [];
foreach ($pdo->query('SHOW COLUMNS FROM settings')->fetchAll() as $column) $existingColumns[$column['Field']] = true;
foreach ($companyColumns as $column => $definition) {
 if (!isset($existingColumns[$column])) $pdo->exec("ALTER TABLE settings ADD COLUMN `" . $column . "` " . $definition);
}
// Keep the product cost used at sale time so gross-profit reports are reproducible.
$transactionColumns = [];
foreach ($pdo->query('SHOW COLUMNS FROM transactions')->fetchAll() as $column) $transactionColumns[$column['Field']] = true;
if (!isset($transactionColumns['cost_total'])) {
    $pdo->exec('ALTER TABLE transactions ADD COLUMN cost_total DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total');
    // Historical sales did not store cost; estimate them using the current product purchase price.
    $pdo->exec("UPDATE transactions t JOIN products p ON p.id=t.product_id SET t.cost_total=ROUND(t.quantity*p.purchase_price,2) WHERE t.txn_type='sale' AND t.cost_total=0");
}
$msg='';
$isSuccess=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) throw new RuntimeException('درخواست نامعتبر است؛ صفحه را تازه کنید.');
        $action=$_POST['action'] ?? '';
        if ($action==='product') {
            $q=$pdo->prepare('INSERT INTO products(name,sku,stock,purchase_price,sale_price) VALUES(?,?,?,?,?)');
            $q->execute([trim($_POST['name']),trim($_POST['sku']) ?: null,0,(float)$_POST['purchase_price'],(float)$_POST['sale_price']]);
            $msg='ثبت شد'; $isSuccess=true;
        } elseif (in_array($action,['purchase','sale'],true)) {
            $pid=(int)$_POST['product_id']; $qty=(float)$_POST['quantity']; $price=(float)$_POST['unit_price'];
            $date=$_POST['txn_date']; $method=$_POST['payment_method']==='credit'?'credit':'cash';
            if ($pid<1 || $qty<=0 || $price<=0) throw new RuntimeException('کالا، تعداد و قیمت را درست وارد کنید.');
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT * FROM products WHERE id=? FOR UPDATE'); $q->execute([$pid]); $p=$q->fetch();
            if (!$p) throw new RuntimeException('کالا پیدا نشد.');
            if ($action==='sale' && (float)$p['stock']<$qty) throw new RuntimeException('موجودی کالا کافی نیست.');
            $total=round($qty*$price,2); $type=$action;
            $costTotal=$action==='sale'?round($qty*(float)$p['purchase_price'],2):0.0;
            $q=$pdo->prepare('INSERT INTO transactions(txn_type,txn_date,product_id,quantity,unit_price,total,cost_total,payment_method,party_name) VALUES(?,?,?,?,?,?,?,?,?)');
            $q->execute([$type,$date,$pid,$qty,$price,$total,$costTotal,$method,trim($_POST['party_name'] ?? '')]);
            $tid=(int)$pdo->lastInsertId();
            $delta=$action==='purchase'?$qty:-$qty;
            $q=$pdo->prepare('UPDATE products SET stock=stock+?, purchase_price=CASE WHEN ?=1 THEN ? ELSE purchase_price END WHERE id=?');
            $q->execute([$delta,$action==='purchase'?1:0,$price,$pid]);
            if ($action==='purchase') postJournal($pdo,$type,$tid,$date,'خرید کالا: '.$p['name'],$method==='cash'?5:5,$method==='cash'?1:2,$total);
            else postJournal($pdo,$type,$tid,$date,'فروش کالا: '.$p['name'],$method==='cash'?1:2,4,$total);
            $pdo->commit(); $msg='ثبت شد؛ سند حسابداری هم به‌صورت خودکار ایجاد شد.'; $isSuccess=true;
        } elseif ($action==='settings') {
            $logoPath = '';
            $oldSettings=$pdo->query('SELECT logo_path FROM settings WHERE id=1')->fetch() ?: [];
            $logoPath=(string)($oldSettings['logo_path'] ?? '');
            if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload=$_FILES['company_logo'];
                if ($upload['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('بارگذاری آرم ناموفق بود.');
                if ($upload['size'] > 2*1024*1024) throw new RuntimeException('حجم آرم باید حداکثر ۲ مگابایت باشد.');
                $imageInfo=@getimagesize($upload['tmp_name']);
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
                $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if (!$imageInfo || !isset($extensions[$mime])) throw new RuntimeException('فقط تصویر JPG، PNG یا WEBP مجاز است.');
                $directory=dirname(__DIR__).'/uploads/company';
                if (!is_dir($directory) && !mkdir($directory,0755,true) && !is_dir($directory)) throw new RuntimeException('پوشه بارگذاری آرم ساخته نشد.');
                $filename='logo_'.bin2hex(random_bytes(12)).'.'.$extensions[$mime];
                if (!move_uploaded_file($upload['tmp_name'],$directory.'/'.$filename)) throw new RuntimeException('ذخیره آرم روی هاست ناموفق بود.');
                $logoPath='uploads/company/'.$filename;
            }
            $q=$pdo->prepare('UPDATE settings SET business_name=?,business_type=?,currency=?,company_address=?,phone=?,email=?,logo_path=?,header_text=? WHERE id=1');
            $q->execute([trim($_POST['business_name']),$_POST['business_type'],trim($_POST['currency']),trim($_POST['company_address'] ?? ''),trim($_POST['phone'] ?? ''),trim($_POST['email'] ?? ''),$logoPath,trim($_POST['header_text'] ?? '')]);
            $msg='ثبت شد؛ تنظیمات ذخیره شد.'; $isSuccess=true;
        }
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Accounting application error: '.$e->getMessage()); $msg='عملیات انجام نشد. لطفاً اطلاعات را بررسی کنید و دوباره تلاش کنید.'; $isSuccess=false; }
    if ($isSuccess) { $_SESSION['flash_success']=$msg; header('Location: index.php'); exit; }
}
if (isset($_SESSION['flash_success'])) { $msg=(string)$_SESSION['flash_success']; $isSuccess=true; unset($_SESSION['flash_success']); }
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$products=$pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();
$reportStart = $_GET['from'] ?? date('Y-m-01');
$reportEnd = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$reportStart)) $reportStart=date('Y-m-01');
if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$reportEnd)) $reportEnd=date('Y-m-d');
$reportQuery=$pdo->prepare("SELECT txn_type,COUNT(*) AS records,COALESCE(SUM(quantity),0) AS qty,COALESCE(SUM(total),0) AS amount,COALESCE(SUM(cost_total),0) AS cost FROM transactions WHERE txn_date BETWEEN ? AND ? GROUP BY txn_type");
$reportQuery->execute([$reportStart,$reportEnd]);
$reportSummary=['purchase'=>['records'=>0,'qty'=>0,'amount'=>0,'cost'=>0],'sale'=>['records'=>0,'qty'=>0,'amount'=>0,'cost'=>0]];
foreach($reportQuery->fetchAll() as $row) $reportSummary[$row['txn_type']]=$row;
$dailyQuery=$pdo->prepare("SELECT txn_date, SUM(CASE WHEN txn_type='purchase' THEN quantity ELSE 0 END) AS purchase_qty, SUM(CASE WHEN txn_type='sale' THEN quantity ELSE 0 END) AS sale_qty, SUM(CASE WHEN txn_type='purchase' THEN total ELSE 0 END) AS purchase_amount, SUM(CASE WHEN txn_type='sale' THEN total ELSE 0 END) AS sale_amount, SUM(CASE WHEN txn_type='sale' THEN total-cost_total ELSE 0 END) AS gross_profit FROM transactions WHERE txn_date BETWEEN ? AND ? GROUP BY txn_date ORDER BY txn_date");
$dailyQuery->execute([$reportStart,$reportEnd]);
$dailyReports=$dailyQuery->fetchAll();
$grossProfit=(float)$reportSummary['sale']['amount']-(float)$reportSummary['sale']['cost'];
$settings=$pdo->query('SELECT * FROM settings WHERE id=1')->fetch() ?: ['business_name'=>'کسب‌وکار من','business_type'=>'store','currency'=>'تومان','company_address'=>'','phone'=>'','email'=>'','logo_path'=>'','header_text'=>''];
$entries=$pdo->query('SELECT e.id,e.entry_date,e.description,a.title,l.debit,l.credit FROM journal_entries e JOIN journal_lines l ON l.entry_id=e.id JOIN accounts a ON a.id=l.account_id ORDER BY e.id DESC,l.id')->fetchAll();
$balances=$pdo->query('SELECT a.code,a.title,a.type,COALESCE(SUM(l.debit),0) debit,COALESCE(SUM(l.credit),0) credit FROM accounts a LEFT JOIN journal_lines l ON l.account_id=a.id WHERE a.is_postable=1 GROUP BY a.id ORDER BY a.code')->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>حسابداری هوشمند</title>
<link rel="stylesheet" href="assets/css/app.css"><link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#174ea6"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="حسابداری"><script src="assets/js/pwa.js" defer></script></head><body><main>
<header class="app-header"><div><h1>حسابداری هوشمند</h1><p>دسترسی سریع به بخش‌های اصلی</p></div><span class="keyboard-hint">F2 کالا | F3 خرید | F4 فروش</span></header><?php if($msg && !$isSuccess): ?><p class="msg error-msg"><?=h($msg)?></p><?php endif; ?>
<?php if($isSuccess): ?><dialog id="result-dialog" class="result-dialog" open aria-labelledby="result-title"><div class="dialog-mark" aria-hidden="true">✓</div><h2 id="result-title">ثبت شد</h2><p><?=h($msg)?></p><button type="button" id="close-result-dialog" autofocus>متوجه شدم</button></dialog><div class="dialog-backdrop"></div><script>document.body.classList.add('dialog-visible');document.getElementById('close-result-dialog').addEventListener('click',function(){document.getElementById('result-dialog').close();document.querySelector('.dialog-backdrop').remove();document.body.classList.remove('dialog-visible')});</script><?php endif; ?><nav class="dashboard-nav" aria-label="منوی سریع"><a class="nav-card" href="#product"><span class="nav-icon">[ک]</span><strong>تعریف کالا</strong><small>F2</small></a><a class="nav-card" href="#purchase"><span class="nav-icon">[خ]</span><strong>خرید کالا</strong><small>F3</small></a><a class="nav-card sale-card" href="#sale"><span class="nav-icon">[ف]</span><strong>فروش کالا</strong><small>F4</small></a><a class="nav-card" href="index.php?route=accounts"><strong>کدینگ حساب‌ها</strong><small>گروه، کل، معین، تفصیلی</small></a><a class="nav-card" href="#settings"><strong>تنظیمات شرکت</strong><small>آرم و تلفن</small></a><a class="nav-card" href="#journal"><strong>دفتر روزنامه</strong></a><a class="nav-card" href="#balances"><strong>تراز آزمایشی</strong></a><a class="nav-card" href="#reports"><strong>گزارش خرید و فروش</strong><small>سود روزانه و نمودار</small></a><a class="nav-card" href="index.php?route=fiscal-years"><strong>سال مالی</strong></a><a class="nav-card" href="index.php?route=print"><strong>گزارش و خروجی</strong></a><a class="nav-card" href="index.php?route=data-exchange"><strong>ورود اطلاعات از اکسل</strong></a></nav>
<section id="settings"><h2>تنظیمات شرکت و سربرگ</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="settings"><input name="business_name" value="<?=h($settings['business_name'])?>" placeholder="نام کسب‌وکار"><select name="business_type"><?php foreach(['store'=>'فروشگاه','company'=>'شرکت','personal'=>'شخصی','custom'=>'سفارشی'] as $k=>$v): ?><option value="<?=$k?>" <?=$settings['business_type']===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select><input name="currency" value="<?=h($settings['currency'])?>" placeholder="واحد پول"><input name="company_address" value="<?=h($settings['company_address'] ?? '')?>" placeholder="نشانی شرکت"><input name="phone" type="tel" value="<?=h($settings['phone'] ?? '')?>" placeholder="تلفن تماس"><input name="email" type="email" value="<?=h($settings['email'] ?? '')?>" placeholder="ایمیل شرکت"><input name="header_text" value="<?=h($settings['header_text'] ?? '')?>" placeholder="متن دلخواه سربرگ / شناسه ملی"><label class="upload-label">آرم یا لوگوی شرکت (JPG/PNG/WEBP، حداکثر ۲ مگابایت)<input type="file" name="company_logo" accept="image/jpeg,image/png,image/webp"></label><?php if(!empty($settings['logo_path'])): ?><p>آرم فعلی: <img class="company-logo-preview" src="<?=h($settings['logo_path'])?>" alt="آرم شرکت"></p><?php endif; ?><button>ذخیره مشخصات شرکت</button></form></section>
<section id="product"><h2>تعریف کالا</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="product"><input name="name" required placeholder="نام کالا"><input name="sku" placeholder="کد کالا"><input type="number" step=".01" min="0" name="purchase_price" placeholder="قیمت خرید"><input type="number" step=".01" min="0" name="sale_price" placeholder="قیمت فروش"><button>ثبت کالا</button></form></section>
<?php foreach(['purchase'=>'خرید کالا','sale'=>'فروش کالا'] as $act=>$label): ?><section id="<?=$act?>"><h2><?=$label?></h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="<?=$act?>"><input type="date" name="txn_date" required value="<?=date('Y-m-d')?>"><?php if($act==='sale'): ?><div class="product-autocomplete" data-products="<?=h(json_encode(array_map(static function($p){return ['id'=>(int)$p['id'],'name'=>(string)$p['name'],'stock'=>(string)$p['stock'],'sku'=>(string)($p['sku']??'')];},$products),JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>"><input type="text" class="product-search" placeholder="نام یا کد کالا را تایپ کنید..." autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" required><input type="hidden" name="product_id" class="product-id" required><div class="product-suggestions" role="listbox" hidden></div><small class="product-search-hint">با نوشتن چند حرف، کالاهای مشابه نمایش داده می‌شوند.</small></div><?php else: ?><select name="product_id" required><option value="">انتخاب کالا</option><?php foreach($products as $p): ?><option value="<?=$p['id']?>"><?=h($p['name'])?> (موجودی: <?=h($p['stock'])?>)</option><?php endforeach; ?></select><?php endif; ?><input type="number" step=".001" min=".001" name="quantity" required placeholder="تعداد"><input type="number" step=".01" min=".01" name="unit_price" required placeholder="قیمت واحد"><select name="payment_method"><option value="cash">نقدی</option><option value="credit">نسیه</option></select><input name="party_name" placeholder="نام مشتری/تأمین‌کننده"><button>ثبت <?=$label?></button></form></section><?php endforeach; ?>
<script>
(function(){
  'use strict';
  document.querySelectorAll('.product-autocomplete').forEach(function(box){
    var products=[];
    try { products=JSON.parse(box.getAttribute('data-products')||'[]'); } catch(e) {}
    var input=box.querySelector('.product-search'), hidden=box.querySelector('.product-id'), list=box.querySelector('.product-suggestions'), active=-1;
    function close(){list.hidden=true;input.setAttribute('aria-expanded','false');active=-1;}
    function choose(p){input.value=p.name+(p.sku?' ('+p.sku+')':'')+' — موجودی: '+p.stock;hidden.value=String(p.id);close();input.setCustomValidity('');}
    function render(){
      var q=input.value.trim().toLocaleLowerCase();
      hidden.value='';
      list.innerHTML='';
      if(!q){close();return;}
      var matches=products.filter(function(p){return (p.name+' '+p.sku+' '+p.id).toLocaleLowerCase().indexOf(q)!==-1;}).slice(0,8);
      if(!matches.length){var empty=document.createElement('div');empty.className='product-suggestion-empty';empty.textContent='کالایی پیدا نشد';list.appendChild(empty);}
      matches.forEach(function(p){
        var item=document.createElement('button');item.type='button';item.className='product-suggestion';item.setAttribute('role','option');
        var name=document.createElement('strong');name.textContent=p.name;item.appendChild(name);
        var detail=document.createElement('small');detail.textContent=(p.sku?'کد: '+p.sku+' · ':'')+'موجودی: '+p.stock;item.appendChild(detail);
        item.addEventListener('mousedown',function(e){e.preventDefault();choose(p);});
        list.appendChild(item);
      });
      list.hidden=false;input.setAttribute('aria-expanded','true');
    }
    input.addEventListener('input',function(){hidden.value='';render();});
    input.addEventListener('keydown',function(e){
      var items=list.querySelectorAll('.product-suggestion');
      if(e.key==='ArrowDown'&&!list.hidden&&items.length){e.preventDefault();active=Math.min(active+1,items.length-1);items.forEach(function(el,i){el.classList.toggle('active',i===active);});}
      else if(e.key==='ArrowUp'&&!list.hidden&&items.length){e.preventDefault();active=Math.max(active-1,0);items.forEach(function(el,i){el.classList.toggle('active',i===active);});}
      else if(e.key==='Enter'&&!list.hidden&&items.length){e.preventDefault();items[Math.max(active,0)].dispatchEvent(new MouseEvent('mousedown',{bubbles:true}));}
      else if(e.key==='Escape'){close();}
    });
    input.addEventListener('blur',function(){setTimeout(close,120);});
    box.closest('form').addEventListener('submit',function(e){
      if(!hidden.value){e.preventDefault();input.setCustomValidity('لطفاً یک کالا را از فهرست پیشنهادها انتخاب کنید.');input.reportValidity();input.focus();}
    });
    input.addEventListener('input',function(){input.setCustomValidity('');});
  });
})();
</script>
<section id="reports"><h2>گزارش خرید و فروش و سود</h2>
<form method="get" class="report-filter"><label>از تاریخ <input type="date" name="from" value="<?=h($reportStart)?>" required></label><label>تا تاریخ <input type="date" name="to" value="<?=h($reportEnd)?>" required></label><button type="submit">نمایش گزارش</button></form>
<div class="report-cards">
<div class="report-card"><span>تعداد کالاهای خریداری‌شده</span><strong><?=number_format((float)$reportSummary['purchase']['qty'],3)?></strong><small>در <?=number_format((int)$reportSummary['purchase']['records'])?> فاکتور/ثبت</small></div>
<div class="report-card"><span>تعداد کالاهای فروخته‌شده</span><strong><?=number_format((float)$reportSummary['sale']['qty'],3)?></strong><small>در <?=number_format((int)$reportSummary['sale']['records'])?> فاکتور/ثبت</small></div>
<div class="report-card"><span>مبلغ کل خرید</span><strong><?=number_format((float)$reportSummary['purchase']['amount'],0)?> <?=h($settings['currency'] ?? 'تومان')?></strong></div>
<div class="report-card"><span>مبلغ کل فروش</span><strong><?=number_format((float)$reportSummary['sale']['amount'],0)?> <?=h($settings['currency'] ?? 'تومان')?></strong></div>
<div class="report-card <?= $grossProfit<0?'report-loss':'report-profit' ?>"><span>سود ناخالص برآوردی</span><strong><?=number_format($grossProfit,0)?> <?=h($settings['currency'] ?? 'تومان')?></strong><small>فروش منهای بهای خرید کالای فروخته‌شده</small></div>
</div>
<div class="actions no-print"><a class="button-link" href="index.php?route=print&amp;report=daily&amp;from=<?=h($reportStart)?>&amp;to=<?=h($reportEnd)?>">چاپ خلاصه سود روزانه</a><a class="button-link" href="index.php?route=print&amp;report=daily&amp;from=<?=h($reportStart)?>&amp;to=<?=h($reportEnd)?>&amp;format=csv">خروجی اکسل سود روزانه</a><a class="button-link" href="index.php?route=print&amp;report=transactions&amp;from=<?=h($reportStart)?>&amp;to=<?=h($reportEnd)?>&amp;format=csv">خروجی خرید و فروش</a></div><h3>نمودار روزانه</h3><div class="report-chart-wrap"><canvas id="trade-chart" height="260" aria-label="نمودار خرید، فروش و سود روزانه" role="img"></canvas></div>
<table><thead><tr><th>تاریخ</th><th>تعداد خرید</th><th>تعداد فروش</th><th>مبلغ خرید</th><th>مبلغ فروش</th><th>سود ناخالص</th></tr></thead><tbody>
<?php if(!$dailyReports): ?><tr><td colspan="6">در این بازه خرید یا فروشی ثبت نشده است.</td></tr><?php endif; ?>
<?php foreach($dailyReports as $day): ?><tr><td><?=h($day['txn_date'])?></td><td><?=number_format((float)$day['purchase_qty'],3)?></td><td><?=number_format((float)$day['sale_qty'],3)?></td><td><?=number_format((float)$day['purchase_amount'],0)?></td><td><?=number_format((float)$day['sale_amount'],0)?></td><td><?=number_format((float)$day['gross_profit'],0)?></td></tr><?php endforeach; ?>
</tbody></table>
<p class="report-note">نکته: سود این گزارش، سود ناخالص است و هزینه‌هایی مثل اجاره، حقوق و مالیات را کم نمی‌کند. برای فروش‌های ثبت‌شده قبل از فعال‌شدن این قابلیت، بهای خرید با قیمت خرید فعلی کالا تخمین زده می‌شود؛ بنابراین سود تاریخی ممکن است دقیق نباشد.</p>
<script>
(function(){
 var rows=<?=json_encode(array_map(static function($d){return ['date'=>$d['txn_date'],'purchase'=>(float)$d['purchase_amount'],'sale'=>(float)$d['sale_amount'],'profit'=>(float)$d['gross_profit']];},$dailyReports),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;
 var canvas=document.getElementById('trade-chart');if(!canvas)return;var ctx=canvas.getContext('2d');
 function draw(){var w=canvas.clientWidth||600,h=260,dpr=window.devicePixelRatio||1;canvas.width=w*dpr;canvas.height=h*dpr;ctx.scale(dpr,dpr);ctx.clearRect(0,0,w,h);
 if(!rows.length){ctx.fillStyle='#667085';ctx.font='14px Tahoma';ctx.textAlign='center';ctx.fillText('اطلاعاتی برای رسم نمودار وجود ندارد',w/2,h/2);return;}
 var max=Math.max(1,...rows.map(function(r){return Math.max(r.purchase,r.sale,Math.abs(r.profit));}));var left=42,right=12,top=20,bottom=48,plotW=w-left-right,plotH=h-top-bottom,slot=plotW/rows.length,bw=Math.max(3,Math.min(18,slot*.22));
 ctx.font='10px Tahoma';ctx.textAlign='right';ctx.fillStyle='#667085';
 for(var i=0;i<=4;i++){var y=top+plotH*i/4;ctx.strokeStyle='#e5eaf2';ctx.beginPath();ctx.moveTo(left,y);ctx.lineTo(w-right,y);ctx.stroke();ctx.fillText(Math.round(max*(4-i)/4).toLocaleString('fa-IR'),left-5,y+4);}
 rows.forEach(function(r,i){var x=left+slot*(i+.5);[[r.purchase,'#64748b',-bw],[r.sale,'#2563eb',0],[Math.max(0,r.profit),'#16a34a',bw]].forEach(function(a){var bh=a[0]/max*plotH;ctx.fillStyle=a[1];ctx.fillRect(x+a[2]-bw/2,top+plotH-bh,bw,bh);});if(rows.length<=12||i%Math.ceil(rows.length/12)===0){ctx.save();ctx.translate(x,h-8);ctx.rotate(-.35);ctx.textAlign='right';ctx.fillStyle='#475467';ctx.fillText(r.date.slice(5),0,0);ctx.restore();}});
 }
 draw();window.addEventListener('resize',draw);
})();
</script></section>
<section id="balances"><h2>تراز آزمایشی ساده</h2><table><tr><th>کد</th><th>حساب</th><th>بدهکار</th><th>بستانکار</th></tr><?php foreach($balances as $b): ?><tr><td><?=h($b['code'])?></td><td><?=h($b['title'])?></td><td><?=number_format((float)$b['debit'],2)?></td><td><?=number_format((float)$b['credit'],2)?></td></tr><?php endforeach; ?></table></section>
<section id="journal"><h2>دفتر روزنامه (اسناد خودکار)</h2><table><tr><th>شماره سند</th><th>تاریخ</th><th>شرح</th><th>حساب</th><th>بدهکار</th><th>بستانکار</th></tr><?php foreach($entries as $e): ?><tr><td><?=h($e['id'])?></td><td><?=h($e['entry_date'])?></td><td><?=h($e['description'])?></td><td><?=h($e['title'])?></td><td><?=number_format((float)$e['debit'],2)?></td><td><?=number_format((float)$e['credit'],2)?></td></tr><?php endforeach; ?></table></section>
</main><script src="assets/js/shortcuts.js"></script></body></html>