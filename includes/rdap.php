<?php

require_once __DIR__ . '/whois.php';

define('CACHE_FILE', __DIR__ . '/../cache/expirations.json');
define('CACHE_TTL', 86400); // 24h

function loadCache(): array
{
    if (!file_exists(CACHE_FILE)) {
        return [];
    }
    $data = json_decode(file_get_contents(CACHE_FILE), true);
    return is_array($data) ? $data : [];
}

function saveCache(array $cache): void
{
    $fp = fopen(CACHE_FILE, 'c+');
    if ($fp === false) {
        return;
    }
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

/**
 * Interroge RDAP via rdap.org, qui fait le routage vers le bon registre
 * (IANA bootstrap) selon le TLD. Pas de clé API nécessaire.
 */
function fetchRdap(string $domain): ?array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => "Extension PHP 'curl' non activée sur l'hébergement."];
    }

    $url = 'https://rdap.org/domain/' . rawurlencode($domain);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/rdap+json'],
        CURLOPT_USERAGENT => 'domain-panel/1.0 (+personal use)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode !== 200) {
        if ($httpCode === 429) {
            return [
                'ok' => false,
                'error' => 'HTTP 429 — rdap.org limite à 10 requêtes/10s, réessaie dans quelques secondes',
            ];
        }
        return [
            'ok' => false,
            'error' => $curlError ?: ('HTTP ' . $httpCode),
        ];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Réponse RDAP illisible'];
    }

    $expiration = null;
    $registered = null;
    foreach ($json['events'] ?? [] as $event) {
        $action = $event['eventAction'] ?? '';
        if ($action === 'expiration') {
            $expiration = $event['eventDate'] ?? null;
        }
        if ($action === 'registration') {
            $registered = $event['eventDate'] ?? null;
        }
    }

    $nameservers = [];
    foreach ($json['nameservers'] ?? [] as $ns) {
        if (!empty($ns['ldhName'])) {
            $nameservers[] = strtolower(rtrim($ns['ldhName'], '.'));
        }
    }

    $registrarName = null;
    foreach ($json['entities'] ?? [] as $entity) {
        if (in_array('registrar', $entity['roles'] ?? [], true)) {
            foreach ($entity['vcardArray'][1] ?? [] as $field) {
                if (($field[0] ?? '') === 'fn') {
                    $registrarName = $field[3] ?? null;
                    break;
                }
            }
        }
    }

    return [
        'ok' => true,
        'expiration' => $expiration,
        'registered' => $registered,
        'nameservers' => $nameservers,
        'status' => $json['status'] ?? [],
        'registrar_rdap' => $registrarName,
    ];
}

/**
 * Rafraîchit (ou renvoie depuis le cache si assez frais) les infos RDAP
 * d'un domaine. $force ignore la fraîcheur du cache.
 */
function refreshDomainCache(string $domain, bool $force = false): array
{
    $cache = loadCache();
    $now = time();

    if (!$force && isset($cache[$domain]) && ($now - $cache[$domain]['fetched_at']) < CACHE_TTL) {
        return $cache[$domain];
    }

    $data = fetchRdap($domain);
    $source = 'rdap';

    if (!($data['ok'] ?? false)) {
        $rdapError = $data['error'] ?? 'Échec RDAP';
        $whois = fetchWhoisFallback($domain);
        if ($whois['ok'] ?? false) {
            $data = $whois;
            $source = 'whois';
        } else {
            $data['error'] = $rdapError . ' — WHOIS : ' . ($whois['error'] ?? 'échec');
        }
    }

    $entry = [
        'fetched_at' => $now,
        'ok' => $data['ok'] ?? false,
        'error' => $data['error'] ?? null,
        'source' => ($data['ok'] ?? false) ? $source : null,
        'whois_server' => $data['server'] ?? null,
        'expiration' => $data['expiration'] ?? null,
        'registered' => $data['registered'] ?? null,
        'nameservers' => $data['nameservers'] ?? [],
        'status' => $data['status'] ?? [],
        'registrar_rdap' => $data['registrar_rdap'] ?? null,
    ];

    $cache[$domain] = $entry;
    saveCache($cache);
    return $entry;
}

function getCachedExpiration(string $domain): ?array
{
    $cache = loadCache();
    return $cache[$domain] ?? null;
}

/**
 * Rafraîchit plusieurs domaines à la suite, avec une petite pause entre
 * chaque requête RDAP pour rester sous la limite de rdap.org (10 requêtes
 * par 10 secondes côté Cloudflare). Sans cette pause, les derniers domaines
 * d'une longue liste se prennent une erreur 429 lors d'un "Tout rafraîchir".
 *
 * @param string[] $domains
 * @return array<string, array> résultats indexés par nom de domaine
 */
function refreshDomainsBatch(array $domains, bool $force = true, int $delayMicroseconds = 350000): array
{
    $results = [];
    $first = true;

    foreach ($domains as $domain) {
        if (!$first) {
            usleep($delayMicroseconds);
        }
        $first = false;

        $results[$domain] = refreshDomainCache($domain, $force);
    }

    return $results;
}
