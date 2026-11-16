<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');

if (post_too_large()) {
    flash('error', 'Envoi trop volumineux : réduis le nombre ou la taille des fichiers.');
    redirect('index.php');
}
if (!csrf_check()) {
    flash('error', 'Session expirée, réessaie.');
    redirect('index.php');
}
if (!rate_limit_check($pdo, $me['id'], 'create_post', 10, 300)) {
    flash('error', 'Tu publies trop vite, souffle un peu.');
    redirect('index.php');
}

$content = trim((string) ($_POST['content'] ?? ''));
if (mb_strlen($content) > MAX_POST_LENGTH) {
    flash('error', 'Ta provocation est trop longue (' . MAX_POST_LENGTH . ' caractères max).');
    redirect('index.php');
}

// Normalise $_FILES['media'] en liste de fichiers (en ignorant les champs vides)
$files = [];
if (!empty($_FILES['media']) && is_array($_FILES['media']['name'])) {
    foreach ($_FILES['media']['name'] as $i => $name) {
        if ((int) $_FILES['media']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $files[] = [
            'name' => $name,
            'tmp_name' => $_FILES['media']['tmp_name'][$i],
            'error' => $_FILES['media']['error'][$i],
            'size' => $_FILES['media']['size'][$i],
        ];
    }
}
if (count($files) > MAX_MEDIA_PER_POST) {
    flash('error', 'Maximum ' . MAX_MEDIA_PER_POST . ' fichiers par publication.');
    redirect('index.php');
}
$totalSize = array_sum(array_map(fn($f) => (int) $f['size'], $files));
if ($totalSize > MAX_POST_TOTAL_SIZE) {
    flash('error', 'Les fichiers pèsent ' . round($totalSize / 1048576) . ' Mo au total : ' . (int) (MAX_POST_TOTAL_SIZE / 1048576) . ' Mo maximum par publication.');
    redirect('index.php');
}
if ($content === '' && !$files) {
    flash('error', 'Ta provocation est vide.');
    redirect('index.php');
}

// 1) On enregistre les fichiers sur le disque (en gardant la trace des refus)
$saved = [];
$skipped = [];
foreach ($files as $i => $f) {
    $res = save_uploaded_media($f, array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_VIDEO_TYPES), UPLOAD_DIR_POSTS);
    if ($res['ok']) {
        $saved[] = ['path' => 'assets/uploads/posts/' . $res['filename'], 'type' => $res['type'], 'position' => $i];
    } else {
        $skipped[] = $res['error'];
    }
}
if ($content === '' && !$saved) {
    flash('error', $skipped ? $skipped[0] : 'Ta provocation est vide.');
    redirect('index.php');
}

// 2) Insertion en base (transaction). En cas d'échec, on supprime les fichiers déjà écrits : pas d'orphelins.
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO posts (user_id, content) VALUES (?, ?)")->execute([$me['id'], $content]);
    $postId = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO post_media (post_id, file_path, media_type, position) VALUES (?, ?, ?, ?)");
    foreach ($saved as $m) {
        $ins->execute([$postId, $m['path'], $m['type'], $m['position']]);
    }
    $pdo->commit();
    notify_mentions($pdo, $content, (int) $me['id'], $postId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($saved as $m) delete_media_file($m['path']);
    error_log('[CLASH] create_post : ' . $e->getMessage());
    flash('error', 'Erreur lors de la publication.');
    redirect('index.php');
}

if ($skipped) {
    flash('success', 'Publié, mais ' . count($skipped) . ' fichier(s) refusé(s) : ' . $skipped[0]);
} else {
    flash('success', 'Provocation publiée !');
}
redirect('index.php');
