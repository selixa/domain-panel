<?php
session_start();
require __DIR__ . '/config/auth.php';

$error = null;
$setupMode = (AUTH_PASSWORD_HASH === '');
$configFile = __DIR__ . '/config/auth.php';

// Déjà connecté -> direct au panel
if (!$setupMode && !empty($_SESSION[AUTH_SESSION_NAME])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($setupMode) {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caractères.';
        } elseif ($password !== $confirm) {
            $error = 'Les deux mots de passe ne correspondent pas.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $refreshToken = bin2hex(random_bytes(20));

            $content = "<?php\n"
                . "date_default_timezone_set('Europe/Paris');\n\n"
                . "define('AUTH_PASSWORD_HASH', '" . addslashes($hash) . "');\n"
                . "define('AUTH_SESSION_NAME', 'domainpanel_auth');\n"
                . "define('REFRESH_TOKEN', '" . addslashes($refreshToken) . "');\n";

            if (file_put_contents($configFile, $content, LOCK_EX) === false) {
                $error = "Impossible d'écrire config/auth.php — vérifie les droits d'écriture (chmod) sur ce fichier/dossier.";
            } else {
                session_regenerate_id(true);
                $_SESSION[AUTH_SESSION_NAME] = true;
                $_SESSION['flash_refresh_token'] = $refreshToken;
                header('Location: index.php');
                exit;
            }
        }
    } else {
        $password = $_POST['password'] ?? '';
        if (password_verify($password, AUTH_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION[AUTH_SESSION_NAME] = true;
            header('Location: index.php');
            exit;
        }
        // Petit délai pour freiner le brute-force basique
        usleep(400000);
        $error = 'Mot de passe incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $setupMode ? 'Configuration — Domain Panel' : 'Connexion — Domain Panel' ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
    <main class="auth-box">
        <h1>🌐 Domain Panel</h1>

        <?php if ($setupMode): ?>
            <p class="auth-subtitle">Aucun mot de passe n'est configuré. Choisis-en un pour protéger le panel.</p>
            <form method="post" class="auth-form">
                <label>Mot de passe
                    <input type="password" name="password" required minlength="8" autofocus>
                </label>
                <label>Confirme le mot de passe
                    <input type="password" name="password_confirm" required minlength="8">
                </label>
                <button type="submit">Définir le mot de passe</button>
                <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
            </form>
            <p class="auth-hint">⚠️ Tant que ce mot de passe n'est pas défini, n'importe qui connaissant l'URL peut le faire à ta place. Fais-le tout de suite après l'upload.</p>
        <?php else: ?>
            <form method="post" class="auth-form">
                <label>Mot de passe
                    <input type="password" name="password" required autofocus>
                </label>
                <button type="submit">Se connecter</button>
                <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
