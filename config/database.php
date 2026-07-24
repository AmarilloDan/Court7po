<?php

function db(): mysqli {
    global $conn;

    if (!isset($conn) || $conn === null) {
        require_once __DIR__ . '/../db_connect.php';
    }

    if (!$conn) {
        die('Database connection failed');
    }

    return $conn;
}
