<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);
require_once __DIR__ . '/includes/feed_query.php';

$posts = fetch_feed($pdo, $me['id'], FEED_PAGE_SIZE + 1);
$hasMore = count($posts) > FEED_PAGE_SIZE;
$posts = array_slice($posts, 0, FEED_PAGE_SIZE);
$lastId = $posts ? (int) end($posts)['id'] : 0;
$suggestions = $posts ? [] : suggest_users($pdo, $me['id'], 6);

$pageTitle = 'Champ de bataille';
$navActive = 'home';
require_once __DIR__ . '/includes/header.php';
?>
<?= page_head('Champ de bataille', null, 'fa-fire', true) ?>

<button type="button" class="composer-bar" data-open-compose>
    <img src="<?= media_url($me['profile_photo']) ?>" alt="">
    <span class="composer-bar__text">Balance ta provoc du jour…</span>
    <span class="btn btn--primary btn--small"><i class="fa-solid fa-bolt"></i> Provoquer</span>
</button>

<?php if (empty($posts)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-explosion"></i>
        <h2>Ton champ de bataille est vide</h2>
        <p>Ton fil affiche les provocations des gens que tu hais et de tes ennemis. Commence par choisir quelques cibles :</p>
    </div>
    <?php foreach ($suggestions as $u): ?>
        <?= user_row($u, hater_button((int) $u['id'], false) . enemy_button('none', (int) $u['id']), '') ?>
    <?php endforeach; ?>
    <p class="center"><a href="<?= BASE_URL ?>/discover.php" class="btn btn--outline">Voir toutes les cibles</a></p>
<?php else: ?>
    <div id="feed" class="feed" data-endpoint="<?= BASE_URL ?>/api/feed.php" data-before="<?= $lastId ?>" data-has-more="<?= $hasMore ? '1' : '0' ?>">
        <?= render_posts($pdo, $posts, $me) ?>
    </div>
    <div id="feedFooter" class="feed-footer">
        <div id="feedSentinel"></div>
        <?php if ($hasMore): ?>
            <button type="button" id="feedLoadMoreBtn" class="btn btn--outline"><i class="fa-solid fa-arrows-rotate"></i> Charger plus</button>
        <?php else: ?>
            <p class="muted center">Tu as tout vu. Va provoquer quelqu’un.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
