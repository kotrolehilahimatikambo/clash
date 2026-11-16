<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);

$tab = ($_GET['tab'] ?? '') === 'hating' ? 'hating' : 'haters';

$hatersStmt = $pdo->prepare(
    "SELECT u.id, u.username, u.display_name, u.profile_photo,
            EXISTS(SELECT 1 FROM haters h2 WHERE h2.hater_id = ? AND h2.target_id = u.id) AS i_hate
     FROM haters h JOIN users u ON u.id = h.hater_id
     WHERE h.target_id = ? ORDER BY h.created_at DESC"
);
$hatersStmt->execute([$me['id'], $me['id']]);
$haters = $hatersStmt->fetchAll();

$hatingStmt = $pdo->prepare(
    "SELECT u.id, u.username, u.display_name, u.profile_photo
     FROM haters h JOIN users u ON u.id = h.target_id
     WHERE h.hater_id = ? ORDER BY h.created_at DESC"
);
$hatingStmt->execute([$me['id']]);
$hating = $hatingStmt->fetchAll();

$pageTitle = 'Haters';
$navActive = 'haters';
require_once __DIR__ . '/includes/header.php';
?>
<?= page_head('Haters & Haine', null, 'fa-ghost') ?>

<div class="tabs" role="tablist">
    <a href="?tab=haters" class="tab <?= $tab === 'haters' ? 'tab--active' : '' ?>" role="tab">Mes haters (<?= count($haters) ?>)</a>
    <a href="?tab=hating" class="tab <?= $tab === 'hating' ? 'tab--active' : '' ?>" role="tab">Je hais (<?= count($hating) ?>)</a>
</div>

<?php $list = $tab === 'hating' ? $hating : $haters; ?>
<?php if (empty($list)): ?>
    <div class="empty-state"><i class="fa-solid fa-ghost"></i>
        <p><?= $tab === 'hating' ? 'Tu ne hais personne pour l’instant.' : 'Personne ne te hait encore. Provoque davantage.' ?></p>
        <?php if ($tab === 'hating'): ?><a href="<?= BASE_URL ?>/discover.php" class="btn btn--primary">Trouver des cibles</a><?php endif; ?>
    </div>
<?php endif; ?>
<?php foreach ($list as $u): ?>
    <?php if ($tab === 'hating'): ?>
        <?= user_row($u, hater_button((int) $u['id'], true, '', true)) ?>
    <?php else: ?>
        <?= user_row($u, hater_button((int) $u['id'], (bool) $u['i_hate']), $u['i_hate'] ? 'tu le hais aussi' : '') ?>
    <?php endif; ?>
<?php endforeach; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
