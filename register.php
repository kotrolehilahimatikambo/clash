<?php
require_once __DIR__ . '/config/config.php';
if (is_logged_in()) redirect('index.php');

$errors = [];
$old = ['username' => '', 'display_name' => ''];
const RESERVED_USERNAMES = ['admin', 'administrator', 'root', 'clash', 'support', 'moderation', 'moderator', 'system', 'null', 'undefined'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post_too_large() || !csrf_check()) {
        $errors[] = 'Session expirée, réessaie.';
    } elseif (!empty($_POST['website'])) {
        // Honeypot rempli : c'est un robot. On fait semblant d'accepter, sans rien créer.
        redirect('login.php');
    } else {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $displayName = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['display_name'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $secretPhrase = trim((string) ($_POST['secret_phrase'] ?? ''));
        $old = ['username' => $username, 'display_name' => $displayName];
        $ipKey = 'ip:' . client_ip();

        if (throttle_count($pdo, $ipKey, 'register', 3600) >= 5) {
            $errors[] = 'Trop d’inscriptions depuis ta connexion. Réessaie plus tard.';
        }
        if (!preg_match('/^[a-z0-9_.]{3,20}$/', $username)) {
            $errors[] = 'Le pseudo doit faire 3 à 20 caractères (lettres, chiffres, _ et . uniquement).';
        } elseif (in_array($username, RESERVED_USERNAMES, true)) {
            $errors[] = 'Ce pseudo est réservé.';
        }
        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 50) {
            $errors[] = 'Le nom affiché doit faire entre 2 et 50 caractères.';
        }
        if ($pwErr = password_problem($password)) {
            $errors[] = $pwErr;
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Les mots de passe ne correspondent pas.';
        }
        if (empty($_POST['accept_terms']) || empty($_POST['accept_privacy']) || empty($_POST['accept_community'])) {
            $errors[] = 'Tu dois accepter les trois documents pour créer ton compte.';
        }
        if ($phraseErr = phrase_problem($secretPhrase, $username, $password)) {
            $errors[] = $phraseErr;
        }

        if (empty($errors)) {
            try {
                $pdo->prepare(
                    "INSERT INTO users (username, display_name, password_hash, secret_phrase_hash, terms_accepted_at, privacy_accepted_at, community_accepted_at) VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())"
                )->execute([$username, $displayName, password_hash($password, PASSWORD_DEFAULT), hash_phrase($secretPhrase)]);
                $newId = (int) $pdo->lastInsertId();
                throttle_hit($pdo, $ipKey, 'register');
                login_user($newId);
                flash('success', 'Bienvenue dans l’arène ! Commence par haïr quelques personnes.');
                redirect('discover.php');
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                $errors[] = 'Ce pseudo est déjà pris par un autre guerrier.';
            }
        }
    }
}

$pageTitle = 'Rejoindre le combat';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-screen">
    <div class="auth-card">
        <h1 class="auth-title">CLASH<span class="brand__dot">.</span></h1>
        <p class="auth-subtitle">Pas d’amis ici. Que des ennemis.</p>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert--error" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> <?= clean($e) ?></div>
        <?php endforeach; ?>

        <form method="post" class="form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="field--trap" aria-hidden="true"><label>Ne pas remplir <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            <div class="field">
                <label for="username">Pseudo <span class="field__opt">(unique)</span></label>
                <input type="text" id="username" name="username" placeholder="roi_du_clash" required autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="20" pattern="[A-Za-z0-9_.]{3,20}" value="<?= clean($old['username']) ?>">
                <small class="field__hint">3 à 20 caractères : lettres, chiffres, _ et .</small>
            </div>
            <div class="field">
                <label for="display_name">Nom affiché</label>
                <input type="text" id="display_name" name="display_name" placeholder="Le Roi du Clash" required maxlength="50" autocomplete="nickname" value="<?= clean($old['display_name']) ?>">
            </div>
            <div class="field">
                <label for="password">Mot de passe</label>
                <div class="field__pw">
                    <input type="password" id="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                    <button type="button" class="icon-btn" data-toggle-password aria-label="Afficher le mot de passe"><i class="fa-regular fa-eye"></i></button>
                </div>
                <small class="field__hint"><?= PASSWORD_MIN_LENGTH ?> caractères minimum</small>
            </div>
            <div class="field">
                <label for="password_confirm">Confirme le mot de passe</label>
                <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            </div>
            <div class="field">
                <label for="secret_phrase">Phrase magique</label>
                <input type="text" id="secret_phrase" name="secret_phrase" placeholder="mon chat déteste tout le monde" required minlength="12" autocomplete="off">
                <small class="field__hint">Ton seul moyen de récupérer ton compte (pas d’email ni de tél.). Une vraie phrase : 3 mots et 12 caractères minimum, sans ton pseudo.</small>
            </div>
            <div class="legal-consents">
                <label><input type="checkbox" name="accept_terms" value="1" required> J'accepte les <a href="<?= BASE_URL ?>/legal.php?doc=terms" target="_blank" rel="noopener">Conditions d'utilisation</a>.</label>
                <label><input type="checkbox" name="accept_privacy" value="1" required> J'ai lu la <a href="<?= BASE_URL ?>/legal.php?doc=privacy" target="_blank" rel="noopener">Politique de confidentialité</a>.</label>
                <label><input type="checkbox" name="accept_community" value="1" required> J'accepte les <a href="<?= BASE_URL ?>/legal.php?doc=community" target="_blank" rel="noopener">Standards de la communauté</a>.</label>
            </div>
            <button type="submit" class="btn btn--primary btn--block btn--lg"><i class="fa-solid fa-skull"></i> Entrer dans l’arène</button>
        </form>

        <p class="auth-switch">Déjà un compte ? <a href="<?= BASE_URL ?>/login.php">Connecte-toi</a></p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
