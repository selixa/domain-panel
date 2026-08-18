<?php
require __DIR__ . '/includes/check-auth.php';
require __DIR__ . '/includes/domains.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

csrf_check();

$id = $_POST['id'] ?? '';
if ($id !== '') {
    deleteDomain($id);
}

header('Location: index.php?deleted=1');
exit;
