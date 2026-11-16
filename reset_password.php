<?php
require_once __DIR__ . '/config/config.php';
if (is_logged_in()) redirect('index.php');

// L'autorisation de réinitialiser expire (10 min) : une session abandonnée ne reste pas « déverrouillée ».
if (empty($_SESSION['reset_user_id']) || ($_SESSION['reset_expires'] ?? 0) < time()) {
    unset($_SESSION['reset_user_id'], $_SESSION['reset_expires']);
    redirect('forgot_password.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post_too_large() || !csrf_check()) {
        $errors[] = 'Session expirée, réessaie.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($pwErr = password_problem($password)) $errors[] = $pwErr;
        if ($password !== $passwordConfirm) $errors[] = 'Les mots de passe ne correspondent pas.';

        if (empty($errors)) {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $_SESSION['reset_user_id']]);
            unset($_SESSION['reset_user_id'], $_SESSION['reset_expires']);
            session_regenerate_id(true);
            flash('success', 'Mot de passe réinitialisé. Tu peux te reconnecter.');
            redirect('login.php');
        }
    }
}

$pageTitle = 'Nouveau mot de passe';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-screen">
    <div class="auth-card">
        <h1 class="auth-title">CLASH<span class="brand__dot">.</span></h1>
        <p class="auth-subtitle">Choisis un nouveau mot de passe.</p>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert--error" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> <?= clean($e) ?></div>
        <?php endforeach; ?>

        <form method="post" class="form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="field">
                <label for="password">Nouveau mot de passe</label>
                <div class="field__pw">
                    <input type="password" id="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" autofocus>
                    <button type="button" class="icon-btn" data-toggle-password aria-label="Afficher le mot de passe"><i class="fa-regular fa-eye"></i></button>
                </div>
                <small class="field__hint"><?= PASSWORD_MIN_LENGTH ?> caractères minimum</small>
            </div>
            <div class="field">
                <label for="password_confirm">Confirme le mot de passe</label>
                <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn--primary btn--block btn--lg"><i class="fa-solid fa-lock"></i> Valider</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
