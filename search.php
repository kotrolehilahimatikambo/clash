<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);

$q = trim((string) ($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 50);
$tab = ($_GET['tab'] ?? '') === 'posts' ? 'posts' : 'users';
$users = [];
$posts = [];

if ($q !== '') {
    $like = '%' . like_escape($q) . '%';
    if ($tab === 'users') {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.username, u.display_name, u.profile_photo,
                    EXISTS(SELECT 1 FROM haters h WHERE h.hater_id = ? AND h.target_id = u.id) AS i_hate
             FROM users u
             WHERE (u.username LIKE ? ESCAPE '|' OR u.display_name LIKE ? ESCAPE '|')
               AND u.id != ? AND u.is_active = 1 AND (u.hide_from_explore = 0 OR u.username = ?)
               AND u.id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)
               AND u.id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)
             ORDER BY (u.username = ?) DESC, u.last_seen DESC
             LIMIT 30"
        );
        $stmt->execute([$me['id'], $like, $like, $me['id'], strtolower($q), $me['id'], $me['id'], strtolower($q)]);
        $users = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            "SELECT p.*, u.username, u.display_name, u.profile_photo
             FROM posts p JOIN users u ON u.id = p.user_id
             WHERE p.content LIKE ? ESCAPE '|' AND u.is_active = 1
               AND u.id NOT IN (SELECT blocker_id FROM blocks WHERE blocked_id = ?)
               AND u.id NOT IN (SELECT blocked_id FROM blocks WHERE blocker_id = ?)
               AND " . post_visibility_sql($me['id'], 'u') . "
             ORDER BY p.id DESC LIMIT 30"
        );
        $stmt->execute([$like, $me['id'], $me['id']]);
        $posts = $stmt->fetchAll();
    }
}

$pageTitle = $q !== '' ? 'Recherche : ' . $q : 'Recherche';
$navActive = 'explore';
require_once __DIR__ . '/includes/header.php';
$qs = urlencode($q);
?>
<?= page_head('Recherche', BASE_URL . '/discover.php', 'fa-magnifying-glass') ?>

<form action="<?= BASE_URL ?>/search.php" method="get" class="searchbox searchbox--page" role="search">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input type="search" name="q" value="<?= clean($q) ?>" placeholder="Chercher un guerrier ou une provocation…" aria-label="Rechercher" maxlength="50" <?= $q === '' ? 'autofocus' : '' ?>>
    <?php if ($tab === 'posts'): ?><input type="hidden" name="tab" value="posts"><?php endif; ?>
</form>

<?php if ($q === ''): ?>
    <div class="empty-state"><i class="fa-solid fa-magnifying-glass"></i><p>Tape un pseudo, un nom ou un mot pour traquer quelqu’un.</p></div>
<?php else: ?>
    <div class="tabs" role="tablist">
        <a href="?q=<?= $qs ?>" class="tab <?= $tab === 'users' ? 'tab--active' : '' ?>" role="tab" aria-selected="<?= $tab === 'users' ? 'true' : 'false' ?>"><i class="fa-solid fa-user"></i> Guerriers</a>
        <a href="?q=<?= $qs ?>&amp;tab=posts" class="tab <?= $tab === 'posts' ? 'tab--active' : '' ?>" role="tab" aria-selected="<?= $tab === 'posts' ? 'true' : 'false' ?>"><i class="fa-solid fa-bolt"></i> Provocations</a>
    </div>

    <?php if ($tab === 'users'): ?>
        <?php foreach ($users as $u): ?>
            <?= user_row($u, hater_button((int) $u['id'], (bool) $u['i_hate'])) ?>
        <?php endforeach; ?>
        <?php if (empty($users)): ?><div class="empty-state"><i class="fa-solid fa-user-slash"></i><p>Aucun guerrier trouvé pour « <?= clean($q) ?> ».</p></div><?php endif; ?>
    <?php else: ?>
        <?= render_posts($pdo, $posts, $me) ?>
        <?php if (empty($posts)): ?><div class="empty-state"><i class="fa-solid fa-comment-slash"></i><p>Aucune provocation ne contient « <?= clean($q) ?> ».</p></div><?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
