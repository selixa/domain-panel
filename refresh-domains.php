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

$results = [];
foreach ($domains as $d) {
    $entry = refreshDomainCache($d['domain'], true);
    $results[] = $d['domain'] . ' -> ' . (
        ($entry['ok'] ?? false)
            ? 'OK (expire le ' . ($entry['expiration'] ?? '?') . ')'
            : 'ERREUR (' . ($entry['error'] ?? 'inconnue') . ')'
    );
}

echo "Rafraîchissement terminé — " . count($domains) . " domaine(s) — " . date('Y-m-d H:i:s') . "\n\n";
echo implode("\n", $results) . "\n";
