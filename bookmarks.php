<?php
/** Favoris : publications du Champ de bataille et Clashs de clan mis de côté (privé, visible par moi seul). */
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);

$rows = [];
$tableMissing = false;
try {
    $st = $pdo->prepare('SELECT item_type, item_id FROM bookmarks WHERE user_id = ? ORDER BY id DESC LIMIT 100');
    $st->execute([$me['id']]);
    $rows = $st->fetchAll();
} catch (PDOException $e) {
    $tableMissing = true;
}

$postIds = $gpostIds = [];
foreach ($rows as $r) {
    if ($r['item_type'] === 'post') $postIds[] = (int) $r['item_id'];
    else $gpostIds[] = (int) $r['item_id'];
}

$posts = [];
if ($postIds) {
    $in = implode(',', array_fill(0, count($postIds), '?'));
    $st = $pdo->prepare("SELECT p.*, u.username, u.display_name, u.profile_photo
        FROM posts p JOIN users u ON u.id = p.user_id
        WHERE p.id IN ($in) AND u.is_active = 1
          AND p.user_id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)
          AND " . post_visibility_sql((int) $me['id'], 'u'));
    $st->execute(array_merge($postIds, [$me['id']]));
    foreach (hydrate_posts($pdo, $st->fetchAll(), (int) $me['id']) as $p) $posts[(int) $p['id']] = $p;
}

$gposts = [];
$roles = [];
if ($gpostIds) {
    $in = implode(',', array_fill(0, count($gpostIds), '?'));
    $st = $pdo->prepare("SELECT p.*, u.username, u.display_name, u.profile_photo, g.name AS group_name, m.role AS my_role,
            (SELECT type FROM group_post_reactions r WHERE r.group_post_id = p.id AND r.user_id = ?) AS my_reaction
        FROM group_posts p
        JOIN users u ON u.id = p.user_id
        JOIN groups_clash g ON g.id = p.group_id
        JOIN group_members m ON m.group_id = p.group_id AND m.user_id = ? AND m.status = 'active'
        WHERE p.id IN ($in)");
    $st->execute(array_merge([$me['id'], $me['id']], $gpostIds));
    foreach (group_posts_with_counts($pdo, $st->fetchAll(), (int) $me['id']) as $p) $gposts[(int) $p['id']] = $p;
}

$pageTitle = 'Favoris';
$navActive = 'bookmarks';
$mainClass = 'page-bookmarks';
require_once __DIR__ . '/includes/header.php';
?>
<?= page_head('Favoris', null, 'fa-bookmark') ?>

<?php if ($tableMissing): ?>
    <div class="empty-state"><i class="fa-solid fa-database"></i><h2>Favoris indisponibles</h2><p>Applique d’abord la migration <code>migrations/006_favoris_commentaires_clan.sql</code>.</p></div>
<?php else: ?>
    <?php $shown = 0; ?>
    <div class="feed" id="bookmarkFeed">
    <?php foreach ($rows as $r):
        $id = (int) $r['item_id'];
        if ($r['item_type'] === 'post' && isset($posts[$id])) {
            $post = $posts[$id];
            include __DIR__ . '/includes/post_card.php';
            $shown++;
        } elseif ($r['item_type'] === 'group_post' && isset($gposts[$id])) {
            $post = $gposts[$id];
            $selected = (int) $post['group_id'];
            $myRole = $post['my_role'];
            include __DIR__ . '/includes/group_post_card.php';
            $shown++;
        }
    endforeach; ?>
    </div>
    <?php if ($shown === 0): ?>
        <div class="empty-state">
            <i class="fa-regular fa-bookmark"></i>
            <h2>Aucun favori pour l’instant</h2>
            <p>Touche le signet sous une publication ou un Clash de clan pour le retrouver ici. Personne d’autre ne le voit.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
