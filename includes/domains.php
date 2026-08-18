<?php

define('DOMAINS_FILE', __DIR__ . '/../data/domains.json');

function loadDomains(): array
{
    if (!file_exists(DOMAINS_FILE)) {
        return [];
    }
    $data = json_decode(file_get_contents(DOMAINS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveDomains(array $domains): void
{
    $fp = fopen(DOMAINS_FILE, 'c+');
    if ($fp === false) {
        return;
    }
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode(array_values($domains), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function addDomain(array $newDomain): array
{
    $domains = loadDomains();
    $newDomain['id'] = bin2hex(random_bytes(4));
    $newDomain['added_at'] = time();
    $domains[] = $newDomain;
    saveDomains($domains);
    return $newDomain;
}

function updateDomain(string $id, array $fields): bool
{
    $domains = loadDomains();
    $found = false;
    foreach ($domains as &$d) {
        if ($d['id'] === $id) {
            foreach ($fields as $key => $value) {
                $d[$key] = $value;
            }
            $found = true;
            break;
        }
    }
    unset($d);
    if ($found) {
        saveDomains($domains);
    }
    return $found;
}

function deleteDomain(string $id): void
{
    $domains = array_values(array_filter(loadDomains(), fn($d) => $d['id'] !== $id));
    saveDomains($domains);
}

function findDomain(string $id): ?array
{
    foreach (loadDomains() as $d) {
        if ($d['id'] === $id) {
            return $d;
        }
    }
    return null;
}
