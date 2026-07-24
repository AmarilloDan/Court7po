<?php
require 'db_connect.php';
if (!$conn) { echo "NO_CONN"; exit; }
$res = mysqli_query($conn, 'SHOW COLUMNS FROM reservations');
if (!$res) { echo mysqli_error($conn); exit; }
while ($row = mysqli_fetch_assoc($res)) { echo $row['Field'] . PHP_EOL; }
