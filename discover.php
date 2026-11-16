<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);

// Suggestions : récemment actifs, ni moi, ni ennemis confirmés, ni bloqués. Hater/ennemi résolus en UNE requête
// (avant : 2 requêtes par carte, plus un ORDER BY RAND() sur toute la table).
$stmt = $pdo->prepare(
    "SELECT u.id, u.username, u.display_name, u.profile_photo, u.bio,
            EXISTS(SELECT 1 FROM haters h WHERE h.hater_id = ? AND h.target_id = u.id) AS i_hate,
            e.status AS enemy_status, e.requester_id AS enemy_requester
     FROM users u
     LEFT JOIN enemies e ON ((e.requester_id = ? AND e.target_id = u.id) OR (e.requester_id = u.id AND e.target_id = ?))
     WHERE u.id != ? AND u.is_active = 1 AND u.hide_from_explore = 0
       AND u.id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)
       AND u.id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)
       AND (e.status IS NULL OR e.status != 'accepted')
     ORDER BY u.last_seen DESC
     LIMIT 60"
);
$stmt->execute([$me['id'], $me['id'], $me['id'], $me['id'], $me['id'], $me['id']]);
$suggestions = $stmt->fetchAll();
shuffle($suggestions);
$suggestions = array_slice($suggestions, 0, 24);

$pageTitle = 'Explorer';
$navActive = 'explore';
require_once __DIR__ . '/includes/header.php';
?>
<?= page_head('Explorer', null, 'fa-crosshairs') ?>

<form action="<?= BASE_URL ?>/search.php" method="get" class="searchbox searchbox--page" role="search">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input type="search" name="q" placeholder="Chercher un guerrier ou une provocation…" aria-label="Rechercher" maxlength="50">
</form>

<h2 class="section-label">Cibles suggérées</h2>
<div class="suggestions-grid">
    <?php foreach ($suggestions as $u): ?>
        <?php $state = enemy_state($u['enemy_status'] ? ['status' => $u['enemy_status'], 'requester_id' => $u['enemy_requester']] : null, $me['id']); ?>
        <div class="suggestion-card">
            <a href="<?= BASE_URL ?>/profile.php?u=<?= urlencode($u['username']) ?>" class="suggestion-card__link">
                <img src="<?= media_url($u['profile_photo']) ?>" alt="" loading="lazy">
                <strong><?= clean($u['display_name']) ?></strong>
                <span class="muted">@<?= clean($u['username']) ?></span>
            </a>
            <?php if (!empty($u['bio'])): ?><p class="suggestion-card__bio"><?= clean(mb_strimwidth($u['bio'], 0, 70, '…')) ?></p><?php endif; ?>
            <div class="suggestion-card__actions">
                <?= hater_button((int) $u['id'], (bool) $u['i_hate']) ?>
                <?= enemy_button($state, (int) $u['id']) ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php if (empty($suggestions)): ?>
    <div class="empty-state"><i class="fa-solid fa-crosshairs"></i><p>Aucune cible disponible pour l’instant. Invite des gens à te rejoindre !</p></div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
