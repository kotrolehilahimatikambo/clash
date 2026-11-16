<?php
require_once __DIR__ . '/config/config.php';

// La déconnexion exige un POST + jeton CSRF : un simple <img src="logout.php"> sur un site tiers
// ne peut plus déconnecter les utilisateurs de force.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    logout_user();
    redirect('login.php');
}
redirect('index.php');
