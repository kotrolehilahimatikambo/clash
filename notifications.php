<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);

$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * NOTIF_PAGE_SIZE;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$countStmt->execute([$me['id']]);
$totalPages = max(1, (int) ceil(((int) $countStmt->fetchColumn()) / NOTIF_PAGE_SIZE));

$stmt = $pdo->prepare(
    "SELECT n.*, u.username AS actor_username, u.display_name AS actor_display_name, u.profile_photo AS actor_photo,
            p.content AS post_excerpt
     FROM notifications n
     LEFT JOIN users u ON u.id = n.actor_id
     LEFT JOIN posts p ON p.id = n.post_id
     WHERE n.user_id = ? ORDER BY n.id DESC LIMIT " . NOTIF_PAGE_SIZE . " OFFSET " . (int) $offset
);
$stmt->execute([$me['id']]);
$notifications = $stmt->fetchAll();

$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$me['id']]);

$labels = [
    'new_enemy_request' => ['icon' => 'fa-skull-crossbones', 'text' => 't’a déclaré la guerre'],
    'enemy_accepted'    => ['icon' => 'fa-fire',            'text' => 'a accepté le combat'],
    'new_hater'         => ['icon' => 'fa-ghost',           'text' => 'te hait maintenant'],
    'reaction'          => ['icon' => 'fa-face-angry',      'text' => 'a réagi à ta publication'],
    'comment'           => ['icon' => 'fa-comment-dots',    'text' => 'a riposté sur ta publication'],
    'mention'           => ['icon' => 'fa-at',              'text' => 't’a mentionné dans une publication ou un commentaire'],
    'new_message'       => ['icon' => 'fa-envelope',        'text' => 't’a envoyé un message'],
    'message_request'   => ['icon' => 'fa-envelope-circle-check', 'text' => 'veut te parler'],
    'suggestion'        => ['icon' => 'fa-crosshairs',      'text' => 'pourrait être une bonne cible'],
];

$pageTitle = 'Notifications';
$navActive = 'notifs';
require_once __DIR__ . '/includes/header.php';
?>
<?= page_head('Notifications', null, 'fa-bomb') ?>
<?php if (!empty($notifications)): ?>
<div class="notif-toolbar"><button type="button" class="btn btn--small btn--outline" data-clear-notifs><i class="fa-solid fa-broom"></i> Tout effacer</button></div>
<?php endif; ?>

<?php foreach ($notifications as $n): ?>
    <?php
    $info = $labels[$n['type']] ?? ['icon' => 'fa-bell', 'text' => ''];
    $link = BASE_URL . '/index.php';
    if ($n['post_id']) {
        $link = BASE_URL . '/post.php?id=' . (int) $n['post_id'];
    } elseif (in_array($n['type'], ['new_message', 'message_request'], true)) {
        $link = BASE_URL . '/messages.php' . ($n['conversation_id'] ? '?conv=' . (int) $n['conversation_id'] : '');
    } elseif ($n['type'] === 'new_enemy_request') {
        $link = BASE_URL . '/enemies.php';
    } elseif ($n['actor_username']) {
        $link = BASE_URL . '/profile.php?u=' . urlencode($n['actor_username']);
    }
    ?>
    <div class="notif-row" data-notif-id="<?= (int) $n['id'] ?>">
    <a href="<?= $link ?>" class="notif <?= $n['is_read'] ? '' : 'notif--unread' ?>">
        <span class="notif__avatar">
            <img src="<?= media_url($n['actor_photo']) ?>" alt="" loading="lazy">
            <span class="notif__type"><i class="fa-solid <?= $info['icon'] ?>"></i></span>
        </span>
        <span class="notif__body">
            <span><strong><?= clean($n['actor_display_name'] ?? 'Quelqu’un') ?></strong> <?= $info['text'] ?></span>
            <?php if (!empty($n['post_excerpt'])): ?><span class="notif__excerpt"><?= clean(mb_strimwidth($n['post_excerpt'], 0, 90, '…')) ?></span><?php endif; ?>
        </span>
        <small class="notif__time"><?= time_ago($n['created_at']) ?></small>
    </a>
    <button type="button" class="icon-btn notif-row__del" data-delete-notif="<?= (int) $n['id'] ?>" aria-label="Supprimer cette notification"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endforeach; ?>

<?php if (empty($notifications)): ?>
    <div class="empty-state"><i class="fa-solid fa-bomb"></i><h2>Le calme avant la tempête</h2><p>Aucune notification pour l’instant.</p></div>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>" class="btn btn--small btn--outline"><i class="fa-solid fa-arrow-left"></i> Précédent</a><?php endif; ?>
        <span class="pagination__info">Page <?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1 ?>" class="btn btn--small btn--outline">Suivant <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
