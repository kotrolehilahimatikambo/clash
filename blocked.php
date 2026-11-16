<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);

$stmt = $pdo->prepare(
    "SELECT u.id, u.username, u.display_name, u.profile_photo
     FROM blocks b JOIN users u ON u.id = b.blocked_id
     WHERE b.blocker_id = ? ORDER BY b.created_at DESC"
);
$stmt->execute([$me['id']]);
$blocked = $stmt->fetchAll();

$pageTitle = 'Utilisateurs bloqués';
$navActive = 'settings';
require_once __DIR__ . '/includes/header.php';
?>
<?= page_head('Utilisateurs bloqués', BASE_URL . '/edit_profile.php', 'fa-ban') ?>
<p class="muted pad">Ces personnes ne peuvent plus t’écrire, te défier ni voir tes publications.</p>

<?php if (empty($blocked)): ?>
    <div class="empty-state"><i class="fa-solid fa-ban"></i><p>Tu n’as bloqué personne pour l’instant.</p></div>
<?php endif; ?>
<?php foreach ($blocked as $u): ?>
    <?= user_row($u, '<button type="button" class="btn btn--small btn--outline unblock-btn" data-target="' . (int) $u['id'] . '"><i class="fa-solid fa-lock-open"></i> Débloquer</button>') ?>
<?php endforeach; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
