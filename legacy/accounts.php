<?php
declare(strict_types=1);
$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) { http_response_code(500); exit('تنظیمات برنامه کامل نیست.'); }
$c = require $configFile;
$config = $c;
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4", $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_admin_credentials (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        password_hash VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $credential = $pdo->query("SELECT password_hash FROM app_admin_credentials WHERE id=1")->fetchColumn();
    // Migrate the existing config hash once, so current installations keep their existing password.
    $legacyHash = (string)($config['admin_password_hash'] ?? '');
    if (!$credential && strlen($legacyHash) >= 50) {
        $seed = $pdo->prepare("INSERT IGNORE INTO app_admin_credentials (id,password_hash) VALUES (1,?)");
        $seed->execute([$legacyHash]);
        $credential = $pdo->query("SELECT password_hash FROM app_admin_credentials WHERE id=1")->fetchColumn();
    }
} catch (Throwable $e) {
    error_log('Admin credentials initialization failed: '.$e->getMessage());
    http_response_code(500);
    exit('اتصال پایگاه داده برقرار نشد. تنظیمات config.php و دسترسی پایگاه داده را بررسی کنید.');
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$msg = '';
$isSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup_admin_password') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $msg = 'درخواست نامعتبر است؛ صفحه را تازه کنید.';
    } elseif ($credential) {
        $msg = 'رمز مدیر قبلاً تنظیم شده است. وارد شوید.';
    } else {
        $password = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (strlen($password) < 10) $msg = 'رمز باید حداقل ۱۰ نویسه داشته باشد.';
        elseif ($password !== $confirm) $msg = 'تکرار رمز با رمز جدید یکسان نیست.';
        else {
            $q = $pdo->prepare("INSERT INTO app_admin_credentials (id,password_hash) VALUES (1,?)");
            $q->execute([password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['chart_admin'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            header('Location: index.php?route=accounts'); exit;
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    if (hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        unset($_SESSION['chart_admin']);
        session_regenerate_id(true);
    }
    header('Location: index.php?route=accounts'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $msg = 'درخواست نامعتبر است.';
    } elseif (!empty($_SESSION['chart_login_block_until']) && time() < (int)$_SESSION['chart_login_block_until']) {
        $msg = 'تلاش‌های ورود زیاد بوده؛ چند دقیقه دیگر دوباره امتحان کنید.';
    } elseif ($credential && password_verify((string)($_POST['password'] ?? ''), (string)$credential)) {
        session_regenerate_id(true);
        $_SESSION['chart_admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['chart_login_failures'] = 0;
        unset($_SESSION['chart_login_block_until']);
        header('Location: index.php?route=accounts'); exit;
    } else {
        $_SESSION['chart_login_failures'] = (int)($_SESSION['chart_login_failures'] ?? 0) + 1;
        if ($_SESSION['chart_login_failures'] >= 5) {
            $_SESSION['chart_login_block_until'] = time() + 300;
            $_SESSION['chart_login_failures'] = 0;
        }
        $msg = 'رمز عبور نادرست است.';
    }
}
if (empty($_SESSION['chart_admin']) && !$credential) {
    ?>
    <!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تنظیم رمز مدیر</title><link rel="stylesheet" href="assets/css/app.css"><style>.back-to-main-menu{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;margin:8px 0;background:#174ea6;color:#fff!important;border:1px solid #174ea6;border-radius:8px;text-decoration:none;font-weight:700}.back-to-main-menu:hover{filter:brightness(.92)}.accounts-top-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.accounts-top-actions form{margin:0}.page-edge-toggle{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;margin:8px 0;background:#f3f6fb;color:#174ea6;border:1px solid #174ea6;border-radius:8px;font-weight:700;cursor:pointer}.page-edge-toggle:hover{background:#e5edfb}@media print{.back-to-main-menu,.page-edge-toggle{display:none!important}}</style></head><body><main><section><h1>تنظیم رمز مدیر</h1><p>برای شروع، یک رمز دلخواه انتخاب کنید. رمز به‌صورت امن هش می‌شود و در پایگاه داده ذخیره خواهد شد؛ نیازی به ساخت هش دستی یا ویرایش فایل تنظیمات نیست.</p><?php if($msg): ?><p class="msg error-msg"><?=htmlspecialchars($msg,ENT_QUOTES,'UTF-8')?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="action" value="setup_admin_password"><label>رمز جدید (حداقل ۱۰ نویسه)<input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label><label>تکرار رمز<input type="password" name="confirm_password" required minlength="10" autocomplete="new-password"></label><button type="submit">ذخیره رمز و ورود</button></form><p><a href="index.php">بازگشت به برنامه</a></p></section></main></body></html>
    <?php
    exit;
}
if (empty($_SESSION['chart_admin'])) {
    ?>
    <!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود مدیر کدینگ</title><link rel="stylesheet" href="assets/css/app.css"><link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#174ea6"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="حسابداری"><script src="assets/js/pwa.js" defer></script></head><body><main><section><h1>ورود مدیر کدینگ حساب‌ها</h1><?php if($msg): ?><p class="msg"><?=htmlspecialchars($msg,ENT_QUOTES,'UTF-8')?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="action" value="login"><input type="password" name="password" required autocomplete="current-password" placeholder="رمز مدیر"><button>ورود</button></form><p><a href="index.php">بازگشت به برنامه</a></p></section></main></body></html>
    <?php
    exit;
}
require dirname(__DIR__) . '/app/Models/ChartOfAccounts.php';
ensureChartOfAccounts($pdo);
function ah($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$levels = ['group'=>'گروه','general'=>'کل','subsidiary'=>'معین','detail'=>'تفصیلی'];
$types = ['asset'=>'دارایی','liability'=>'بدهی','equity'=>'حقوق مالکانه','income'=>'درآمد','expense'=>'هزینه'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'login') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('درخواست نامعتبر است؛ صفحه را تازه کنید.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'change_password') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            $storedHash = (string)$pdo->query("SELECT password_hash FROM app_admin_credentials WHERE id=1")->fetchColumn();
            if (!password_verify($currentPassword, $storedHash)) throw new RuntimeException('رمز فعلی درست نیست.');
            if (strlen($newPassword) < 10) throw new RuntimeException('رمز جدید باید حداقل ۱۰ نویسه داشته باشد.');
            if ($newPassword !== $confirmPassword) throw new RuntimeException('تکرار رمز جدید یکسان نیست.');
            if (password_verify($newPassword, $storedHash)) throw new RuntimeException('رمز جدید باید با رمز فعلی متفاوت باشد.');
            $q=$pdo->prepare("UPDATE app_admin_credentials SET password_hash=? WHERE id=1");
            $q->execute([password_hash($newPassword, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $msg='رمز عبور با موفقیت تغییر کرد.'; $isSuccess=true;
        } elseif ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim((string)($_POST['code'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));
            $level = (string)($_POST['level'] ?? '');
            $type = (string)($_POST['type'] ?? '');
            $parentId = (int)($_POST['parent_id'] ?? 0);
            if ($code === '' || !preg_match('/^[0-9]{1,20}$/', $code) || $title === '') throw new RuntimeException('کد و عنوان معتبر وارد کنید.');
            if (!isset($levels[$level], $types[$type])) throw new RuntimeException('سطح یا ماهیت حساب معتبر نیست.');
            if ($id > 0) {
                $q=$pdo->prepare('SELECT * FROM accounts WHERE id=?'); $q->execute([$id]); $old=$q->fetch();
                if (!$old) throw new RuntimeException('حساب پیدا نشد.');
                $q=$pdo->prepare('SELECT COUNT(*) FROM journal_lines WHERE account_id=?'); $q->execute([$id]); $hasJournal=(int)$q->fetchColumn()>0;
                if ($hasJournal && ($level !== $old['level'] || $type !== $old['type'] || $level !== 'detail')) throw new RuntimeException('حساب دارای سند را نمی‌توان به سطح یا ماهیت دیگری منتقل کرد.');
                $q=$pdo->prepare('SELECT COUNT(*) FROM accounts WHERE parent_id=?'); $q->execute([$id]); $hasChildren=(int)$q->fetchColumn()>0;
                if ($hasChildren && $level !== $old['level']) throw new RuntimeException('سطح حسابی که زیرحساب دارد قابل تغییر نیست.');
                if ($hasChildren && $type !== $old['type']) throw new RuntimeException('ماهیت حساب دارای زیرحساب قابل تغییر نیست.');
                if ($id >= 1 && $id <= 5 && ($level !== 'detail' || $type !== $old['type'])) throw new RuntimeException('سطح و ماهیت حساب‌های پایه سیستم قابل تغییر نیست.');
                $parentId = $parentId ?: 0;
                if ($level==='group') $parentId=0;
                else {
                    $q=$pdo->prepare('SELECT id,level,type FROM accounts WHERE id=? AND is_active=1'); $q->execute([$parentId]); $parent=$q->fetch();
                    $expected=['general'=>'group','subsidiary'=>'general','detail'=>'subsidiary'][$level] ?? '';
                    if (!$parent || $parent['level']!==$expected || $parent['type']!==$type) throw new RuntimeException('حساب بالادست باید از سطح قبلی و دارای ماهیت یکسان باشد.');
                    if ($parentId===$id) throw new RuntimeException('حساب نمی‌تواند والد خودش باشد.');
                }
                $postable = $level==='detail' ? 1 : 0;
                $q=$pdo->prepare('UPDATE accounts SET code=?,title=?,type=?,parent_id=?,level=?,is_postable=? WHERE id=?');
                $q->execute([$code,$title,$type,$parentId ?: null,$level,$postable,$id]);
                $msg='تغییرات حساب ذخیره شد.'; $isSuccess=true;
            } else {
                $parentId = $parentId ?: 0;
                if ($level==='group') {
                    $parentId=0;
                } else {
                    $q=$pdo->prepare('SELECT id,level,type FROM accounts WHERE id=? AND is_active=1'); $q->execute([$parentId]); $parent=$q->fetch();
                    $expected=['general'=>'group','subsidiary'=>'general','detail'=>'subsidiary'][$level] ?? '';
                    if (!$parent || $parent['level']!==$expected || $parent['type']!==$type) throw new RuntimeException('برای این سطح، حساب بالادست معتبر از سطح قبلی انتخاب کنید.');
                }
                $q=$pdo->prepare('INSERT INTO accounts(code,title,type,parent_id,level,is_postable,is_active) VALUES(?,?,?,?,?,?,1)');
                $q->execute([$code,$title,$type,$parentId ?: null,$level,$level==='detail'?1:0]);
                $msg='حساب جدید ثبت شد.'; $isSuccess=true;
            }
        } elseif ($action === 'delete') {
            $id=(int)($_POST['id'] ?? 0);
            if ($id >= 1 && $id <= 5) throw new RuntimeException('حساب‌های پایه سیستم قابل حذف نیستند؛ این حساب‌ها برای ثبت خودکار خرید و فروش استفاده می‌شوند.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM accounts WHERE parent_id=?'); $q->execute([$id]);
            if ((int)$q->fetchColumn()>0) throw new RuntimeException('این حساب زیرحساب دارد؛ ابتدا حساب‌های زیرمجموعه را منتقل یا غیرفعال کنید.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM journal_lines WHERE account_id=?'); $q->execute([$id]);
            if ((int)$q->fetchColumn()>0) throw new RuntimeException('این حساب در سند حسابداری استفاده شده و حذف نمی‌شود. برای حفظ سوابق، آن را غیرفعال کنید.');
            $q=$pdo->prepare('DELETE FROM accounts WHERE id=?'); $q->execute([$id]);
            $msg='حساب حذف شد.'; $isSuccess=true;
        } elseif ($action === 'toggle') {
            $id=(int)($_POST['id'] ?? 0);
            if ($id >= 1 && $id <= 5) throw new RuntimeException('حساب‌های پایه سیستم را نمی‌توان غیرفعال کرد.');
            $q=$pdo->prepare('SELECT is_active FROM accounts WHERE id=?'); $q->execute([$id]); $current=$q->fetchColumn();
            if ($current===false) throw new RuntimeException('حساب پیدا نشد.');
            $q=$pdo->prepare('UPDATE accounts SET is_active=? WHERE id=?'); $q->execute([(int)!((int)$current),$id]);
            $msg='وضعیت حساب تغییر کرد.'; $isSuccess=true;
        }
    } catch (Throwable $e) {
        error_log('Chart of accounts error: '.$e->getMessage());
        $msg=$e instanceof RuntimeException ? $e->getMessage() : 'عملیات انجام نشد. کد حساب ممکن است تکراری باشد.'; $isSuccess=false;
    }
    if ($isSuccess) { $_SESSION['flash_success']=$msg; header('Location: index.php?route=accounts'); exit; }
}
if (isset($_SESSION['flash_success'])) { $msg=(string)$_SESSION['flash_success']; $isSuccess=true; unset($_SESSION['flash_success']); }
$edit = null;
if (isset($_GET['edit'])) { $q=$pdo->prepare('SELECT * FROM accounts WHERE id=?'); $q->execute([(int)$_GET['edit']]); $edit=$q->fetch() ?: null; }
$accounts=$pdo->query("SELECT a.*,p.code parent_code,p.title parent_title FROM accounts a LEFT JOIN accounts p ON p.id=a.parent_id ORDER BY a.code")->fetchAll();
$parents=$pdo->query("SELECT id,code,title,type,level FROM accounts WHERE is_active=1 AND level IN ('group','general','subsidiary') ORDER BY code")->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کدینگ حساب‌ها</title><link rel="stylesheet" href="assets/css/app.css"></head><body><main>
<header class="app-header"><div><h1>کدینگ حساب‌ها</h1><p>گروه ← کل ← معین ← تفصیلی</p></div><div class="accounts-top-actions"><a class="back-to-main-menu" href="index.php?route=home">← بازگشت به منوی اصلی</a><button type="button" class="page-edge-toggle" id="page-edge-toggle" aria-label="رفتن به انتهای صفحه" title="رفتن به انتهای صفحه">↓ رفتن به انتهای صفحه</button><form method="post"><input type="hidden" name="csrf" value="<?=ah($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button type="submit">خروج مدیر</button></form></div></header>
<section id="change-admin-password"><h2>تغییر رمز عبور مدیر</h2><form method="post"><input type="hidden" name="csrf" value="<?=ah($_SESSION['csrf'])?>"><input type="hidden" name="action" value="change_password"><label>رمز فعلی<input type="password" name="current_password" required autocomplete="current-password"></label><label>رمز جدید (حداقل ۱۰ نویسه)<input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label><label>تکرار رمز جدید<input type="password" name="confirm_password" required minlength="10" autocomplete="new-password"></label><button type="submit">تغییر رمز</button></form><p class="muted">رمز جدید به‌صورت خودکار هش شده و در پایگاه داده ذخیره می‌شود.</p></section>
<?php if($msg && !$isSuccess): ?><p class="msg error-msg"><?=ah($msg)?></p><?php endif; ?>
<?php if($isSuccess): ?><dialog id="result-dialog" class="result-dialog" open aria-labelledby="result-title"><div class="dialog-mark" aria-hidden="true">✓</div><h2 id="result-title">ثبت شد</h2><p><?=ah($msg)?></p><button type="button" id="close-result-dialog" autofocus>متوجه شدم</button></dialog><div class="dialog-backdrop"></div><script>document.body.classList.add('dialog-visible');document.getElementById('close-result-dialog').addEventListener('click',function(){document.getElementById('result-dialog').close();document.querySelector('.dialog-backdrop').remove();document.body.classList.remove('dialog-visible')});</script><?php endif; ?>
<section><h2><?=$edit?'ویرایش حساب':'ایجاد حساب جدید'?></h2><form method="post"><input type="hidden" name="csrf" value="<?=ah($_SESSION['csrf'])?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=ah($edit['id']??0)?>">
<label>سطح حساب <select name="level" required><?php foreach($levels as $k=>$v): ?><option value="<?=$k?>" <?=($edit['level']??'detail')===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select></label>
<label>ماهیت <select name="type" required><?php foreach($types as $k=>$v): ?><option value="<?=$k?>" <?=($edit['type']??'asset')===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select></label>
<input name="code" required maxlength="20" value="<?=ah($edit['code']??'')?>" placeholder="کد حساب"><input name="title" required maxlength="120" value="<?=ah($edit['title']??'')?>" placeholder="عنوان حساب">
<label>حساب بالادست <select name="parent_id"><option value="0">بدون والد (فقط گروه)</option><?php foreach($parents as $p): ?><option value="<?=$p['id']?>" <?=((int)($edit['parent_id']??0)===(int)$p['id'])?'selected':''?>><?=ah($p['code'].' - '.$p['title'].' ('.$levels[$p['level']].')')?></option><?php endforeach; ?></select></label>
<button>ذخیره حساب</button><?php if($edit): ?><a href="index.php?route=accounts">لغو ویرایش</a><?php endif; ?></form><p class="muted">کدهای موجود حفظ می‌شوند. سطح تفصیلی قابل ثبت در سند است؛ سطوح بالاتر فقط برای دسته‌بندی هستند.</p></section>
<section><h2>فهرست حساب‌ها</h2><table><thead><tr><th>کد</th><th>عنوان</th><th>سطح</th><th>ماهیت</th><th>والد</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach($accounts as $a): ?><tr><td><?=ah($a['code'])?></td><td><?=ah($a['title'])?></td><td><?=ah($levels[$a['level']]??$a['level'])?></td><td><?=ah($types[$a['type']]??$a['type'])?></td><td><?=ah(trim(($a['parent_code']??'').' '.($a['parent_title']??'')))?></td><td><?=$a['is_active']?'فعال':'غیرفعال'?></td><td><a href="index.php?route=accounts&edit=<?=$a['id']?>">ویرایش</a> | <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=ah($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=$a['id']?>"><input type="hidden" name="action" value="toggle"><button type="submit"><?=$a['is_active']?'غیرفعال':'فعال'?></button></form> <?php if(!$a['parent_id'] && $a['level']==='group'){} ?><form method="post" style="display:inline" onsubmit="return confirm('از حذف این حساب مطمئن هستید؟')"><input type="hidden" name="csrf" value="<?=ah($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=$a['id']?>"><input type="hidden" name="action" value="delete"><button type="submit">حذف</button></form></td></tr><?php endforeach; ?>
</tbody></table></section><p><a class="back-to-main-menu" href="index.php?route=home">← بازگشت به منوی اصلی</a></p></main><script>
(function(){
 var button=document.getElementById('page-edge-toggle');
 if(!button)return;
 function atBottom(){return window.scrollY+window.innerHeight>=document.documentElement.scrollHeight-5;}
 function update(){var bottom=atBottom();button.textContent=bottom?'↑ رفتن به ابتدای صفحه':'↓ رفتن به انتهای صفحه';button.setAttribute('aria-label',bottom?'رفتن به ابتدای صفحه':'رفتن به انتهای صفحه');button.title=bottom?'رفتن به ابتدای صفحه':'رفتن به انتهای صفحه';}
 button.addEventListener('click',function(){if(atBottom()){window.scrollTo({top:0,behavior:'smooth'});}else{window.scrollTo({top:document.documentElement.scrollHeight,behavior:'smooth'});}});
 window.addEventListener('scroll',update,{passive:true});window.addEventListener('resize',update);update();
})();
</script><script src="assets/js/page-effects.js" defer></script></body></html>
