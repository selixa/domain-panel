<?php
require __DIR__ . '/includes/check-auth.php';
require __DIR__ . '/includes/domains.php';
require __DIR__ . '/includes/rdap.php';

// Actions de rafraîchissement manuel (bouton dans l'interface)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_all') {
    csrf_check();
    foreach (loadDomains() as $d) {
        refreshDomainCache($d['domain'], true);
    }
    header('Location: index.php?refreshed=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_one') {
    csrf_check();
    $domain = $_POST['domain'] ?? '';
    if ($domain !== '') {
        refreshDomainCache($domain, true);
    }
    header('Location: index.php?refreshed=1');
    exit;
}

$domains = loadDomains();

// Enrichissement avec les données du cache RDAP + calcul du statut
$rows = [];
foreach ($domains as $d) {
    $cached = getCachedExpiration($d['domain']);
    $expiration = $cached['expiration'] ?? null;
    $daysLeft = null;
    $status = 'unknown'; // unknown | ok | warning | soon | expired | error

    if ($expiration) {
        $expTs = strtotime($expiration);
        $daysLeft = (int) floor(($expTs - time()) / 86400);
        if ($daysLeft < 0) {
            $status = 'expired';
        } elseif ($daysLeft < 30) {
            $status = 'soon';
        } elseif ($daysLeft < 60) {
            $status = 'warning';
        } else {
            $status = 'ok';
        }
    } elseif ($cached && !($cached['ok'] ?? false)) {
        $status = 'error';
    }

    $rows[] = [
        'domain' => $d,
        'cached' => $cached,
        'expiration' => $expiration,
        'daysLeft' => $daysLeft,
        'status' => $status,
    ];
}

// Tri: domaines qui expirent le plus vite en premier, non vérifiés à la fin
usort($rows, function ($a, $b) {
    if ($a['daysLeft'] === null && $b['daysLeft'] === null) return 0;
    if ($a['daysLeft'] === null) return 1;
    if ($b['daysLeft'] === null) return -1;
    return $a['daysLeft'] <=> $b['daysLeft'];
});

$totalCost = 0;
foreach ($domains as $d) {
    $totalCost += (float) ($d['cost'] ?? 0);
}

$statusLabels = [
    'ok' => 'OK',
    'warning' => '< 60j',
    'soon' => '< 30j',
    'expired' => 'Expiré',
    'error' => 'Non disponible',
    'unknown' => 'Non vérifié',
];

$flashToken = $_SESSION['flash_refresh_token'] ?? null;
unset($_SESSION['flash_refresh_token']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Domain Panel</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="page">
        <header class="page-header">
            <h1>🌐 Domain Panel</h1>
            <div class="header-actions">
                <a href="add-domain.php" class="btn btn-primary">+ Ajouter un domaine</a>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refresh_all">
                    <button type="submit" class="btn btn-ghost">↻ Tout rafraîchir</button>
                </form>
                <a href="logout.php" class="btn btn-ghost">Déconnexion</a>
            </div>
        </header>

        <?php if ($flashToken): ?>
            <div class="flash flash-token">
                <strong>Configuration du cron :</strong> note bien ce token, il ne sera plus affiché.
                <br>URL à appeler une fois par jour :
                <code>https://tondomaine.fr/refresh-domains.php?token=<?= htmlspecialchars($flashToken) ?></code>
                <br>Sur Planethoster : panel N0C/cPanel → Tâches CRON → type "wget" ou "curl" sur cette URL, fréquence quotidienne.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['added'])): ?>
            <div class="flash">Domaine <strong><?= htmlspecialchars($_GET['added']) ?></strong> ajouté.</div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="flash">Domaine supprimé.</div>
        <?php endif; ?>
        <?php if (isset($_GET['updated'])): ?>
            <div class="flash">Domaine <strong><?= htmlspecialchars($_GET['updated']) ?></strong> mis à jour.</div>
        <?php endif; ?>
        <?php if (isset($_GET['refreshed'])): ?>
            <div class="flash">Données RDAP rafraîchies.</div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat-card">
                <span class="stat-value"><?= count($domains) ?></span>
                <span class="stat-label">domaines suivis</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?= number_format($totalCost, 2, ',', ' ') ?> €</span>
                <span class="stat-label">coût annuel estimé</span>
            </div>
            <div class="stat-card stat-danger">
                <span class="stat-value"><?= count(array_filter($rows, fn($r) => in_array($r['status'], ['soon', 'expired'], true))) ?></span>
                <span class="stat-label">à surveiller (&lt; 30j)</span>
            </div>
        </div>

        <?php if (empty($domains)): ?>
            <div class="card empty-state">
                <p>Aucun domaine pour l'instant.</p>
                <a href="add-domain.php" class="btn btn-primary">Ajouter ton premier domaine</a>
            </div>
        <?php else: ?>
            <div class="card table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Domaine</th>
                            <th>Projet</th>
                            <th>Registrar</th>
                            <th>Expire le</th>
                            <th>Statut</th>
                            <th>Auto-renew</th>
                            <th>Coût/an</th>
                            <th>Notes</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $d = $row['domain'];
                            $cached = $row['cached'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($d['domain']) ?></strong>
                                    <?php if ($cached && !empty($cached['nameservers'])): ?>
                                        <div class="cell-sub"><?= count($cached['nameservers']) ?> nameserver(s)</div>
                                    <?php endif; ?>
                                    <?php if (($cached['source'] ?? null) === 'whois'): ?>
                                        <div class="cell-sub" title="RDAP non supporté pour ce TLD, données récupérées via WHOIS classique">
                                            via WHOIS<?= !empty($cached['whois_server']) ? ' (' . htmlspecialchars($cached['whois_server']) . ')' : '' ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($row['status'] === 'error'): ?>
                                        <div class="cell-sub cell-error" title="<?= htmlspecialchars($cached['error'] ?? '') ?>">
                                            RDAP + WHOIS indisponibles
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($d['project'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($d['registrar'] ?: '—') ?></td>
                                <td>
                                    <?php if ($row['expiration']): ?>
                                        <?= htmlspecialchars(date('d/m/Y', strtotime($row['expiration']))) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $row['status'] ?>">
                                        <?= $statusLabels[$row['status']] ?>
                                        <?php if ($row['daysLeft'] !== null): ?>
                                            (<?= $row['daysLeft'] ?>j)
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td><?= !empty($d['auto_renew']) ? '✅' : '❌' ?></td>
                                <td><?= $d['cost'] !== null ? number_format((float) $d['cost'], 2, ',', ' ') . ' €' : '—' ?></td>
                                <td class="cell-notes"><?= htmlspecialchars($d['notes'] ?: '') ?></td>
                                <td class="cell-actions">
                                    <a href="edit-domain.php?id=<?= htmlspecialchars($d['id']) ?>" class="btn-icon" title="Modifier">&#9998;&#xFE0E;</a>
                                    <form method="post" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="refresh_one">
                                        <input type="hidden" name="domain" value="<?= htmlspecialchars($d['domain']) ?>">
                                        <button type="submit" class="btn-icon" title="Vérifier maintenant">↻</button>
                                    </form>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Supprimer <?= htmlspecialchars($d['domain']) ?> de la liste ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($d['id']) ?>">
                                        <button type="submit" formaction="delete-domain.php" class="btn-icon btn-icon-danger" title="Supprimer">✕</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
