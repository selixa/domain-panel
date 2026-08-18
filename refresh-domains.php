<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/includes/domains.php';
require __DIR__ . '/includes/rdap.php';

header('Content-Type: text/plain; charset=utf-8');

$token = $_GET['token'] ?? '';

if (REFRESH_TOKEN === '' || !hash_equals(REFRESH_TOKEN, $token)) {
    http_response_code(403);
    echo "Accès refusé.\n";
    exit;
}

$domains = loadDomains();

if (empty($domains)) {
    echo "Aucun domaine à rafraîchir.\n";
    exit;
}

$domainNames = array_column($domains, 'domain');
$results = refreshDomainsBatch($domainNames);

echo "Rafraîchissement terminé — " . count($domains) . " domaine(s) — " . date('Y-m-d H:i:s') . "\n\n";
foreach ($results as $domain => $entry) {
    echo $domain . ' -> ' . (
        ($entry['ok'] ?? false)
            ? 'OK (expire le ' . ($entry['expiration'] ?? '?') . ')'
            : 'ERREUR (' . ($entry['error'] ?? 'inconnue') . ')'
    ) . "\n";
}
