<?php
session_start();
require_once __DIR__ . '/../config/auth.php';

// Pas de mot de passe configuré -> on force le passage par le setup
if (AUTH_PASSWORD_HASH === '') {
    header('Location: /login.php');
    exit;
}

// Pas connecté -> login
if (empty($_SESSION[AUTH_SESSION_NAME])) {
    header('Location: /login.php');
    exit;
}

// Token CSRF léger pour les formulaires POST (ajout, suppression, refresh)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['csrf']) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    $expected = $_SESSION['csrf'] ?? '__none__';
    if (!hash_equals($expected, $sent)) {
        http_response_code(403);
        die('Requête invalide (CSRF). Recharge la page et réessaie.');
    }
}
