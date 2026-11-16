<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);
require_once __DIR__ . '/includes/feed_query.php';

$username = is_string($_GET['u'] ?? null) ? strtolower(trim($_GET['u'])) : '';
$profile = $username !== '' ? get_user_by_username($pdo, $username) : null;
if (!$profile || (int) $profile['is_active'] !== 1) {
    abort_page(404, 'Ce guerrier n’existe pas', 'Vérifie l’orthographe du pseudo.');
}
$pid = $profile['id'];
$isMe = $pid === $me['id'];
$blockedByThem = !$isMe && is_blocked($pdo, $pid, $me['id']);
$iBlockedThem = !$isMe && is_blocked($pdo, $me['id'], $pid);
$tab = ($_GET['tab'] ?? '') === 'media' ? 'media' : 'posts';

$stats = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM haters WHERE target_id = :a) AS haters,
        (SELECT COUNT(*) FROM haters WHERE hater_id = :b) AS hating,
        (SELECT COUNT(*) FROM enemies WHERE status = 'accepted' AND (requester_id = :c OR target_id = :d)) AS enemies"
);
$stats->execute(['a' => $pid, 'b' => $pid, 'c' => $pid, 'd' => $pid]);
$counts = $stats->fetch();

$iHateThem = $theyHateMe = false;
$enemyState = 'none';
if (!$isMe) {
    $iHateThem = is_hating($pdo, $me['id'], $pid);
    $theyHateMe = is_hating($pdo, $pid, $me['id']);
    $enemyState = enemy_state(get_enemy_row($pdo, $me['id'], $pid), $me['id']);
}

$canSee = can_view_posts($pdo, $me, $profile);
$posts = [];
$hasMore = false;
$lastId = 0;
$mediaTiles = [];
if (!$blockedByThem && $canSee) {
    if ($tab === 'media') {
        $m = $pdo->prepare(
            "SELECT p.id,
                    (SELECT file_path FROM post_media WHERE post_id = p.id ORDER BY position, id LIMIT 1) AS path,
                    (SELECT media_type FROM post_media WHERE post_id = p.id ORDER BY position, id LIMIT 1) AS mtype,
                    (SELECT COUNT(*) FROM post_media WHERE post_id = p.id) AS n
             FROM posts p WHERE p.user_id = ? AND EXISTS (SELECT 1 FROM post_media WHERE post_id = p.id)
             ORDER BY p.id DESC LIMIT 60"
        );
        $m->execute([$pid]);
        $mediaTiles = $m->fetchAll();
    } else {
        $posts = fetch_user_posts($pdo, $pid, FEED_PAGE_SIZE + 1);
        $hasMore = count($posts) > FEED_PAGE_SIZE;
        $posts = array_slice($posts, 0, FEED_PAGE_SIZE);
        $lastId = $posts ? (int) end($posts)['id'] : 0;
    }
}

$pageTitle = $profile['display_name'];
$navActive = $isMe ? 'profile' : '';
$profileUrl = BASE_URL . '/profile.php?u=' . urlencode($profile['username']);
require_once __DIR__ . '/includes/header.php';
?>
<?= page_head($profile['display_name'], BASE_URL . '/index.php') ?>

<section class="profile" data-profile-id="<?= $pid ?>">
    <?php
    // Visionneuse (v4.12) : on n'ouvre que de vraies photos, pas les images par défaut. Cover + avatar forment une galerie.
    $coverUrl = media_url($profile['cover_photo'], 'assets/img/default-cover.png');
    $avatarUrl = media_url($profile['profile_photo']);
    $hasCover = !empty($profile['cover_photo']) && strpos((string) $profile['cover_photo'], 'default-') === false;
    $hasAvatar = !empty($profile['profile_photo']) && strpos((string) $profile['profile_photo'], 'default-') === false;
    $viewerGroup = 'profile-' . $pid;
    ?>
    <?php if ($hasCover): ?>
        <button type="button" class="profile__cover profile__cover--btn" style="background-image:url('<?= $coverUrl ?>')"
                data-lightbox="<?= $coverUrl ?>" data-lightbox-alt="Photo de couverture de <?= clean($profile['display_name']) ?>" data-lightbox-group="<?= $viewerGroup ?>"
                aria-label="Voir la photo de couverture de <?= clean($profile['display_name']) ?>"></button>
    <?php else: ?>
        <div class="profile__cover" style="background-image:url('<?= $coverUrl ?>')"></div>
    <?php endif; ?>
    <div class="profile__bar">
        <?php if ($hasAvatar): ?>
            <button type="button" class="profile__avatar-btn" data-lightbox="<?= $avatarUrl ?>" data-lightbox-alt="Photo de profil de <?= clean($profile['display_name']) ?>" data-lightbox-group="<?= $viewerGroup ?>"
                    aria-label="Voir la photo de profil de <?= clean($profile['display_name']) ?>">
                <img class="profile__avatar" src="<?= $avatarUrl ?>" alt="Photo de <?= clean($profile['display_name']) ?>">
            </button>
        <?php else: ?>
            <img class="profile__avatar" src="<?= $avatarUrl ?>" alt="Photo de <?= clean($profile['display_name']) ?>">
        <?php endif; ?>
        <div class="profile__actions">
            <?php if ($isMe): ?>
                <a href="<?= BASE_URL ?>/edit_profile.php" class="btn btn--outline btn--small"><i class="fa-solid fa-pen"></i> Modifier le profil</a>
            <?php elseif ($iBlockedThem): ?>
                <button type="button" class="btn btn--outline btn--small unblock-btn" data-target="<?= $pid ?>" data-reload="1"><i class="fa-solid fa-lock-open"></i> Débloquer</button>
            <?php elseif (!$blockedByThem): ?>
                <?= hater_button($pid, $iHateThem) ?>
                <?= enemy_button($enemyState, $pid) ?>
                <a href="<?= BASE_URL ?>/messages.php?to=<?= $pid ?>" class="btn btn--outline btn--small" aria-label="Envoyer un message"><i class="fa-solid fa-envelope"></i></a>
            <?php endif; ?>
            <?php if (!$isMe): ?>
                <div class="menu">
                    <button type="button" class="icon-btn menu__btn" aria-label="Plus d’options" aria-haspopup="menu" aria-expanded="false"><i class="fa-solid fa-ellipsis"></i></button>
                    <div class="menu__list menu__list--right" role="menu" hidden>
                        <button type="button" role="menuitem" data-copy-link="<?= $profileUrl ?>"><i class="fa-solid fa-link"></i> Copier le lien</button>
                        <?php if (!$iBlockedThem): ?>
                            <button type="button" role="menuitem" class="menu__danger" data-block-user="<?= $pid ?>" data-name="<?= clean($profile['display_name']) ?>"><i class="fa-solid fa-ban"></i> Bloquer</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="profile__info">
        <h2 class="profile__name"><?= clean($profile['display_name']) ?></h2>
        <div class="profile__handle">@<?= clean($profile['username']) ?>
            <?php if (!empty($profile['profile_private'])): ?><span class="pill"><i class="fa-solid fa-lock"></i> Profil privé</span><?php endif; ?>
            <?php if ($theyHateMe): ?><span class="pill pill--danger">Te hait</span><?php endif; ?>
            <?php if ($enemyState === 'accepted'): ?><span class="pill pill--danger">Ennemi juré</span><?php endif; ?>
        </div>
        <?php if (!empty($profile['bio'])): ?><p class="profile__bio"><?= nl2br(clean($profile['bio'])) ?></p><?php endif; ?>

        <div class="profile__stats">
            <?php if ($isMe): ?>
                <a href="<?= BASE_URL ?>/enemies.php"><strong><?= (int) $counts['enemies'] ?></strong> Ennemis</a>
                <a href="<?= BASE_URL ?>/haters.php"><strong id="statHaters"><?= (int) $counts['haters'] ?></strong> Haters</a>
                <a href="<?= BASE_URL ?>/haters.php?tab=hating"><strong><?= (int) $counts['hating'] ?></strong> Hait</a>
            <?php else: ?>
                <span><strong><?= (int) $counts['enemies'] ?></strong> Ennemis</span>
                <span><strong id="statHaters"><?= (int) $counts['haters'] ?></strong> Haters</span>
                <span><strong><?= (int) $counts['hating'] ?></strong> Hait</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($blockedByThem): ?>
        <div class="empty-state"><i class="fa-solid fa-ban"></i><h2>Ce guerrier t’a bloqué</h2><p>Tu ne peux pas voir ses publications.</p></div>
    <?php elseif (!$canSee): ?>
        <div class="empty-state"><i class="fa-solid fa-lock"></i><h2>Profil privé</h2>
            <p>Seuls les ennemis jurés de <?= clean($profile['display_name']) ?> voient ses publications. Déclare-lui la guerre et attends qu’il accepte.</p></div>
    <?php else: ?>
        <div class="tabs" role="tablist">
            <a href="<?= $profileUrl ?>" class="tab <?= $tab === 'posts' ? 'tab--active' : '' ?>" role="tab" aria-selected="<?= $tab === 'posts' ? 'true' : 'false' ?>"><i class="fa-solid fa-bolt"></i> Provocations</a>
            <a href="<?= $profileUrl ?>&amp;tab=media" class="tab <?= $tab === 'media' ? 'tab--active' : '' ?>" role="tab" aria-selected="<?= $tab === 'media' ? 'true' : 'false' ?>"><i class="fa-solid fa-image"></i> Médias</a>
        </div>

        <?php if ($tab === 'media'): ?>
            <?php if (empty($mediaTiles)): ?>
                <div class="empty-state"><i class="fa-solid fa-image"></i><p>Aucun média publié.</p></div>
            <?php else: ?>
                <div class="grid-media">
                    <?php foreach ($mediaTiles as $t): ?>
                        <a href="<?= BASE_URL ?>/post.php?id=<?= (int) $t['id'] ?>" class="grid-media__tile" aria-label="Voir la publication">
                            <?php if ($t['mtype'] === 'image'): ?>
                                <img src="<?= media_url($t['path']) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <video src="<?= media_url($t['path']) ?>#t=0.1" preload="metadata" muted playsinline></video>
                            <?php endif; ?>
                            <?php if ((int) $t['n'] > 1): ?><i class="fa-solid fa-clone grid-media__badge"></i>
                            <?php elseif ($t['mtype'] === 'video'): ?><i class="fa-solid fa-play grid-media__badge"></i><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if (empty($posts)): ?>
                <div class="empty-state"><i class="fa-solid fa-ban"></i><p><?= $isMe ? 'Tu n’as encore rien provoqué.' : 'Aucune provocation publiée.' ?></p></div>
            <?php else: ?>
                <div id="feed" class="feed" data-endpoint="<?= BASE_URL ?>/api/feed.php" data-user="<?= $pid ?>" data-before="<?= $lastId ?>" data-has-more="<?= $hasMore ? '1' : '0' ?>">
                    <?= render_posts($pdo, $posts, $me) ?>
                </div>
                <div id="feedFooter" class="feed-footer">
                    <div id="feedSentinel"></div>
                    <?php if ($hasMore): ?><button type="button" id="feedLoadMoreBtn" class="btn btn--outline"><i class="fa-solid fa-arrows-rotate"></i> Charger plus</button><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
