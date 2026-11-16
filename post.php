<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);

$postId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT p.*, u.username, u.display_name, u.profile_photo
     FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ?"
);
$stmt->execute([$postId]);
$post = $stmt->fetch();
$postOwner = $post ? get_user_by_id($pdo, (int) $post['user_id']) : null;
if (!$post || !$postOwner || is_blocked($pdo, (int) $post['user_id'], $me['id']) || !can_view_posts($pdo, $me, $postOwner)) {
    abort_page(404, 'Publication introuvable', 'Elle a été supprimée, ou tu n’as pas le droit de la voir.');
}
$postOwnerId = (int) $post['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_comment'])) {
    if (!csrf_check()) flash('error', 'Session expirée, réessaie.');
    else {
        $cid=(int)($_POST['comment_id']??0); $content=trim((string)($_POST['content']??''));
        if ($content==='' || mb_strlen($content)>MAX_COMMENT_LENGTH) flash('error','Commentaire vide ou trop long.');
        else { $pdo->prepare("UPDATE comments SET content=?,updated_at=NOW() WHERE id=? AND user_id=? AND post_id=?")->execute([$content,$cid,$me['id'],$postId]); notify_mentions($pdo, $content, (int) $me['id'], $postId); }
    }
    redirect('post.php?id='.$postId.'#comments');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_post'])) {
    if (!csrf_check()) flash('error', 'Session expirée, réessaie.');
    else {
        $content=trim((string)($_POST['content']??''));
        if (mb_strlen($content)>5000) flash('error','Publication trop longue.');
        else { $pdo->prepare("UPDATE posts SET content=?,updated_at=NOW() WHERE id=? AND user_id=?")->execute([$content,$postId,$me['id']]); notify_mentions($pdo, $content, (int) $me['id'], $postId); }
    }
    redirect('post.php?id='.$postId);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    if (post_too_large() || !csrf_check()) {
        flash('error', 'Session expirée, réessaie.');
    } elseif (is_blocked($pdo, $me['id'], $postOwnerId)) {
        flash('error', 'Tu as bloqué cette personne : débloque-la pour répondre.');
    } elseif (!rate_limit_check($pdo, $me['id'], 'comment', 30, 60)) {
        flash('error', 'Tu ripostes trop vite, souffle un peu.');
    } else {
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '') {
            flash('error', 'Ta riposte est vide.');
        } elseif (mb_strlen($content) > MAX_COMMENT_LENGTH) {
            flash('error', 'Ta riposte est trop longue (' . MAX_COMMENT_LENGTH . ' caractères max).');
        } else {
            // v4.12 : on peut répondre à n'importe quel commentaire (racine, réponse, réponse à une réponse…).
            $slot = resolve_comment_parent($pdo, 'comments', $postId, (int) ($_POST['parent_comment_id'] ?? 0));
            if (is_string($slot)) {
                flash('error', $slot);
                redirect('post.php?id=' . $postId . '#comments');
            }
            $parentId = (int) $slot['parent'];
            $pdo->prepare("INSERT INTO comments (post_id,parent_comment_id,root_comment_id,depth,user_id,content) VALUES (?,?,?,?,?,?)")
                ->execute([$postId, $slot['parent'], $slot['root'], $slot['depth'], $me['id'], $content]);
            create_notification($pdo, $postOwnerId, $me['id'], 'comment', $postId);
            if ($parentId) {
                $parentOwnerStmt = $pdo->prepare('SELECT user_id FROM comments WHERE id = ? AND post_id = ?');
                $parentOwnerStmt->execute([$parentId, $postId]);
                $parentOwnerId = (int) $parentOwnerStmt->fetchColumn();
                if ($parentOwnerId && $parentOwnerId !== $postOwnerId && $parentOwnerId !== (int) $me['id']) {
                    create_notification($pdo, $parentOwnerId, (int) $me['id'], 'comment', $postId);
                }
            }
            notify_mentions($pdo, $content, (int) $me['id'], $postId);
        }
    }
    redirect('post.php?id=' . $postId . '#comments');
}

$commentPage = fetch_comments($pdo, $postId, $me['id']);
$comments = $commentPage['rows'];

$pageTitle = 'Publication de ' . $post['display_name'];
require_once __DIR__ . '/includes/header.php';
?>
<?= page_head('Publication', BASE_URL . '/index.php') ?>

<?= render_posts($pdo, [$post], $me) ?>
<?php if ((int)$post['user_id']===(int)$me['id']): ?>
<form method="post" class="post-edit-form" id="postEditForm" <?= isset($_GET['edit']) && $_GET['edit'] === '1' ? '' : 'hidden' ?>>
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<textarea name="content" maxlength="5000" required><?= clean($post['content']??'') ?></textarea>
<button class="btn btn--primary btn--small" name="edit_post">Enregistrer</button>
<button type="button" class="btn btn--outline btn--small" data-cancel-post-edit>Annuler</button>
</form>
<?php endif; ?>

<section class="comments" id="comments">
    <div class="reply-context" id="replyContext" hidden><span id="replyContextText">Réponse en cours</span><button type="button" class="icon-btn" id="cancelReply" aria-label="Annuler la réponse"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="post" class="comment-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <img src="<?= media_url($me['profile_photo']) ?>" alt="" class="comment-form__avatar">
        <input type="hidden" name="parent_comment_id" id="replyParentId" value=""><input type="text" name="content" maxlength="<?= MAX_COMMENT_LENGTH ?>" placeholder="Riposte avec hargne…" required aria-label="Ta riposte" autocomplete="off">
        <button type="submit" name="add_comment" class="btn btn--primary btn--small" aria-label="Envoyer"><i class="fa-solid fa-paper-plane"></i></button>
    </form>

    <div id="commentsList"><?= render_comments($comments, $me, $postOwnerId) ?></div>
<form method="post" class="comment-edit-form" id="commentEditForm" hidden>
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="comment_id" id="editCommentId">
<textarea name="content" id="editCommentContent" maxlength="<?= MAX_COMMENT_LENGTH ?>" required></textarea>
<button class="btn btn--primary btn--small" name="edit_comment">Enregistrer</button><button type="button" class="btn btn--outline btn--small" data-cancel-edit>Annuler</button>
</form>
    <?php if ($commentPage['hasMore']): ?>
        <div class="comments__more">
            <button type="button" id="commentsMore" class="btn btn--outline btn--small" data-post="<?= $postId ?>" data-after="<?= $commentPage['after'] ?>"><i class="fa-solid fa-angles-down"></i> Voir plus de ripostes</button>
        </div>
    <?php endif; ?>
    <?php if (empty($comments)): ?>
        <p class="muted center comments__empty">Personne n’a encore osé répondre.</p>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
