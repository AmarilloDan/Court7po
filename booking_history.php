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
    $stmt = $conn->prepare('SELECT id, name, username, email, contact_number, profile_photo, created_at FROM users WHERE id = ? LIMIT 1');
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
        while ($column = mysqli_fetch_assoc($columnsResult)) {
            $reservationColumns[] = $column['Field'];
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
$userIdColumn = in_array('user_id', $reservationColumns, true) ? 'user_id' : null;
$customerNameColumn = in_array('customer_name', $reservationColumns, true) ? 'customer_name' : null;
$statusColumn = in_array('status', $reservationColumns, true) ? 'status' : null;

$courtNames = [];
if ($conn) {
    $courtStmt = $conn->prepare('SELECT id, name FROM courts ORDER BY name ASC');
    if ($courtStmt !== false) {
        $courtStmt->execute();
        $courtResult = $courtStmt->get_result();
        if ($courtResult) {
            while ($court = $courtResult->fetch_assoc()) {
                $courtNames[$court['id']] = $court['name'];
            }
        }
        $courtStmt->close();
    }
}

// Get and clean search parameter
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

$bookings = [];
if ($conn && $dateColumn !== null && $courtColumn !== null) {
    $filter = $userIdColumn !== null
        ? "r.$userIdColumn = $user_id"
        : ($customerNameColumn !== null ? "r.$customerNameColumn = '" . mysqli_real_escape_string($conn, $display_name) . "'" : '1=0');

    $timeSql = $timeSlotColumn !== null
        ? "r.$timeSlotColumn"
        : ($startTimeColumn !== null && $endTimeColumn !== null ? "CONCAT(r.$startTimeColumn, ' - ', r.$endTimeColumn)" : "''");

    $statusSql = $statusColumn !== null ? "r.$statusColumn AS status" : "'confirmed' AS status";

    // Base SQL string building
    $sql = "SELECT r.$dateColumn AS reservation_date, $timeSql AS time_slot, r.$courtColumn AS court_id, $statusSql, r.id AS reservation_id 
            FROM reservations r 
            LEFT JOIN courts c ON r.$courtColumn = c.id
            WHERE $filter";

    // If search term is present, narrow the records down
    if ($searchQuery !== '') {
        $escapedSearch = mysqli_real_escape_string($conn, $searchQuery);
        $sql .= " AND (r.$dateColumn LIKE '%$escapedSearch%' 
                    OR $timeSql LIKE '%$escapedSearch%' 
                    OR c.name LIKE '%$escapedSearch%'
                    OR r.$courtColumn LIKE '%$escapedSearch%')";
    }

    $sql .= " ORDER BY r.$dateColumn DESC";
    
    $result = mysqli_query($conn, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $bookings[] = $row;
        }
    }
}

$pastBookings = [];
$upcomingBookings = [];
$today = date('Y-m-d');
foreach ($bookings as $booking) {
    $bookingDate = $booking['reservation_date'] ?? $today;
    if ($bookingDate < $today) {
        $pastBookings[] = $booking;
    } else {
        $upcomingBookings[] = $booking;
    }
}

$totalBookings = count($bookings);
$totalUpcoming = count($upcomingBookings);
$totalPast = count($pastBookings);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking History | Court7</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background: #f3f3f3;
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
        }
        .history-shell {
            max-width: 1320px;
            margin: 30px auto;
            padding: 0 15px;
        }
        .history-card {
            max-width: 100%;
            margin: 0 auto;
        }

        /* =========================
           TOP NAVIGATION (MATCHING COMPONENT)
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
        .logout { color: #ef4444 !important; }

        /* =========================
           HEADER SECTION
        ========================= */
        .history-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .history-title { margin: 0; font-size: 2rem; color: #0c63e4; font-weight: 800; }
        .history-subtitle { margin: 6px 0 0; color: #475569; }

        /* =========================
           SEARCH COMPONENT STYLE
        ========================= */
        .search-container {
            display: flex;
            align-items: center;
            background: #fff;
            padding: 6px 10px;
            border-radius: 14px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            width: 100%;
            max-width: 1000px;
        }
        .search-container input {
            border: none;
            outline: none;
            padding: 10px 12px;
            font-size: 0.95rem;
            color: #334155;
            width: 100%;
            background: transparent;
        }
        .search-container button {
            background: #0c63e4;
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .search-container button:hover { background: #024dbc; }
        .clear-btn {
            color: #64748b;
            text-decoration: none;
            font-size: 0.88rem;
            margin-right: 12px;
            font-weight: 600;
        }
        .clear-btn:hover { color: #ef4444; }

        /* =========================
           STATS & PLACEMENT
        ========================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: #fff;
            border-radius: 18px;
            padding: 22px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 28px rgba(12, 99, 228, 0.05);
            text-align: center;
        }
        .stat-value { font-size: 2rem; font-weight: 800; color: #0c63e4; margin-bottom: 8px; }
        .stat-label { color: #64748b; font-size: 0.96rem; }
        
        .history-section {
            background: #fff;
            border-radius: 24px;
            padding: 26px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
            margin-bottom: 24px;
        }
        .section-title { font-size: 1.25rem; color: #0c63e4; margin-bottom: 18px; font-weight: 700; }
        .reservation-table { width: 100%; border-collapse: collapse; }
        .reservation-table th,
        .reservation-table td { padding: 14px 16px; border: 1px solid #e2e8f0; text-align: left; color: #334155; }
        .reservation-table th { background: #eef2ff; color: #0c63e4; font-weight: 700; }
        .reservation-table tbody tr:hover { background: #f8fafc; }
        
        .booking-status { padding: 6px 12px; border-radius: 999px; display: inline-block; font-size: 0.85rem; font-weight: 700; }
        .status-confirmed { background: #d1fae5; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }

        .summary-panel { display: grid; gap: 14px; margin-top: 22px; }
        .summary-block { background: #f8fafc; border-radius: 18px; padding: 18px 20px; border: 1px solid #e2e8f0; }
        .summary-label { color: #64748b; display: block; margin-bottom: 6px; }
        .corner-link { text-decoration: none; color: #0c63e4; font-weight: 700; }

        @media (max-width: 960px) { .stats-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 680px) { .stats-grid { grid-template-columns: 1fr; } .history-header { flex-direction: column; align-items: stretch; } .search-container { max-width: 100%; } }
    </style>
</head>
<body>
    <div class="history-shell">
        <div class="history-card">

            <!-- TOP NAVIGATION MODULE -->
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
                    <li><a href="booking_calendar.php">Book a Court</a></li>
                    <li><a href="booking_history.php" class="active">Booking History</a></li>
                    <li><a href="logout.php" class="logout">Logout</a></li>
                </ul>
            </nav>

            <!-- MAIN HEADER AND SEARCH INPUT BAR -->
            <div class="history-header">
                <div>
                    <h1 class="history-title">Booking History</h1>
                    <p class="history-subtitle">Review your court reservations, including upcoming slots and past bookings.</p>
                </div>
                
                <form method="GET" action="booking_history.php" class="search-container">
                    <input type="text" name="search" placeholder="Search date, time, or court..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    <?php if ($searchQuery !== ''): ?>
                        <a href="booking_history.php" class="clear-btn">Clear</a>
                    <?php endif; ?>
                    <button type="submit">Search</button>
                </form>
            </div>

            <!-- ANALYTICS STATS CARDS -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo htmlspecialchars((string)$totalBookings); ?></div>
                    <div class="stat-label">Total Results</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo htmlspecialchars((string)$totalUpcoming); ?></div>
                    <div class="stat-label">Upcoming Matches</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo htmlspecialchars((string)$totalPast); ?></div>
                    <div class="stat-label">Past Matches</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo htmlspecialchars($display_name); ?></div>
                    <div class="stat-label">User</div>
                </div>
            </div>

            <!-- SEARCH ANCHOR: UPCOMING RESERVATIONS -->
            <div class="history-section">
                <div class="section-title">Upcoming Reservations</div>
                <?php if (empty($upcomingBookings)): ?>
                    <p style="color:#64748b; margin:0;">No upcoming reservations found.</p>
                <?php else: ?>
                    <table class="reservation-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Court</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingBookings as $booking): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($booking['reservation_date']))); ?></td>
                                    <td><?php echo htmlspecialchars($booking['time_slot']); ?></td>
                                    <td><?php echo htmlspecialchars($courtNames[$booking['court_id']] ?? 'Court ' . htmlspecialchars((string)$booking['court_id'])); ?></td>
                                    <td>
                                        <?php $status = strtolower(trim($booking['status'] ?? 'confirmed')); ?>
                                        <span class="booking-status status-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- SEARCH ANCHOR: PAST RESERVATIONS -->
            <div class="history-section">
                <div class="section-title">Past Reservations</div>
                <?php if (empty($pastBookings)): ?>
                    <p style="color:#64748b; margin:0;">No past bookings found.</p>
                <?php else: ?>
                    <table class="reservation-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Court</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pastBookings as $booking): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($booking['reservation_date']))); ?></td>
                                    <td><?php echo htmlspecialchars($booking['time_slot']); ?></td>
                                    <td><?php echo htmlspecialchars($courtNames[$booking['court_id']] ?? 'Court ' . htmlspecialchars((string)$booking['court_id'])); ?></td>
                                    <td>
                                        <?php $status = strtolower(trim($booking['status'] ?? 'confirmed')); ?>
                                        <span class="booking-status status-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="summary-panel">
                <div class="summary-block">
                    <span class="summary-label">Need a new reservation?</span>
                    <a href="booking_calendar.php" class="corner-link">Go to Booking Calendar</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>