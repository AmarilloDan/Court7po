<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_user_access();

$conn = db();
$user_id = current_user_id();
$usersFile = __DIR__ . '/uploads/users.json';

function load_users_file(string $path): array {
    $data = @file_get_contents($path);
    if ($data === false || trim($data) === '') {
        return [];
    }

    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function find_user_by_id(array $users, int $id): ?array {
    foreach ($users as $entry) {
        if (isset($entry['id']) && ((int)$entry['id']) === $id) {
            return $entry;
        }
    }
    return null;
}

$user = [];
if ($conn) {
    $stmt = $conn->prepare(
        'SELECT id, name, username, email, address, contact_number, profile_photo, created_at FROM users WHERE id = ? LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() ?: [] : [];
        $stmt->close();
    }
}

if (empty($user) && file_exists($usersFile)) {
    $fallbackUsers = load_users_file($usersFile);
    $fallbackUser = find_user_by_id($fallbackUsers, $user_id);
    if ($fallbackUser !== null) {
        $user = [
            'id' => $fallbackUser['id'] ?? $user_id,
            'name' => $fallbackUser['name'] ?? '',
            'username' => $fallbackUser['username'] ?? '',
            'email' => $fallbackUser['email'] ?? '',
            'address' => $fallbackUser['address'] ?? '',
            'contact_number' => $fallbackUser['contact_number'] ?? '',
            'profile_photo' => $fallbackUser['profile_photo'] ?? '',
            'created_at' => $fallbackUser['created_at'] ?? null,
        ];
    }
}

$display_name = $user['name'] ?? $user['username'] ?? current_user_name() ?: 'Court7 Member';
$display_username = $user['username'] ?? $_SESSION['user'] ?? 'guest';
$profile_photo = 'images/logoo.png';
if (!empty($user['profile_photo']) && file_exists($user['profile_photo'])) {
    $profile_photo = $user['profile_photo'];
}

$reservationColumns = [];
if ($conn) {
    $columnsResult = mysqli_query($conn, 'SHOW COLUMNS FROM reservations');
    if ($columnsResult) {
        $whileColumn = mysqli_fetch_assoc($columnsResult);
        while ($column = $whileColumn) {
            $reservationColumns[] = $column['Field'];
            $whileColumn = mysqli_fetch_assoc($columnsResult);
        }
    }
}

$dateColumn = in_array('reservation_date', $reservationColumns, true)
    ? 'reservation_date'
    : (in_array('date', $reservationColumns, true) ? 'date' : null);
$timeSlotColumn = in_array('time_slot', $reservationColumns, true) ? 'time_slot' : null;
$startTimeColumn = in_array('start_time', $reservationColumns, true) ? 'start_time' : null;
$endTimeColumn = in_array('end_time', $reservationColumns, true) ? 'end_time' : null;
$courtColumn = in_array('court_id', $reservationColumns, true) ? 'court_id' : (in_array('court', $reservationColumns, true) ? 'court' : null);
$statusColumn = in_array('status', $reservationColumns, true) ? 'status' : null;
$nameColumn = in_array('name', $reservationColumns, true) ? 'name' : null;
$userIdColumn = in_array('user_id', $reservationColumns, true) ? 'user_id' : null;
$customerNameColumn = in_array('customer_name', $reservationColumns, true) ? 'customer_name' : null;

$timeSlots = [
    '06:00 AM - 07:00 AM',
    '07:00 AM - 08:00 AM',
    '08:00 AM - 09:00 AM',
    '09:00 AM - 10:00 AM',
    '10:00 AM - 11:00 AM',
    '11:00 AM - 12:00 PM',
    '12:00 PM - 01:00 PM',
    '01:00 PM - 02:00 PM',
    '02:00 PM - 03:00 PM',
    '03:00 PM - 04:00 PM',
    '04:00 PM - 05:00 PM',
    '05:00 PM - 06:00 PM',
    '06:00 PM - 07:00 PM',
    '07:00 PM - 08:00 PM',
    '08:00 PM - 09:00 PM',
];

$courts = [];
if ($conn) {
    $courtStmt = $conn->prepare('SELECT id, name FROM courts ORDER BY name ASC');
    if ($courtStmt !== false) {
        $courtStmt->execute();
        $courtResult = $courtStmt->get_result();
        if ($courtResult) {
            while ($court = $courtResult->fetch_assoc()) {
                $courts[] = $court;
            }
        }
        $courtStmt->close();
    }
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book'])) {
    $selectedCourt = (int)($_POST['court_id'] ?? 0);
    $selectedDate = trim($_POST['reservation_date'] ?? '');
    $selectedTime = trim($_POST['time_slot'] ?? '');

    if ($selectedCourt <= 0 || $selectedDate === '' || $selectedTime === '') {
        $message = 'Please choose a court, date, and time slot.';
        $messageType = 'error';
    } elseif ($dateColumn === null || $courtColumn === null) {
        $message = 'Booking configuration is not available right now. Contact support.';
        $messageType = 'error';
    } else {
        $available = true;
        if ($conn) {
            $checkSql = "SELECT COUNT(*) AS total FROM reservations WHERE $dateColumn = ? AND $courtColumn = ?";
            if ($timeSlotColumn !== null) {
                $checkSql .= " AND $timeSlotColumn = ?";
            }
            $checkStmt = $conn->prepare($checkSql);
            if ($checkStmt !== false) {
                if ($timeSlotColumn !== null) {
                    $checkStmt->bind_param('sis', $selectedDate, $selectedCourt, $selectedTime);
                } else {
                    $checkStmt->bind_param('si', $selectedDate, $selectedCourt);
                }
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $already = $checkResult ? (int)($checkResult->fetch_assoc()['total'] ?? 0) : 0;
                $available = ($already === 0);
                $checkStmt->close();
            }
        }

        if (!$available) {
            $message = 'That court is already booked for the selected date and time.';
            $messageType = 'error';
        } else {
            $insertColumns = [];
            $insertValues = [];
            $insertTypes = '';

            $insertColumns[] = $dateColumn;
            $insertValues[] = $selectedDate;
            $insertTypes .= 's';

            if ($timeSlotColumn !== null) {
                $insertColumns[] = $timeSlotColumn;
                $insertValues[] = $selectedTime;
                $insertTypes .= 's';
            } elseif ($startTimeColumn !== null && $endTimeColumn !== null) {
                [$startValue, $endValue] = explode(' - ', $selectedTime) + ['', ''];
                $insertColumns[] = $startTimeColumn;
                $insertValues[] = $startValue;
                $insertTypes .= 's';
                $insertColumns[] = $endTimeColumn;
                $insertValues[] = $endValue;
                $insertTypes .= 's';
            }

            $insertColumns[] = $courtColumn;
            $insertValues[] = $selectedCourt;
            $insertTypes .= 'i';

            if ($userIdColumn !== null) {
                $insertColumns[] = $userIdColumn;
                $insertValues[] = $user_id;
                $insertTypes .= 'i';
            } elseif ($customerNameColumn !== null) {
                $insertColumns[] = $customerNameColumn;
                $insertValues[] = $display_name;
                $insertTypes .= 's';
            }

            if ($nameColumn !== null) {
                $insertColumns[] = $nameColumn;
                $insertValues[] = $display_name;
                $insertTypes .= 's';
            }
            if ($statusColumn !== null) {
                $insertColumns[] = $statusColumn;
                $insertValues[] = 'confirmed';
                $insertTypes .= 's';
            }

            $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
            $sql = 'INSERT INTO reservations (' . implode(', ', $insertColumns) . ') VALUES (' . $placeholders . ')';

            $insertStmt = $conn ? $conn->prepare($sql) : false;
            if ($insertStmt === false) {
                $message = 'Unable to create booking. Please try again later.';
                $messageType = 'error';
            } else {
                $insertStmt->bind_param($insertTypes, ...$insertValues);
                if ($insertStmt->execute()) {
                    $message = 'Your court booking has been added successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Unable to save your booking. Please try again.';
                    $messageType = 'error';
                }
                $insertStmt->close();
            }
        }
    }
}

$upcomingBookings = [];
if ($conn && $dateColumn !== null && $courtColumn !== null) {
    $userFilter = $userIdColumn !== null
        ? "r.$userIdColumn = $user_id"
        : "r.$customerNameColumn = '" . mysqli_real_escape_string($conn, $display_name) . "'";

    $joinCourt = "LEFT JOIN courts c ON r.$courtColumn = c.id";
    $selectCourt = "COALESCE(c.name, CONCAT('Court ', r.$courtColumn)) AS court_name";
    $statusField = $statusColumn !== null ? "r.$statusColumn AS status" : "'pending' AS status";
    $timeField = $timeSlotColumn !== null ? "r.$timeSlotColumn AS time_slot" : ($startTimeColumn !== null && $endTimeColumn !== null ? "CONCAT(r.$startTimeColumn, ' - ', r.$endTimeColumn) AS time_slot" : "'' AS time_slot");

    $upcomingSql = "SELECT r.$dateColumn AS reservation_date, $timeField, $selectCourt, $statusField FROM reservations r $joinCourt WHERE $userFilter AND r.$dateColumn >= CURDATE() ORDER BY r.$dateColumn ASC";
    $result = mysqli_query($conn, $upcomingSql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $upcomingBookings[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Court | Court7</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background: #f3f3f3;
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
        }
        .page-shell {
            max-width: 1320px;
            margin: 30px auto;
            padding: 0 15px;
        }
        
        /* =========================
           TOP NAVIGATION (UPDATED MATCHING IMAGE)
        ========================= */
        .top-navbar {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,.05);
            padding: 12px 24px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .navbar-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #3b82f6;
        }

        .brand-meta {
            display: flex;
            flex-direction: column;
        }

        .brand-meta h2 {
            color: #1e293b;
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .brand-meta span {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 2px;
        }

        .nav-menu {
            list-style: none;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            padding: 0;
        }

        .nav-menu li a {
            text-decoration: none;
            color: #334155;
            font-weight: 600;
            font-size: 0.95rem;
            padding: 10px 18px;
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .nav-menu li a:hover {
            background: #f1f5f9;
            color: #1e1e1e;
        }

        .nav-menu li a.active {
            background: #2563eb;
            color: #fff;
        }

        .logout {
            color: #ef4444 !important;
        }

        /* =========================
           CONTENT STRUCTURE
        ========================= */
        .page-card {
            max-width: 100%;
            margin: 0 auto;
        }
        .panel-header {
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        .page-title {
            margin-top: 0;
            margin-bottom: 8px;
            color: #0c63e4;
            font-size: 2rem;
            font-weight: 800;
        }
        .page-subtitle {
            color: #475569;
            margin-bottom: 0;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 24px;
        }
        .card-panel {
            background: #ffffff;
            border-radius: 24px;
            padding: 26px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
        }
        .panel-title {
            margin: 0 0 18px 0;
            font-size: 1.4rem;
            color: #0c63e4;
            font-weight: 700;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 8px; color: #334155; font-weight: 700; }
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid #cbd5e1;
            background: #f8fbff;
            color: #0f172a;
            font-size: 1rem;
        }
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #0c63e4;
            box-shadow: 0 0 0 3px rgba(12, 99, 228, 0.12);
        }
        .alert { margin-bottom: 22px; padding: 18px 20px; border-radius: 16px; font-weight: 700; }
        .alert.success { background: #ecfdf5; color: #115d42; }
        .alert.error { background: #f8d7da; color: #8a1f2a; }
        .button-row { display: flex; gap: 14px; flex-wrap: wrap; }
        .button-primary {
            background: linear-gradient(90deg, #0c63e4 0%, #0c63e4 100%);
            color: #fff;
            border: none;
            padding: 14px 22px;
            border-radius: 14px;
            cursor: pointer;
            font-weight: 700;
            min-width: 170px;
        }
        .booking-summary {
            display: grid;
            gap: 14px;
        }
        .booking-summary .detail {
            background: #f8fafc;
            border-radius: 18px;
            padding: 18px 20px;
            border: 1px solid #e2e8f0;
        }
        .detail-label { color: #64748b; font-size: 0.95rem; margin-bottom: 6px; display: block; }
        .detail-value { color: #0f172a; font-size: 1.05rem; font-weight: 700; }
        .reservation-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .reservation-table th,
        .reservation-table td {
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            text-align: left;
            font-size: 0.95rem;
        }
        .reservation-table th { background: #eef2ff; color: #0c63e4; }
        .reservation-table tbody tr:hover { background: #f8fafc; }
        .small-note { color: #64748b; margin-top: 8px; line-height: 1.6; }
        .link-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .link-row a { text-decoration: none; color: #0c63e4; font-weight: 700; }
        @media (max-width: 980px) {
            .content-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="page-shell">

        <!-- TOP NAVIGATION -->
        <nav class="top-navbar">
            <div class="navbar-brand-section">
                <img src="<?php echo htmlspecialchars($profile_photo); ?>" alt="Profile" class="navbar-avatar">
                <div class="brand-meta">
                    <h2>Court 7</h2>
                    <span>Signed in as <?php echo htmlspecialchars($display_username); ?></span>
                </div>
            </div>

            <ul class="nav-menu">
                <li><a href="user_dashboard.php">Dashboard</a></li>
                <li><a href="profile.php">My Profile</a></li>
                <li><a href="booking_calendar.php" class="active">Book a Court</a></li>
                <li><a href="booking_history.php">Booking History</a></li>
                <li><a href="logout.php" class="logout">Logout</a></li>
            </ul>
        </nav>

        <div class="page-card">
            <div class="panel-header">
                <div>
                    <h1 class="page-title">Book a Court</h1>
                    <p class="page-subtitle">Reserve your preferred court time from the user dashboard. Your booking will appear in upcoming reservations.</p>
                </div>
            </div>

            <?php if ($message !== ''): ?>
                <div class="alert <?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="content-grid">
                <section class="card-panel">
                    <h2 class="panel-title">Reserve a Court</h2>
                    <form method="POST" action="booking_calendar.php">
                        <div class="form-group">
                            <label for="court_id">Select Court</label>
                            <select id="court_id" name="court_id" required>
                                <option value="">Choose a court</option>
                                <option value="1">Court 1</option>
                                <option value="2">Court 2</option>
                                <option value="3">Court 3</option>
                                <option value="4">Court 4</option>

                            
                                <?php foreach ($courts as $court): ?>
                                    <option value="<?php echo htmlspecialchars($court['id']); ?>"><?php echo htmlspecialchars($court['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reservation_date">Date</label>
                            <input type="date" id="reservation_date" name="reservation_date" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="time_slot">Time Slot</label>
                            <select id="time_slot" name="time_slot" required>
                                <option value="">Choose a time slot</option>
                                <?php foreach ($timeSlots as $slot): ?>
                                    <option value="<?php echo htmlspecialchars($slot); ?>"><?php echo htmlspecialchars($slot); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="button-row">
                            <button type="submit" class="button-primary" name="book">Confirm booking</button>
                        </div>
                    </form>
                    <p class="small-note">If you do not see your preferred court, please contact support or choose another available slot.</p>
                </section>

                <aside class="card-panel booking-summary">
                    <div class="detail">
                        <span class="detail-label">Logged in as</span>
                        <span class="detail-value"><?php echo htmlspecialchars($display_name); ?> (<?php echo htmlspecialchars($display_username); ?>)</span>
                    </div>
                    <div class="detail">
                        <span class="detail-label">Contact</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user['contact_number'] ?? 'Not provided'); ?></span>
                    </div>
                    <div class="detail">
                        <span class="detail-label">Upcoming Reservations</span>
                        <?php if (count($upcomingBookings) === 0): ?>
                            <p style="margin:0; color:#64748b;">No upcoming reservations yet.</p>
                        <?php else: ?>
                            <table class="reservation-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Court</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcomingBookings as $booking): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($booking['reservation_date']))); ?></td>
                                            <td><?php echo htmlspecialchars($booking['time_slot']); ?></td>
                                            <td><?php echo htmlspecialchars($booking['court_name']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div class="detail">
                        <span class="detail-label">Quick links</span>
                        <div class="link-row">
                            <a href="user_dashboard.php">Dashboard</a>
                            <a href="profile.php">My Profile</a>
                            <a href="booking_history.php">History</a>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</body>
</html>