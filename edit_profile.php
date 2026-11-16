<?php
require_once __DIR__ . '/config/config.php';
require_login();
$me = current_user($pdo);
$errors = [];
$successes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post_too_large()) {
        $errors[] = 'Fichier trop volumineux (' . (int) (MAX_FILE_SIZE / 1048576) . ' Mo max).';
    } elseif (!csrf_check()) {
        $errors[] = 'Session expirée, recharge la page et réessaie.';
    } else {
        // --- Informations ---
        if (isset($_POST['update_info'])) {
            $displayName = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['display_name'] ?? '')));
            $bio = trim((string) ($_POST['bio'] ?? ''));
            if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 50) {
                $errors[] = 'Le nom affiché doit faire entre 2 et 50 caractères.';
            }
            if (mb_strlen($bio) > 280) {
                $errors[] = 'La bio est trop longue (280 caractères max).';
            }
            if (!$errors) {
                $pdo->prepare("UPDATE users SET display_name = ?, bio = ? WHERE id = ?")->execute([$displayName, $bio, $me['id']]);
                $successes[] = 'Profil mis à jour.';
            }
        }

        // --- Mot de passe ---
        if (isset($_POST['update_password'])) {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['new_password_confirm'] ?? '');
            $key = 'pw:' . $me['id'];
            if (throttle_count($pdo, $key, 'pwchange', 900) >= 5) {
                $errors[] = 'Trop de tentatives. Réessaie dans 15 minutes.';
            } elseif (!password_verify($current, $me['password_hash'])) {
                throttle_hit($pdo, $key, 'pwchange');
                $errors[] = 'Mot de passe actuel incorrect.';
            } elseif ($pwErr = password_problem($new)) {
                $errors[] = $pwErr;
            } elseif ($new !== $confirm) {
                $errors[] = 'Les deux nouveaux mots de passe ne correspondent pas.';
            } else {
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
                session_regenerate_id(true);
                $successes[] = 'Mot de passe mis à jour.';
            }
        }

        // --- Phrase magique (exige le mot de passe actuel : sinon une session volée suffirait à prendre le compte) ---
        if (isset($_POST['update_phrase'])) {
            $newPhrase = (string) ($_POST['new_phrase'] ?? '');
            $current = (string) ($_POST['current_password_phrase'] ?? '');
            $key = 'pw:' . $me['id'];
            if (throttle_count($pdo, $key, 'pwchange', 900) >= 5) {
                $errors[] = 'Trop de tentatives. Réessaie dans 15 minutes.';
            } elseif (!password_verify($current, $me['password_hash'])) {
                throttle_hit($pdo, $key, 'pwchange');
                $errors[] = 'Mot de passe actuel incorrect.';
            } elseif ($phraseErr = phrase_problem($newPhrase, $me['username'], $current)) {
                $errors[] = $phraseErr;
            } else {
                $pdo->prepare("UPDATE users SET secret_phrase_hash = ? WHERE id = ?")->execute([hash_phrase($newPhrase), $me['id']]);
                $successes[] = 'Phrase magique mise à jour.';
            }
        }

        // --- Confidentialité et notifications ---
        if (isset($_POST['update_privacy'])) {
            $mode = (string) ($_POST['allow_messages'] ?? 'everyone');
            if (!in_array($mode, ['everyone', 'enemies', 'nobody'], true)) $mode = 'everyone';
            $pdo->prepare(
                "UPDATE users SET profile_private = ?, hide_from_explore = ?, allow_messages = ?, allow_challenges = ?,
                                  notify_reactions = ?, notify_comments = ?, notify_haters = ? WHERE id = ?"
            )->execute([
                isset($_POST['profile_private']) ? 1 : 0,
                isset($_POST['hide_from_explore']) ? 1 : 0,
                $mode,
                isset($_POST['allow_challenges']) ? 1 : 0,
                isset($_POST['notify_reactions']) ? 1 : 0,
                isset($_POST['notify_comments']) ? 1 : 0,
                isset($_POST['notify_haters']) ? 1 : 0,
                $me['id'],
            ]);
            $successes[] = 'Réglages de confidentialité enregistrés.';
        }

        // --- Suppression définitive du compte ---
        if (isset($_POST['delete_account'])) {
            $key = 'pw:' . $me['id'];
            if (throttle_count($pdo, $key, 'pwchange', 900) >= 5) {
                $errors[] = 'Trop de tentatives. Réessaie dans 15 minutes.';
            } elseif (strtolower(trim((string) ($_POST['delete_confirm'] ?? ''))) !== $me['username']) {
                $errors[] = 'Tape ton pseudo exact pour confirmer la suppression.';
            } elseif (!password_verify((string) ($_POST['delete_password'] ?? ''), $me['password_hash'])) {
                throttle_hit($pdo, $key, 'pwchange');
                $errors[] = 'Mot de passe incorrect.';
            } elseif ($err = delete_user_account($pdo, $me['id'])) {
                $errors[] = $err;
            } else {
                logout_user();
                session_start(); // nouvelle session vide, juste pour afficher le message d'adieu
                flash('success', 'Ton compte a été supprimé définitivement. Adieu, guerrier.');
                redirect('login.php');
            }
        }

        // --- Photos ---
        if (isset($_POST['update_photos'])) {
            foreach ([
                'profile_photo' => ['col' => 'profile_photo', 'dir' => UPLOAD_DIR_PROFILE, 'sub' => 'profile', 'w' => 500, 'h' => 500, 'ok' => 'Photo de profil mise à jour.', 'default' => 'assets/img/default-profile.png'],
                'cover_photo'   => ['col' => 'cover_photo',   'dir' => UPLOAD_DIR_COVER,   'sub' => 'cover',   'w' => 1600, 'h' => 800, 'ok' => 'Photo de couverture mise à jour.', 'default' => 'assets/img/default-cover.png'],
            ] as $field => $cfg) {
                if (empty($_FILES[$field]) || (int) $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) continue;
                $res = save_uploaded_media($_FILES[$field], ALLOWED_IMAGE_TYPES, $cfg['dir'], $cfg['w'], $cfg['h']);
                if (!$res['ok']) {
                    $errors[] = ($field === 'profile_photo' ? 'Photo de profil : ' : 'Couverture : ') . $res['error'];
                    continue;
                }
                $new = 'assets/uploads/' . $cfg['sub'] . '/' . $res['filename'];
                $old = $me[$cfg['col']];
                $pdo->prepare("UPDATE users SET {$cfg['col']} = ? WHERE id = ?")->execute([$new, $me['id']]);
                if ($old && $old !== $cfg['default']) delete_media_file($old); // plus de fichiers orphelins
                $successes[] = $cfg['ok'];
            }
            if (!$errors && !$successes) $errors[] = 'Choisis d’abord une image.';
        }
    }
    $me = current_user($pdo, true); // relecture : le cache de current_user() serait périmé
}

$pageTitle = 'Réglages';
$navActive = 'settings';
require_once __DIR__ . '/includes/header.php';
?>
<?= page_head('Réglages', BASE_URL . '/index.php', 'fa-gear') ?>

<?php foreach ($errors as $e): ?><div class="alert alert--error" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> <?= clean($e) ?></div><?php endforeach; ?>
<?php foreach ($successes as $s): ?><div class="alert alert--success" role="status"><i class="fa-solid fa-circle-check"></i> <?= clean($s) ?></div><?php endforeach; ?>

<div class="card" id="apparence">
    <h2 class="card__title">Apparence et langue</h2>
    <div class="pref-row">
        <div class="pref-row__text"><strong>Mode sombre / clair</strong><small>Choisis l’ambiance du champ de bataille.</small></div>
        <button type="button" class="icon-btn" id="themeToggle" aria-label="Basculer entre mode clair et sombre"><span class="theme-switch__track" aria-hidden="true"><span class="theme-switch__thumb"></span></span><span class="theme-switch__label">Thème</span></button>
    </div>
    <div class="pref-row">
        <div class="pref-row__text"><strong>Langue</strong><small>Langue de l’interface.</small></div>
        <select id="languageSelect" aria-label="Langue"><option value="fr">Français</option><option value="en">English</option><option value="de">Deutsch</option><option value="es">Español</option><option value="zh">中文</option></select>
    </div>
</div>

<div class="card">
    <h2 class="card__title">Photos</h2>
    <form method="post" enctype="multipart/form-data" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="photo-edit">
            <div class="photo-edit__cover" style="background-image:url('<?= media_url($me['cover_photo'], 'assets/img/default-cover.png') ?>')" id="coverPreview"></div>
            <img class="photo-edit__avatar" src="<?= media_url($me['profile_photo']) ?>" alt="" id="avatarPreview">
        </div>
        <div class="field">
            <label for="profile_photo">Photo de profil</label>
            <input type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="avatarPreview">
        </div>
        <div class="field">
            <label for="cover_photo">Photo de couverture</label>
            <input type="file" id="cover_photo" name="cover_photo" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="coverPreview" data-bg="1">
        </div>
        <button class="btn btn--primary" type="submit" name="update_photos">Enregistrer les photos</button>
    </form>
</div>

<div class="card">
    <h2 class="card__title">Informations</h2>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="field">
            <label for="display_name">Nom affiché</label>
            <input type="text" id="display_name" name="display_name" maxlength="50" required value="<?= clean($me['display_name']) ?>">
        </div>
        <div class="field">
            <label for="bio">Bio</label>
            <textarea id="bio" name="bio" maxlength="280" rows="3" data-counter="bioCount"><?= clean($me['bio']) ?></textarea>
            <small class="field__hint"><span id="bioCount"><?= mb_strlen((string) $me['bio']) ?></span> / 280</small>
        </div>
        <button class="btn btn--primary" type="submit" name="update_info">Enregistrer</button>
    </form>
</div>

<div class="card">
    <h2 class="card__title">Mot de passe</h2>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="field">
            <label for="current_password">Mot de passe actuel</label>
            <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        </div>
        <div class="field">
            <label for="new_password">Nouveau mot de passe</label>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" required>
            <small class="field__hint"><?= PASSWORD_MIN_LENGTH ?> caractères minimum</small>
        </div>
        <div class="field">
            <label for="new_password_confirm">Confirme le nouveau mot de passe</label>
            <input type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password" required>
        </div>
        <button class="btn btn--primary" type="submit" name="update_password">Changer le mot de passe</button>
    </form>
</div>

<div class="card">
    <h2 class="card__title">Phrase magique</h2>
    <p class="muted">Sert à récupérer ton compte sans email ni téléphone. Garde-la secrète.</p>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="field">
            <label for="new_phrase">Nouvelle phrase magique</label>
            <input type="text" id="new_phrase" name="new_phrase" autocomplete="off" minlength="12" required>
            <small class="field__hint">3 mots et 12 caractères minimum, sans ton pseudo.</small>
        </div>
        <div class="field">
            <label for="current_password_phrase">Mot de passe actuel (par sécurité)</label>
            <input type="password" id="current_password_phrase" name="current_password_phrase" autocomplete="current-password" required>
        </div>
        <button class="btn btn--primary" type="submit" name="update_phrase">Changer la phrase</button>
    </form>
</div>

<div class="card card--danger">
    <h2 class="card__title">Supprimer mon compte</h2>
    <p class="muted">Définitif et impossible à annuler : ton profil, tes publications, commentaires, réactions, relations et conversations sont effacés (y compris tes messages dans les conversations des autres).</p>
    <form method="post" class="form" data-confirm="Supprimer définitivement ton compte ?">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="field">
            <label for="delete_confirm">Tape ton pseudo (<strong>@<?= clean($me['username']) ?></strong>) pour confirmer</label>
            <input type="text" id="delete_confirm" name="delete_confirm" autocomplete="off" autocapitalize="none" spellcheck="false" required>
        </div>
        <div class="field">
            <label for="delete_password">Mot de passe</label>
            <input type="password" id="delete_password" name="delete_password" autocomplete="current-password" required>
        </div>
        <button class="btn btn--danger" type="submit" name="delete_account"><i class="fa-solid fa-trash"></i> Supprimer définitivement mon compte</button>
    </form>
</div>

<div class="card">
    <h2 class="card__title">Confidentialité</h2>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

        <label class="check">
            <input type="checkbox" name="profile_private" value="1" <?= $me['profile_private'] ? 'checked' : '' ?>>
            <span><strong>Profil privé</strong><small>Seuls tes ennemis jurés voient tes publications. Les autres voient ton profil, mais pas ce que tu écris.</small></span>
        </label>
        <label class="check">
            <input type="checkbox" name="hide_from_explore" value="1" <?= $me['hide_from_explore'] ? 'checked' : '' ?>>
            <span><strong>Mode fantôme</strong><small>Tu n’apparais ni dans Explorer, ni dans les suggestions, ni dans la recherche (on te trouve avec ton pseudo exact).</small></span>
        </label>
        <label class="check">
            <input type="checkbox" name="allow_challenges" value="1" <?= $me['allow_challenges'] ? 'checked' : '' ?>>
            <span><strong>Accepter les déclarations de guerre</strong><small>Décoche pour que personne ne puisse te défier.</small></span>
        </label>
        <div class="field">
            <label for="allow_messages">Qui peut t’écrire ?</label>
            <select id="allow_messages" name="allow_messages">
                <option value="everyone" <?= $me['allow_messages'] === 'everyone' ? 'selected' : '' ?>>Tout le monde (sous forme de demande)</option>
                <option value="enemies" <?= $me['allow_messages'] === 'enemies' ? 'selected' : '' ?>>Mes ennemis jurés uniquement</option>
                <option value="nobody" <?= $me['allow_messages'] === 'nobody' ? 'selected' : '' ?>>Personne</option>
            </select>
        </div>

        <h3 class="card__subtitle">Notifications</h3>
        <label class="check"><input type="checkbox" name="notify_reactions" value="1" <?= $me['notify_reactions'] ? 'checked' : '' ?>><span><strong>Réactions</strong><small>Quand quelqu’un réagit à une de tes publications.</small></span></label>
        <label class="check"><input type="checkbox" name="notify_comments" value="1" <?= $me['notify_comments'] ? 'checked' : '' ?>><span><strong>Ripostes</strong><small>Quand quelqu’un commente tes publications.</small></span></label>
        <label class="check"><input type="checkbox" name="notify_haters" value="1" <?= $me['notify_haters'] ? 'checked' : '' ?>><span><strong>Nouveaux haters</strong><small>Quand quelqu’un se met à te haïr.</small></span></label>

        <button class="btn btn--primary" type="submit" name="update_privacy">Enregistrer</button>
    </form>
    <a href="<?= BASE_URL ?>/blocked.php" class="settings-link settings-link--sep"><i class="fa-solid fa-ban"></i> Utilisateurs bloqués <i class="fa-solid fa-chevron-right"></i></a>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
