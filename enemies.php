<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);

$pendingReceivedStmt = $pdo->prepare(
    "SELECT u.id, u.username, u.display_name, u.profile_photo
     FROM enemies e JOIN users u ON u.id = e.requester_id
     WHERE e.target_id = ? AND e.status = 'pending' ORDER BY e.created_at DESC"
);
$pendingReceivedStmt->execute([$me['id']]);
$pendingReceived = $pendingReceivedStmt->fetchAll();

$pendingSentStmt = $pdo->prepare(
    "SELECT u.id, u.username, u.display_name, u.profile_photo
     FROM enemies e JOIN users u ON u.id = e.target_id
     WHERE e.requester_id = ? AND e.status = 'pending' ORDER BY e.created_at DESC"
);
$pendingSentStmt->execute([$me['id']]);
$pendingSent = $pendingSentStmt->fetchAll();

$confirmedStmt = $pdo->prepare(
    "SELECT u.id, u.username, u.display_name, u.profile_photo
     FROM enemies e
     JOIN users u ON u.id = (CASE WHEN e.requester_id = ? THEN e.target_id ELSE e.requester_id END)
     WHERE e.status = 'accepted' AND (e.requester_id = ? OR e.target_id = ?)
     ORDER BY e.responded_at DESC"
);
$confirmedStmt->execute([$me['id'], $me['id'], $me['id']]);
$confirmed = $confirmedStmt->fetchAll();

$pageTitle = 'Mes ennemis';
$navActive = 'enemies';
require_once __DIR__ . '/includes/header.php';
?>
<?= page_head('Mes ennemis', null, 'fa-skull-crossbones') ?>

<?php if ($pendingReceived): ?>
    <h2 class="section-label">Déclarations de guerre reçues <span class="pill pill--danger"><?= count($pendingReceived) ?></span></h2>
    <?php foreach ($pendingReceived as $u): ?>
        <?= user_row($u,
            '<button type="button" class="btn btn--small btn--danger enemy-action" data-target="' . (int) $u['id'] . '" data-action="accept">Accepter</button>'
          . '<button type="button" class="btn btn--small btn--outline enemy-action" data-target="' . (int) $u['id'] . '" data-action="cancel">Refuser</button>') ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($pendingSent): ?>
    <h2 class="section-label">Guerres déclarées (en attente)</h2>
    <?php foreach ($pendingSent as $u): ?>
        <?= user_row($u, '<button type="button" class="btn btn--small btn--outline enemy-action" data-target="' . (int) $u['id'] . '" data-action="cancel">Annuler</button>') ?>
    <?php endforeach; ?>
<?php endif; ?>

<h2 class="section-label">Ennemis confirmés (<?= count($confirmed) ?>)</h2>
<?php if (empty($confirmed)): ?>
    <div class="empty-state"><i class="fa-solid fa-skull-crossbones"></i><p>Aucun ennemi pour l’instant. Va en chercher dans <a href="<?= BASE_URL ?>/discover.php">Explorer</a>.</p></div>
<?php endif; ?>
<?php foreach ($confirmed as $u): ?>
    <?= user_row($u,
        '<a class="btn btn--small btn--outline" href="' . BASE_URL . '/messages.php?to=' . (int) $u['id'] . '" aria-label="Message"><i class="fa-solid fa-envelope"></i></a>'
      . '<button type="button" class="btn btn--small btn--outline enemy-action" data-target="' . (int) $u['id'] . '" data-action="cancel" data-confirm="Rompre cette guerre ? Vous ne serez plus ennemis."><i class="fa-solid fa-skull-crossbones"></i> Ennemi juré</button>') ?>
<?php endforeach; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
