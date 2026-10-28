<?php
declare(strict_types=1);

/**
 * Adds the four-level chart structure without changing existing account IDs.
 * Existing posting accounts become detail accounts and remain linked to journal_lines.
 */
function ensureChartOfAccounts(PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM accounts')->fetchAll() as $column) {
        $columns[$column['Field']] = $column;
    }
    if (stripos((string)($columns['id']['Extra'] ?? ''), 'auto_increment') === false) $pdo->exec('ALTER TABLE accounts MODIFY id INT NOT NULL AUTO_INCREMENT');
    if (!isset($columns['parent_id'])) $pdo->exec('ALTER TABLE accounts ADD COLUMN parent_id INT NULL');
    if (!isset($columns['level'])) $pdo->exec("ALTER TABLE accounts ADD COLUMN level ENUM('group','general','subsidiary','detail') NOT NULL DEFAULT 'detail'");
    if (!isset($columns['is_postable'])) $pdo->exec('ALTER TABLE accounts ADD COLUMN is_postable TINYINT(1) NOT NULL DEFAULT 1');
    if (!isset($columns['is_active'])) $pdo->exec('ALTER TABLE accounts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');

    // Existing account IDs are retained; only missing parent levels are created.
    $groups = [
        'asset' => ['1','دارایی‌ها','دارایی‌های جاری','موجودی نقد و بانک'],
        'liability' => ['2','بدهی‌ها','بدهی‌های جاری','حساب‌های پرداختنی'],
        'equity' => ['3','حقوق مالکانه','حقوق مالکانه','سرمایه'],
        'income' => ['4','درآمدها','درآمدهای عملیاتی','فروش کالا'],
        'expense' => ['5','هزینه‌ها','بهای تمام‌شده و هزینه‌های عملیاتی','خرید کالا']
    ];
    foreach ($groups as $type => [$groupCode, $groupTitle, $generalTitle, $subsidiaryTitle]) {
        $generalCode = $groupCode . '0';
        $subsidiaryCode = $groupCode . '00';
        $q = $pdo->prepare('INSERT INTO accounts (code,title,type,parent_id,level,is_postable,is_active) VALUES (?,?,?,NULL,\'group\',0,1) ON DUPLICATE KEY UPDATE title=VALUES(title)');
        $q->execute([$groupCode,$groupTitle,$type]);
        $q = $pdo->prepare('SELECT id FROM accounts WHERE code=?');
        $q->execute([$groupCode]); $groupId = (int)$q->fetchColumn();

        $q = $pdo->prepare('INSERT INTO accounts (code,title,type,parent_id,level,is_postable,is_active) VALUES (?,?,?, ?,\'general\',0,1) ON DUPLICATE KEY UPDATE title=VALUES(title), parent_id=VALUES(parent_id)');
        $q->execute([$generalCode,$generalTitle,$type,$groupId]);
        $q = $pdo->prepare('SELECT id FROM accounts WHERE code=?');
        $q->execute([$generalCode]); $generalId = (int)$q->fetchColumn();

        $q = $pdo->prepare('INSERT INTO accounts (code,title,type,parent_id,level,is_postable,is_active) VALUES (?,?,?, ?,\'subsidiary\',0,1) ON DUPLICATE KEY UPDATE title=VALUES(title), parent_id=VALUES(parent_id)');
        $q->execute([$subsidiaryCode,$subsidiaryTitle,$type,$generalId]);
        $q = $pdo->prepare('SELECT id FROM accounts WHERE code=?');
        $q->execute([$subsidiaryCode]); $subsidiaryId = (int)$q->fetchColumn();

        $q = $pdo->prepare("UPDATE accounts SET parent_id=?, level='detail', is_postable=1 WHERE type=? AND level='detail' AND code NOT IN (?,?,?) AND (parent_id IS NULL OR parent_id=0)");
        $q->execute([$subsidiaryId,$type,$groupCode,$generalCode,$subsidiaryCode]);
    }
}
