<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
session_start();
$_SESSION['id'] = 1;
$_SESSION['name'] = 'Test User';
$_SESSION['role'] = 'user';

ob_start();
include __DIR__ . '/user_dashboard.php';
$output = ob_get_clean();

if (strpos($output, 'Fatal error') !== false || strpos($output, 'Parse error') !== false) {
    echo "FAILED\n";
    echo substr($output, 0, 800);
    exit(1);
}

echo "OK\n";
echo (strpos($output, 'bootstrap') !== false ? "Bootstrap: yes\n" : "Bootstrap: no\n");
echo (strpos($output, 'dashboard-card') !== false ? "Cards: yes\n" : "Cards: no\n");
