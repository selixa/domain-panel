<?php
require __DIR__ . '/includes/check-auth.php';
require __DIR__ . '/includes/domains.php';

$id = $_GET['id'] ?? $_POST['id'] ?? '';
$domain = $id !== '' ? findDomain($id) : null;

if (!$domain) {
    header('Location: index.php');
    exit;
}

$existingProjects = array_values(array_unique(array_filter(array_map(
    fn($d) => $d['project'] ?? '',
    loadDomains()
))));
sort($existingProjects);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    updateDomain($id, [
        'registrar' => trim($_POST['registrar'] ?? ''),
        'project' => trim($_POST['project'] ?? ''),
        'auto_renew' => isset($_POST['auto_renew']),
        'cost' => ($_POST['cost'] ?? '') !== '' ? (float) $_POST['cost'] : null,
        'notes' => trim($_POST['notes'] ?? ''),
    ]);

    header('Location: index.php?updated=' . urlencode($domain['domain']));
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modifier <?= htmlspecialchars($domain['domain']) ?> — Domain Panel</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="page">
        <header class="page-header">
            <h1>✏️ Modifier <?= htmlspecialchars($domain['domain']) ?></h1>
            <a href="index.php" class="btn btn-ghost">← Retour</a>
        </header>

        <form method="post" class="card form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= htmlspecialchars($domain['id']) ?>">

            <label>Nom de domaine
                <input type="text" value="<?= htmlspecialchars($domain['domain']) ?>" disabled>
            </label>

            <label>Registrar
                <input type="text" name="registrar" value="<?= htmlspecialchars($domain['registrar'] ?? '') ?>" placeholder="OVH, Gandi, Cloudflare..." list="registrar-list" autofocus>
                <datalist id="registrar-list">
                    <option value="OVH">
                    <option value="Gandi">
                    <option value="Cloudflare Registrar">
                    <option value="Namecheap">
                    <option value="Ionos">
                    <option value="Infomaniak">
                </datalist>
            </label>

            <label>Projet associé
                <input type="text" name="project" value="<?= htmlspecialchars($domain['project'] ?? '') ?>" placeholder="Koloni, Freat, selixa.fr..." list="project-list">
                <datalist id="project-list">
                    <?php foreach ($existingProjects as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>">
                    <?php endforeach; ?>
                </datalist>
            </label>

            <label class="checkbox-label">
                <input type="checkbox" name="auto_renew" <?= !empty($domain['auto_renew']) ? 'checked' : '' ?>>
                Renouvellement automatique activé
            </label>

            <label>Coût de renouvellement annuel (€)
                <input type="number" name="cost" step="0.01" min="0" value="<?= $domain['cost'] !== null ? htmlspecialchars((string) $domain['cost']) : '' ?>" placeholder="12.50">
            </label>

            <label>Notes
                <textarea name="notes" rows="3" placeholder="À laisser expirer, racheté suite à typo, etc."><?= htmlspecialchars($domain['notes'] ?? '') ?></textarea>
            </label>

            <button type="submit">Enregistrer</button>
            <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        </form>
    </main>
</body>
</html>
