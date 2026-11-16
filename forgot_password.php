<?php
require_once __DIR__ . '/config/config.php';
if (is_logged_in()) redirect('index.php');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post_too_large() || !csrf_check()) {
        $errors[] = 'Session expirée, réessaie.';
    } else {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $phrase = (string) ($_POST['secret_phrase'] ?? '');
        $keys = throttle_keys($username);

        // La phrase magique est LE point faible du système (pas d'email) : on la protège strictement.
        // Verrouillage progressif (5 échecs → 15 min, 10 → 1 h, 15 → 24 h) et 15 essais / heure par IP.
        $lock = throttle_lock_remaining($pdo, $keys['ipuser'], 'phrase');
        if ($lock > 0 || throttle_count($pdo, $keys['ip'], 'phrase', 3600) >= 15) {
            $errors[] = lock_message($lock > 0 ? $lock : 900);
        } else {
            $user = get_user_by_username($pdo, $username);
            $hash = $user['secret_phrase_hash'] ?? DUMMY_PASSWORD_HASH;
            $ok = $user && verify_phrase($phrase, $hash);
            if (!$user) password_verify($phrase, DUMMY_PASSWORD_HASH); // temps de réponse constant

            if (!$ok || (int) $user['is_active'] !== 1) {
                throttle_hit($pdo, $keys['ipuser'], 'phrase');
                throttle_hit($pdo, $keys['ip'], 'phrase');
                $errors[] = 'Pseudo ou phrase magique incorrecte.';
            } else {
                throttle_clear($pdo, $keys['ipuser'], 'phrase');
                session_regenerate_id(true);
                $_SESSION['reset_user_id'] = (int) $user['id'];
                $_SESSION['reset_expires'] = time() + 600; // 10 minutes pour choisir le nouveau mot de passe
                redirect('reset_password.php');
            }
        }
    }
}

$pageTitle = 'Mot de passe oublié';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-screen">
    <div class="auth-card">
        <h1 class="auth-title">CLASH<span class="brand__dot">.</span></h1>
        <p class="auth-subtitle">Pas d’email, pas de SMS. Juste ta phrase magique.</p>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert--error" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> <?= clean($e) ?></div>
        <?php endforeach; ?>

        <form method="post" class="form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="field">
                <label for="username">Pseudo</label>
                <input type="text" id="username" name="username" required autofocus autocapitalize="none" spellcheck="false" autocomplete="username">
            </div>
            <div class="field">
                <label for="secret_phrase">Phrase magique</label>
                <input type="text" id="secret_phrase" name="secret_phrase" required autocomplete="off">
            </div>
            <button type="submit" class="btn btn--primary btn--block btn--lg"><i class="fa-solid fa-key"></i> Vérifier</button>
        </form>
        <p class="auth-switch"><a href="<?= BASE_URL ?>/login.php">Retour à la connexion</a></p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
