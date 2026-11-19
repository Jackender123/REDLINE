<?php
// Vehicles have been merged into Customers. Keep this URL for old bookmarks/forms.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_vehicle') {
    require __DIR__ . '/../customers/list.php';
    exit;
}
header('Location: ../customers/list.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
exit;
