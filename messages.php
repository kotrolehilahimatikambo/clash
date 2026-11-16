<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);
$myId = $me['id'];

// Démarrer une conversation depuis un profil (?to=ID)
if (isset($_GET['to'])) {
    $toId = (int) $_GET['to'];
    $target = $toId > 0 && $toId !== $myId ? get_user_by_id($pdo, $toId) : null;
    if (!$target || (int) $target['is_active'] !== 1) {
        redirect('messages.php');
    }
    if (is_blocked($pdo, $myId, $toId)) {
        flash('error', 'Tu as bloqué cette personne. Débloque-la d’abord pour lui écrire.');
        redirect('messages.php');
    }
    if (is_blocked($pdo, $toId, $myId)) {
        flash('error', 'Tu ne peux pas écrire à cette personne.');
        redirect('messages.php');
    }
    if ($why = can_message_user($pdo, $myId, $target)) {
        flash('error', $why);
        redirect('messages.php');
    }
    $conv = get_or_create_conversation($pdo, $myId, $toId);
    redirect('messages.php?conv=' . (int) $conv['id']);
}

// Envoi d'un message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['conversation_id']) || post_too_large())) {
    $convId = (int) ($_POST['conversation_id'] ?? 0);
    $back = 'messages.php' . ($convId > 0 ? '?conv=' . $convId : '');

    if (post_too_large()) { flash('error', 'Image trop volumineuse.'); redirect('messages.php'); }
    if (!csrf_check()) { flash('error', 'Session expirée, réessaie.'); redirect($back); }
    if (!rate_limit_check($pdo, $myId, 'send_message', 30, 60)) {
        flash('error', 'Tu envoies trop de messages, calme-toi un peu.');
        redirect($back);
    }

    $stmt = $pdo->prepare("SELECT * FROM conversations WHERE id = ?");
    $stmt->execute([$convId]);
    $conv = $stmt->fetch();
    if (!$conv || !conversation_has_member($conv, $myId)) redirect('messages.php');

    $otherId = conversation_other_user_id($conv, $myId);
    $content = trim((string) ($_POST['content'] ?? ''));

    if ($conv['status'] === 'blocked' || is_blocked($pdo, $otherId, $myId) || is_blocked($pdo, $myId, $otherId)) {
        flash('error', 'Tu ne peux pas envoyer de message dans cette conversation.');
        redirect($back);
    }
    // Réglages du destinataire (sauf si je réponds à une demande qu'il m'a envoyée)
    if (!($conv['status'] === 'pending' && (int) $conv['initiator_id'] !== $myId)) {
        $other = get_user_by_id($pdo, $otherId);
        if ($other && ($why = can_message_user($pdo, $myId, $other))) {
            flash('error', $why);
            redirect($back);
        }
    }
    if (mb_strlen($content) > MAX_MESSAGE_LENGTH) {
        flash('error', 'Message trop long (' . MAX_MESSAGE_LENGTH . ' caractères max).');
        redirect($back);
    }

    // Demande de message : l'initiateur n'a droit qu'à UN message tant que l'autre n'a pas accepté.
    // Si le destinataire répond, la conversation est acceptée d'office.
    $isRequestFromMe = $conv['status'] === 'pending' && (int) $conv['initiator_id'] === $myId;
    if ($isRequestFromMe) {
        $sent = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND sender_id = ?");
        $sent->execute([$convId, $myId]);
        if ((int) $sent->fetchColumn() >= 1) {
            flash('error', 'Attends que cette personne accepte ta demande avant d’envoyer d’autres messages.');
            redirect($back);
        }
    } elseif ($conv['status'] === 'pending') {
        $pdo->prepare("UPDATE conversations SET status = 'accepted' WHERE id = ?")->execute([$convId]);
    }

    $mediaPath = null;
    if (!empty($_FILES['media']) && (int) $_FILES['media']['error'] !== UPLOAD_ERR_NO_FILE) {
        $res = save_uploaded_media($_FILES['media'], array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_VIDEO_TYPES), UPLOAD_DIR_MESSAGES, 1200, 1200);
        if ($res['ok']) {
            $mediaPath = 'assets/uploads/messages/' . $res['filename'];
        } else {
            flash('error', 'Fichier non envoyé : ' . $res['error']);
            if ($content === '') redirect($back);
        }
    }
    if ($content === '' && !$mediaPath) {
        redirect($back);
    }

    $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, content, media_path) VALUES (?, ?, ?, ?)")
        ->execute([$convId, $myId, $content, $mediaPath]);
    create_notification($pdo, $otherId, $myId, $isRequestFromMe ? 'message_request' : 'new_message', null, $convId);
    redirect($back);
}

// Liste des conversations (du plus récent message au plus ancien)
$convStmt = $pdo->prepare(
    "SELECT c.*,
        u.id AS other_id, u.username AS other_username, u.display_name AS other_display_name, u.profile_photo AS other_photo,
        (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_message,
        (SELECT media_path FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_media,
        (SELECT sender_id FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_sender,
        (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_at,
        (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_id != ? AND is_read = 0) AS unread
     FROM conversations c
     JOIN users u ON u.id = (CASE WHEN c.user_one_id = ? THEN c.user_two_id ELSE c.user_one_id END)
     WHERE (c.user_one_id = ? OR c.user_two_id = ?) AND c.status != 'blocked' AND u.is_active = 1
     ORDER BY COALESCE((SELECT MAX(id) FROM messages WHERE conversation_id = c.id), 0) DESC, c.id DESC"
);
$convStmt->execute([$myId, $myId, $myId, $myId]);
$allConversations = $convStmt->fetchAll();

$accepted = array_filter($allConversations, fn($c) => $c['status'] === 'accepted');
$requests = array_filter($allConversations, fn($c) => $c['status'] === 'pending' && (int) $c['initiator_id'] !== $myId);
$sentRequests = array_filter($allConversations, fn($c) => $c['status'] === 'pending' && (int) $c['initiator_id'] === $myId);

// Conversation active
$activeConv = null;
$activeMessages = [];
if (isset($_GET['conv'])) {
    $convId = (int) $_GET['conv'];
    foreach ($allConversations as $c) {
        if ((int) $c['id'] === $convId) { $activeConv = $c; break; }
    }
    if ($activeConv) {
        $msgStmt = $pdo->prepare(
            "SELECT * FROM (SELECT * FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 200) t ORDER BY id ASC"
        );
        $msgStmt->execute([$convId]);
        $activeMessages = $msgStmt->fetchAll();
        $pdo->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ? AND is_read = 0")->execute([$convId, $myId]);
    }
}

$activeIsIncomingRequest = $activeConv && $activeConv['status'] === 'pending' && (int) $activeConv['initiator_id'] !== $myId;
$activeIsMyRequest = $activeConv && $activeConv['status'] === 'pending' && (int) $activeConv['initiator_id'] === $myId;
$msgLockReason = null;
if ($activeConv && !$activeIsIncomingRequest) {
    $otherRow = get_user_by_id($pdo, (int) $activeConv['other_id']);
    $msgLockReason = $otherRow ? can_message_user($pdo, $myId, $otherRow) : null;
}
$canSend = $activeConv && $msgLockReason === null && !($activeIsMyRequest && count(array_filter($activeMessages, fn($m) => (int) $m['sender_id'] === $myId)) >= 1);

/** Une ligne de la liste de conversations. */
function conv_item(array $c, ?array $active, int $myId, string $extra = ''): string
{
    $isActive = $active && (int) $active['id'] === (int) $c['id'];
    $preview = $c['last_message'] !== null && $c['last_message'] !== ''
        ? mb_strimwidth($c['last_message'], 0, 45, '…')
        : ($c['last_media'] ? (is_video_path($c['last_media']) ? '🎬 Vidéo' : '📷 Photo') : 'Démarre la conversation');
    if ($c['last_sender'] !== null && (int) $c['last_sender'] === $myId && $preview !== 'Démarre la conversation') $preview = 'Toi : ' . $preview;
    $unread = (int) $c['unread'];
    return '<a href="?conv=' . (int) $c['id'] . '" class="conv-item ' . ($isActive ? 'is-active' : '') . ($unread > 0 ? ' is-unread' : '') . ' ' . $extra . '">'
        . '<img src="' . media_url($c['other_photo']) . '" alt="" loading="lazy">'
        . '<span class="conv-item__body"><strong>' . clean($c['other_display_name']) . '</strong><span>' . clean($preview) . '</span></span>'
        . ($c['last_at'] ? '<small>' . time_ago($c['last_at']) . '</small>' : '')
        . ($unread > 0 ? '<span class="badge badge--inline">' . $unread . '</span>' : '')
        . '</a>';
}

$pageTitle = 'Messages';
$navActive = 'messages';
$noRail = true;
$mainClass = 'app__main--wide';
require_once __DIR__ . '/includes/header.php';
?>
<div class="msgs <?= $activeConv ? 'has-thread' : '' ?>">
    <aside class="msgs__list" aria-label="Conversations">
        <h1 class="msgs__title">Messages</h1>

        <?php if ($requests): ?>
            <h2 class="section-label">Demandes de message</h2>
            <?php foreach ($requests as $c) echo conv_item($c, $activeConv, $myId, 'conv-item--request'); ?>
        <?php endif; ?>

        <?php if ($accepted || $sentRequests): ?>
            <h2 class="section-label">Conversations</h2>
            <?php foreach ($accepted as $c) echo conv_item($c, $activeConv, $myId); ?>
            <?php foreach ($sentRequests as $c) echo conv_item($c, $activeConv, $myId, 'conv-item--pending'); ?>
        <?php endif; ?>

        <?php if (empty($allConversations)): ?>
            <div class="empty-state"><i class="fa-solid fa-envelope-open-text"></i><p>Aucune conversation. Va défier quelqu’un dans <a href="<?= BASE_URL ?>/discover.php">Explorer</a>.</p></div>
        <?php endif; ?>
    </aside>

    <section class="msgs__thread" aria-label="Conversation">
        <?php if (!$activeConv): ?>
            <div class="empty-state"><i class="fa-solid fa-envelope-open-text"></i><h2>Tes messages</h2><p>Sélectionne une conversation pour lancer les hostilités.</p></div>
        <?php else: ?>
            <header class="thread__head">
                <a href="<?= BASE_URL ?>/messages.php" class="icon-btn thread__back" aria-label="Retour aux conversations"><i class="fa-solid fa-arrow-left"></i></a>
                <a href="<?= BASE_URL ?>/profile.php?u=<?= urlencode($activeConv['other_username']) ?>" class="thread__who">
                    <img src="<?= media_url($activeConv['other_photo']) ?>" alt="">
                    <span><strong><?= clean($activeConv['other_display_name']) ?></strong><small>@<?= clean($activeConv['other_username']) ?></small></span>
                </a>
                <div class="menu">
                    <button type="button" class="icon-btn menu__btn" aria-label="Options de la conversation" aria-haspopup="menu" aria-expanded="false"><i class="fa-solid fa-ellipsis"></i></button>
                    <div class="menu__list menu__list--right" role="menu" hidden>
                        <button type="button" role="menuitem" data-msg-action="report_spam" data-conv="<?= (int) $activeConv['id'] ?>"><i class="fa-solid fa-flag"></i> Signaler comme spam</button>
                        <button type="button" role="menuitem" class="menu__danger" data-msg-action="block" data-conv="<?= (int) $activeConv['id'] ?>" data-name="<?= clean($activeConv['other_display_name']) ?>"><i class="fa-solid fa-ban"></i> Bloquer</button>
                    </div>
                </div>
            </header>

            <?php if ($activeIsIncomingRequest): ?>
                <div class="msg-request">
                    <p><strong><?= clean($activeConv['other_display_name']) ?></strong> veut t’écrire. Accepte pour discuter normalement, ou bloque.</p>
                    <div class="msg-request__actions">
                        <button type="button" class="btn btn--small btn--primary" data-msg-action="accept" data-conv="<?= (int) $activeConv['id'] ?>">Accepter</button>
                        <button type="button" class="btn btn--small btn--outline" data-msg-action="block" data-conv="<?= (int) $activeConv['id'] ?>" data-name="<?= clean($activeConv['other_display_name']) ?>">Bloquer</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="thread__messages" id="threadMessages">
                <?php if (empty($activeMessages)): ?>
                    <p class="muted center">Aucun message. Lance les hostilités.</p>
                <?php endif; ?>
                <?php
                $lastMineIdx = null;
                foreach ($activeMessages as $i => $m) if ((int) $m['sender_id'] === $myId) $lastMineIdx = $i;
                foreach ($activeMessages as $i => $m):
                    $mine = (int) $m['sender_id'] === $myId;
                ?>
                    <div class="bubble <?= $mine ? 'bubble--me' : 'bubble--them' ?>">
                        <?php if ($m['media_path']): ?>
                            <?php if (is_video_path($m['media_path'])): ?><video src="<?= media_url($m['media_path']) ?>" controls playsinline preload="metadata"></video>
                            <?php else: ?><a href="<?= media_url($m['media_path']) ?>" target="_blank" rel="noopener"><img src="<?= media_url($m['media_path']) ?>" alt="Image envoyée" decoding="async"></a><?php endif; ?>
                        <?php endif; ?>
                        <?php if ($m['content'] !== null && $m['content'] !== ''): ?><p><?= nl2br(clean($m['content'])) ?></p><?php endif; ?>
                        <small><?= format_msg_time($m['created_at']) ?><?= ($mine && $i === $lastMineIdx && $m['is_read']) ? ' · Vu' : '' ?></small>
                        <?php if ($mine && !$activeIsMyRequest): ?>
                            <button type="button" class="bubble__del" data-delete-message="<?= (int) $m['id'] ?>" aria-label="Supprimer ce message"><i class="fa-regular fa-trash-can"></i></button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($canSend): ?>
                <form method="post" enctype="multipart/form-data" class="thread__form" id="msgForm">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="conversation_id" value="<?= (int) $activeConv['id'] ?>">
                    <div id="msgPreview" class="msg-preview" hidden></div>
                    <div class="thread__row">
                        <label class="icon-btn" title="Envoyer une image ou une vidéo" tabindex="0">
                            <i class="fa-solid fa-paperclip"></i>
                            <input type="file" name="media" id="msgMedia" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime" hidden>
                        </label>
                        <textarea name="content" id="msgText" rows="1" maxlength="<?= MAX_MESSAGE_LENGTH ?>" placeholder="Écris quelque chose de méchant…" aria-label="Ton message"></textarea>
                        <button type="submit" name="send_message" class="btn btn--primary btn--small" aria-label="Envoyer"><i class="fa-solid fa-paper-plane"></i></button>
                    </div>
                </form>
            <?php elseif ($msgLockReason): ?>
                <p class="thread__locked muted"><i class="fa-solid fa-lock"></i> <?= clean($msgLockReason) ?></p>
            <?php else: ?>
                <p class="thread__locked muted"><i class="fa-solid fa-hourglass-half"></i> Ta demande est envoyée. Tu pourras continuer quand <?= clean($activeConv['other_display_name']) ?> l’aura acceptée.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<?php
$extraScripts = '<script src="' . asset('assets/js/messages.js') . '"></script>';
require_once __DIR__ . '/includes/footer.php';
?>
