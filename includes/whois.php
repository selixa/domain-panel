<?php

// Serveurs WHOIS connus pour des TLD qui n'ont pas de RDAP enregistré
// auprès de l'IANA (vérifié sur https://deployment.rdap.org/, ex: .io et
// .bz sont marqués "RDAP: -"). Best-effort : le format des réponses WHOIS
// varie énormément d'un registre à l'autre, donc l'extraction de la date
// d'expiration reste heuristique. Complète cette liste si tu ajoutes des
// domaines dans d'autres TLD "exotiques".
const WHOIS_SERVERS = [
    'io' => ['whois.nic.io'],
    'bz' => ['whois.afilias-grs.info', 'whois.belizenic.bz'],
    'co' => ['whois.nic.co'],
    'me' => ['whois.nic.me'],
    'ws' => ['whois.website.ws'],
    'sh' => ['whois.nic.sh'],
    'ac' => ['whois.nic.ac'],
    'to' => ['whois.tonic.to'],
];

function whoisTld(string $domain): string
{
    $parts = explode('.', $domain);
    return strtolower((string) end($parts));
}

/**
 * Demande à l'IANA quel serveur WHOIS fait référence pour un TLD donné.
 * Ne fonctionne que si l'IANA a une entrée "refer:" pour ce TLD.
 */
function ianaReferralServer(string $tld): ?string
{
    $response = rawWhoisQuery('whois.iana.org', $tld, 43, 4);
    if ($response === null) {
        return null;
    }
    if (preg_match('/^refer:\s*(\S+)/mi', $response, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Requête WHOIS brute (port 43). Retourne null si la connexion échoue
 * (port bloqué par l'hébergeur, serveur injoignable, timeout...).
 */
function rawWhoisQuery(string $server, string $query, int $port = 43, int $timeout = 5): ?string
{
    $fp = @fsockopen($server, $port, $errno, $errstr, $timeout);
    if ($fp === false) {
        return null;
    }

    stream_set_timeout($fp, $timeout);
    fwrite($fp, $query . "\r\n");

    $response = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 4096);
        if ($chunk === false) {
            break;
        }
        $response .= $chunk;
        $meta = stream_get_meta_data($fp);
        if ($meta['timed_out']) {
            break;
        }
    }
    fclose($fp);

    return $response !== '' ? $response : null;
}

/**
 * Cherche une date d'expiration dans un texte WHOIS brut. Les registres
 * n'ont pas de format standard, donc on essaie plusieurs libellés connus,
 * dans l'ordre du plus courant au plus rare.
 */
function parseWhoisExpiration(string $text): ?string
{
    $patterns = [
        '/Registry Expiry Date:\s*(.+)/i',
        '/Registrar Registration Expiration Date:\s*(.+)/i',
        '/Expiration (?:Date|Time):\s*(.+)/i',
        '/Expiry Date:\s*(.+)/i',
        '/Expiry:\s*(.+)/i',
        '/paid-till:\s*(.+)/i',
        '/renewal date:\s*(.+)/i',
        '/free-date:\s*(.+)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $raw = trim($m[1]);
            $ts = strtotime($raw);
            if ($ts !== false) {
                return date('Y-m-d\TH:i:s\Z', $ts);
            }
        }
    }

    return null;
}

function parseWhoisNameservers(string $text): array
{
    $nameservers = [];
    if (preg_match_all('/^(?:Name Server|nserver|Nserver):\s*(\S+)/mi', $text, $matches)) {
        foreach ($matches[1] as $ns) {
            $nameservers[] = strtolower(rtrim($ns, '.'));
        }
    }
    return array_values(array_unique($nameservers));
}

/**
 * Tentative de secours quand RDAP échoue : WHOIS classique (port 43).
 * Essaie d'abord la liste de serveurs connus pour le TLD (WHOIS_SERVERS),
 * puis le référencement officiel IANA si aucun n'est configuré ou si les
 * serveurs connus ne répondent pas.
 */
function fetchWhoisFallback(string $domain): array
{
    $tld = whoisTld($domain);
    $candidates = WHOIS_SERVERS[$tld] ?? [];

    $ianaServer = ianaReferralServer($tld);
    if ($ianaServer && !in_array($ianaServer, $candidates, true)) {
        $candidates[] = $ianaServer;
    }

    if (empty($candidates)) {
        return [
            'ok' => false,
            'error' => "Aucun serveur WHOIS connu pour .$tld (ni dans la liste locale, ni chez l'IANA)",
        ];
    }

    $lastError = null;
    foreach ($candidates as $server) {
        $response = rawWhoisQuery($server, $domain);
        if ($response === null) {
            $lastError = "Connexion à $server impossible (port 43 bloqué par l'hébergeur ?)";
            continue;
        }
        if (preg_match('/no match|not found|no data found|no entries found|status:\s*free/i', $response)) {
            $lastError = "$server : domaine inconnu de ce registre";
            continue;
        }

        $expiration = parseWhoisExpiration($response);
        if ($expiration === null) {
            $lastError = "$server a répondu mais la date d'expiration n'a pas été reconnue dans le format retourné";
            continue;
        }

        return [
            'ok' => true,
            'expiration' => $expiration,
            'nameservers' => parseWhoisNameservers($response),
            'server' => $server,
        ];
    }

    return [
        'ok' => false,
        'error' => $lastError ?? 'Échec WHOIS',
    ];
}
