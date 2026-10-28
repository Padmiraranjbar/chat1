<?php
declare(strict_types=1);
$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) { http_response_code(500); exit('تنظیمات برنامه کامل نیست.'); }
$c = require $configFile;
$adminHash = (string)($c['admin_password_hash'] ?? '');
if (strlen($adminHash) < 50) {
    http_response_code(503);
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>راه‌اندازی دسترسی مدیر</title><body style="font-family:tahoma;max-width:760px;margin:40px auto;padding:20px"><h2>راه‌اندازی دسترسی مدیر</h2><p>برای جلوگیری از دسترسی عمومی به کدینگ حساب‌ها، ابتدا مقدار <code>admin_password_hash</code> را در فایل محرمانه <code>config.php</code> تنظیم کنید.</p><p>در PHP از تابع <code>password_hash($password, PASSWORD_DEFAULT)</code> برای ساخت هش رمز مدیر استفاده کنید؛ فقط هش را در فایل تنظیمات قرار دهید.</p><p>هش خروجی را در تنظیمات قرار دهید. خود رمز را در GitHub قرار ندهید.</p><p><a href="index.php">بازگشت</a></p></body></html>';
    exit;
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$msg = '';
if (isset($_GET['logout'])) {
    $_SESSION['chart_admin'] = false;
    header('Location: index.php?route=accounts'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $msg = 'درخواست نامعتبر است.';
    } elseif (!empty($_SESSION['chart_login_block_until']) && time() < (int)$_SESSION['chart_login_block_until']) {
        $msg = 'تلاش‌های ورود زیاد بوده؛ چند دقیقه دیگر دوباره امتحان کنید.';
    } elseif (password_verify((string)($_POST['password'] ?? ''), $adminHash)) {
        session_regenerate_id(true);
        $_SESSION['chart_admin'] = true;
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
if (empty($_SESSION['chart_admin'])) {
    ?>
    <!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود مدیر کدینگ</title><link rel="stylesheet" href="assets/css/app.css"></head><body><main><section><h1>ورود مدیر کدینگ حساب‌ها</h1><?php if($msg): ?><p class="msg"><?=htmlspecialchars($msg,ENT_QUOTES,'UTF-8')?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="action" value="login"><input type="password" name="password" required autocomplete="current-password" placeholder="رمز مدیر"><button>ورود</button></form><p><a href="index.php">بازگشت به برنامه</a></p></section></main></body></html>
    <?php
    exit;
}
$config = $c;
$pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4", $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
require dirname(__DIR__) . '/app/Models/ChartOfAccounts.php';
ensureChartOfAccounts($pdo);
function ah($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$levels = ['group'=>'گروه','general'=>'کل','subsidiary'=>'معین','detail'=>'تفصیلی'];
$types = ['asset'=>'دارایی','liability'=>'بدهی','equity'=>'حقوق مالکانه','income'=>'درآمد','expense'=>'هزینه'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'login') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('درخواست نامعتبر است؛ صفحه را تازه کنید.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim((string)($_POST['code'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));
            $level = (string)($_POST['level'] ?? '');
            $type = (string)($_POST['type'] ?? '');
            $parentId = (int)($_POST['parent_id'] ?? 0);
            if ($code === '' || !preg_match('/^[0-9A-Za-z._-]{1,20}$/', $code) || $title === '') throw new RuntimeException('کد و عنوان معتبر وارد کنید.');
            if (!isset($levels[$level], $types[$type])) throw new RuntimeException('سطح یا ماهیت حساب معتبر نیست.');
            if ($id > 0) {
                $q=$pdo->prepare('SELECT * FROM accounts WHERE id=?'); $q->execute([$id]); $old=$q->fetch();
                if (!$old) throw new RuntimeException('حساب پیدا نشد.');
                $q=$pdo->prepare('SELECT COUNT(*) FROM journal_lines WHERE account_id=?'); $q->execute([$id]); $hasJournal=(int)$q->fetchColumn()>0;
                if ($hasJournal && ($level !== $old['level'] || $type !== $old['type'] || $level !== 'detail')) throw new RuntimeException('حساب دارای سند را نمی‌توان به سطح یا ماهیت دیگری منتقل کرد.');
                $q=$pdo->prepare('SELECT COUNT(*) FROM accounts WHERE parent_id=?'); $q->execute([$id]); $hasChildren=(int)$q->fetchColumn()>0;
                if ($hasChildren && $level==='detail') throw new RuntimeException('حسابی که زیرحساب دارد نمی‌تواند تفصیلی شود.');
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
                $msg='حساب ویرایش شد.';
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
                $msg='حساب جدید ایجاد شد.';
            }
        } elseif ($action === 'delete') {
            $id=(int)($_POST['id'] ?? 0);
            if ($id >= 1 && $id <= 5) throw new RuntimeException('حساب‌های پایه سیستم قابل حذف نیستند؛ این حساب‌ها برای ثبت خودکار خرید و فروش استفاده می‌شوند.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM accounts WHERE parent_id=?'); $q->execute([$id]);
            if ((int)$q->fetchColumn()>0) throw new RuntimeException('این حساب زیرحساب دارد؛ ابتدا حساب‌های زیرمجموعه را منتقل یا غیرفعال کنید.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM journal_lines WHERE account_id=?'); $q->execute([$id]);
            if ((int)$q->fetchColumn()>0) throw new RuntimeException('این حساب در سند حسابداری استفاده شده و حذف نمی‌شود. برای حفظ سوابق، آن را غیرفعال کنید.');
            $q=$pdo->prepare('DELETE FROM accounts WHERE id=?'); $q->execute([$id]);
            $msg='حساب حذف شد.';
        } elseif ($action === 'toggle') {
            $id=(int)($_POST['id'] ?? 0);
            if ($id >= 1 && $id <= 5) throw new RuntimeException('حساب‌های پایه سیستم را نمی‌توان غیرفعال کرد.');
            $q=$pdo->prepare('SELECT is_active FROM accounts WHERE id=?'); $q->execute([$id]); $current=$q->fetchColumn();
            if ($current===false) throw new RuntimeException('حساب پیدا نشد.');
            $q=$pdo->prepare('UPDATE accounts SET is_active=? WHERE id=?'); $q->execute([(int)!((int)$current),$id]);
            $msg='وضعیت حساب تغییر کرد.';
        }
    } catch (Throwable $e) {
        error_log('Chart of accounts error: '.$e->getMessage());
        $msg=$e instanceof RuntimeException ? $e->getMessage() : 'عملیات انجام نشد. کد حساب ممکن است تکراری باشد.';
    }
}
$edit = null;
if (isset($_GET['edit'])) { $q=$pdo->prepare('SELECT * FROM accounts WHERE id=?'); $q->execute([(int)$_GET['edit']]); $edit=$q->fetch() ?: null; }
$accounts=$pdo->query("SELECT a.*,p.code parent_code,p.title parent_title FROM accounts a LEFT JOIN accounts p ON p.id=a.parent_id ORDER BY a.code")->fetchAll();
$parents=$pdo->query("SELECT id,code,title,type,level FROM accounts WHERE is_active=1 AND level IN ('group','general','subsidiary') ORDER BY code")->fetchAll();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کدینگ حساب‌ها</title><link rel="stylesheet" href="assets/css/app.css"></head><body><main>
<header class="app-header"><div><h1>کدینگ حساب‌ها</h1><p>گروه ← کل ← معین ← تفصیلی</p></div><a href="index.php?route=accounts&logout=1">خروج مدیر</a></header>
<?php if($msg): ?><p class="msg"><?=ah($msg)?></p><?php endif; ?>
<section><h2><?=$edit?'ویرایش حساب':'ایجاد حساب جدید'?></h2><form method="post"><input type="hidden" name="csrf" value="<?=ah($_SESSION['csrf'])?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=ah($edit['id']??0)?>">
<label>سطح حساب <select name="level" required><?php foreach($levels as $k=>$v): ?><option value="<?=$k?>" <?=($edit['level']??'detail')===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select></label>
<label>ماهیت <select name="type" required><?php foreach($types as $k=>$v): ?><option value="<?=$k?>" <?=($edit['type']??'asset')===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select></label>
<input name="code" required maxlength="20" value="<?=ah($edit['code']??'')?>" placeholder="کد حساب"><input name="title" required maxlength="120" value="<?=ah($edit['title']??'')?>" placeholder="عنوان حساب">
<label>حساب بالادست <select name="parent_id"><option value="0">بدون والد (فقط گروه)</option><?php foreach($parents as $p): ?><option value="<?=$p['id']?>" <?=((int)($edit['parent_id']??0)===(int)$p['id'])?'selected':''?>><?=ah($p['code'].' - '.$p['title'].' ('.$levels[$p['level']].')')?></option><?php endforeach; ?></select></label>
<button>ذخیره حساب</button><?php if($edit): ?><a href="index.php?route=accounts">لغو ویرایش</a><?php endif; ?></form><p class="muted">کدهای موجود حفظ می‌شوند. سطح تفصیلی قابل ثبت در سند است؛ سطوح بالاتر فقط برای دسته‌بندی هستند.</p></section>
<section><h2>فهرست حساب‌ها</h2><table><thead><tr><th>کد</th><th>عنوان</th><th>سطح</th><th>ماهیت</th><th>والد</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach($accounts as $a): ?><tr><td><?=ah($a['code'])?></td><td><?=ah($a['title'])?></td><td><?=ah($levels[$a['level']]??$a['level'])?></td><td><?=ah($types[$a['type']]??$a['type'])?></td><td><?=ah(trim(($a['parent_code']??'').' '.($a['parent_title']??'')))?></td><td><?=$a['is_active']?'فعال':'غیرفعال'?></td><td><a href="index.php?route=accounts&edit=<?=$a['id']?>">ویرایش</a> | <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=ah($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=$a['id']?>"><input type="hidden" name="action" value="toggle"><button type="submit"><?=$a['is_active']?'غیرفعال':'فعال'?></button></form> <?php if(!$a['parent_id'] && $a['level']==='group'){} ?><form method="post" style="display:inline" onsubmit="return confirm('از حذف این حساب مطمئن هستید؟')"><input type="hidden" name="csrf" value="<?=ah($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=$a['id']?>"><input type="hidden" name="action" value="delete"><button type="submit">حذف</button></form></td></tr><?php endforeach; ?>
</tbody></table></section><p><a href="index.php">بازگشت به داشبورد</a></p></main></body></html>
