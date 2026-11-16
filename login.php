<?php
require_once __DIR__ . '/config/config.php';
if (is_logged_in()) redirect('index.php');

$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post_too_large() || !csrf_check()) {
        $errors[] = 'Session expirée, réessaie.';
    } else {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $keys = throttle_keys($username);

        // Anti-brute-force PROGRESSIF : 5 échecs → 15 min, 10 → 1 h, 15 → 24 h (par IP + pseudo) ; 30 échecs / 15 min par IP
        $lock = throttle_lock_remaining($pdo, $keys['ipuser'], 'login');
        if ($lock > 0 || throttle_count($pdo, $keys['ip'], 'login', 900) >= 30) {
            $errors[] = lock_message($lock > 0 ? $lock : 900);
        } else {
            $user = get_user_by_username($pdo, $username);
            // Toujours vérifier un hash (même si le pseudo n'existe pas) : temps de réponse constant.
            $valid = password_verify($password, $user['password_hash'] ?? DUMMY_PASSWORD_HASH) && $user;

            if (!$valid) {
                throttle_hit($pdo, $keys['ipuser'], 'login');
                throttle_hit($pdo, $keys['ip'], 'login');
                $errors[] = 'Pseudo ou mot de passe incorrect.';
            } elseif ((int) $user['is_active'] !== 1) {
                $errors[] = 'Ce compte est suspendu.';
            } else {
                throttle_clear($pdo, $keys['ipuser'], 'login');
                login_user((int) $user['id']);
                redirect('index.php');
            }
        }
    }
}

$pageTitle = 'Connexion';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-screen">
    <div class="auth-card">
        <h1 class="auth-title">CLASH<span class="brand__dot">.</span></h1>
        <p class="auth-subtitle">Reviens te battre.</p>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert--error" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> <?= clean($e) ?></div>
        <?php endforeach; ?>

        <form method="post" class="form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="field">
                <label for="username">Pseudo</label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= clean($username) ?>">
            </div>
            <div class="field">
                <label for="password">Mot de passe</label>
                <div class="field__pw">
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    <button type="button" class="icon-btn" data-toggle-password aria-label="Afficher le mot de passe"><i class="fa-regular fa-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="btn btn--primary btn--block btn--lg"><i class="fa-solid fa-fire"></i> Se connecter</button>
        </form>

        <p class="auth-switch"><a href="<?= BASE_URL ?>/forgot_password.php">Mot de passe oublié ?</a></p>
        <p class="auth-switch">Pas encore de compte ? <a href="<?= BASE_URL ?>/register.php">Inscris-toi</a></p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
