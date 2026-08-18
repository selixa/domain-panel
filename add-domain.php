<?php
require __DIR__ . '/includes/check-auth.php';
require __DIR__ . '/includes/domains.php';
require __DIR__ . '/includes/rdap.php';

$error = null;
$existingProjects = array_values(array_unique(array_filter(array_map(
    fn($d) => $d['project'] ?? '',
    loadDomains()
))));
sort($existingProjects);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $domain = strtolower(trim($_POST['domain'] ?? ''));
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = rtrim($domain, '/');

    if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain)) {
        $error = 'Nom de domaine invalide (attendu: exemple.com).';
    } else {
        $alreadyExists = false;
        foreach (loadDomains() as $d) {
            if ($d['domain'] === $domain) {
                $alreadyExists = true;
                break;
            }
        }

        if ($alreadyExists) {
            $error = 'Ce domaine est déjà dans la liste.';
        } else {
            addDomain([
                'domain' => $domain,
                'registrar' => trim($_POST['registrar'] ?? ''),
                'project' => trim($_POST['project'] ?? ''),
                'auto_renew' => isset($_POST['auto_renew']),
                'cost' => ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null,
                'notes' => trim($_POST['notes'] ?? ''),
            ]);

            // Vérification RDAP immédiate pour ne pas laisser le domaine
            // "en attente" jusqu'au prochain cron
            refreshDomainCache($domain, true);

            header('Location: index.php?added=' . urlencode($domain));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ajouter un domaine — Domain Panel</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="page">
        <header class="page-header">
            <h1>➕ Ajouter un domaine</h1>
            <a href="index.php" class="btn btn-ghost">← Retour</a>
        </header>

        <form method="post" class="card form">
            <?= csrf_field() ?>

            <label>Nom de domaine *
                <input type="text" name="domain" placeholder="exemple.com" required autofocus>
            </label>

            <label>Registrar
                <input type="text" name="registrar" placeholder="OVH, Gandi, Cloudflare..." list="registrar-list">
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
                <input type="text" name="project" placeholder="Koloni, Freat, selixa.fr..." list="project-list">
                <datalist id="project-list">
                    <?php foreach ($existingProjects as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>">
                    <?php endforeach; ?>
                </datalist>
            </label>

            <label class="checkbox-label">
                <input type="checkbox" name="auto_renew" checked>
                Renouvellement automatique activé
            </label>

            <label>Coût de renouvellement annuel (€)
                <input type="number" name="cost" step="0.01" min="0" placeholder="12.50">
            </label>

            <label>Notes
                <textarea name="notes" rows="3" placeholder="À laisser expirer, racheté suite à typo, etc."></textarea>
            </label>

            <button type="submit">Ajouter le domaine</button>
            <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        </form>
    </main>
</body>
</html>
