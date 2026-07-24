<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_user_access();
$conn = db();

$user_id = current_user_id();
$usersFile = __DIR__ . '/uploads/users.json';

function load_users_file($path) {
    $data = @file_get_contents($path);
    if ($data === false || trim($data) === '') {
        return [];
    }

    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function find_user_by_id($users, $id) {
    foreach ($users as $userEntry) {
        if (($userEntry['id'] ?? null) == $id) {
            return $userEntry;
        }
    }

    return null;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['amount'])) {

    $reservation_id = (int) $_POST['reservation_id'];
    $amount = (float) $_POST['amount'];
    $method = mysqli_real_escape_string($conn, $_POST['method']);
    $reference = mysqli_real_escape_string($conn, $_POST['reference'] ?? '');

    $proofPath = '';

    if (!empty($_FILES['proof']['name'])) {
        $uploadDir = "uploads/payments/";

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = time() . "_" . basename($_FILES['proof']['name']);
        $target = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['proof']['tmp_name'], $target)) {
            $proofPath = $target;
        }
    }

    mysqli_query($conn, "
        INSERT INTO payments (reservation_id, user_id, amount, method, reference, proof, status)
        VALUES ($reservation_id, $user_id, $amount, '$method', '$reference', '$proofPath', 'pending')
    ");
}


$user = current_user();

$profile_photo = !empty($user['profile_photo']) && file_exists($user['profile_photo'])
    ? $user['profile_photo']
    : 'images/logoo.png';

$reservationColumns = [];
$columnsResult = mysqli_query($conn, 'SHOW COLUMNS FROM reservations');
if ($columnsResult) {
    while ($column = mysqli_fetch_assoc($columnsResult)) {
        $reservationColumns[] = $column['Field'];
    }
}

$courtColumns = [];
$courtColumnsResult = mysqli_query($conn, 'SHOW COLUMNS FROM courts');
if ($courtColumnsResult) {
    while ($column = mysqli_fetch_assoc($courtColumnsResult)) {
        $courtColumns[] = $column['Field'];
    }
}

$idColumn = in_array('id', $reservationColumns, true) ? 'id' : 'reservation_id';
$dateColumn = in_array('reservation_date', $reservationColumns, true) ? 'reservation_date' : 'date';
$timeSelect = in_array('time_slot', $reservationColumns, true)
    ? 'r.time_slot'
    : "CONCAT(r.start_time, '-', r.end_time) AS time_slot";
$timeOrder = in_array('time_slot', $reservationColumns, true)
    ? 'r.time_slot'
    : 'r.start_time';
$statusSelect = in_array('status', $reservationColumns, true)
    ? 'r.status'
    : "COALESCE(r.payment_status, 'pending') AS status";
$reservationCourtColumn = in_array('court', $reservationColumns, true) ? 'court' : 'court_id';
$courtIdColumn = in_array('id', $courtColumns, true) ? 'id' : 'court_id';
$courtNameColumn = in_array('name', $courtColumns, true) ? 'name' : 'court_name';

$userFilter = '';
if (in_array('user_id', $reservationColumns, true)) {
    $userFilter = "r.user_id = $user_id";
} elseif ($user_id > 0 && in_array('customer_name', $reservationColumns, true)) {
    $userName = mysqli_real_escape_string($conn, $user['name'] ?? current_user_name());
    if ($userName !== '') {
        $userFilter = "r.customer_name = '$userName'";
    }
}

$total = 0;
if ($userFilter !== '') {
    $total_q = mysqli_query($conn, "
        SELECT COUNT(*) AS total
        FROM reservations r
        WHERE $userFilter
    ");
    $total = $total_q ? (int)(mysqli_fetch_assoc($total_q)['total'] ?? 0) : 0;
}

$upcoming_rows = [];
$next_booking = null;
$upcoming_count = 0;

if ($userFilter !== '') {
    $paymentsJoin = '';
    $paymentStatusSelect = "'unpaid' AS payment_status";
    $paymentsCheck = mysqli_query($conn, "SHOW TABLES LIKE 'payments'");
    if ($paymentsCheck && mysqli_num_rows($paymentsCheck) > 0) {
        $paymentsJoin = "
            LEFT JOIN (
                SELECT reservation_id, status
                FROM payments
                ORDER BY id DESC
            ) p ON p.reservation_id = r.$idColumn";
        $paymentStatusSelect = "COALESCE(p.status, 'unpaid') AS payment_status";
    }

    $upcomingResult = mysqli_query($conn, "
        SELECT
            r.$idColumn AS id,
            r.$dateColumn AS reservation_date,
            $timeSelect,
            $statusSelect,
            c.$courtNameColumn AS court_name,
            $paymentStatusSelect
        FROM reservations r
        LEFT JOIN courts c ON r.$reservationCourtColumn = c.$courtIdColumn
        $paymentsJoin
        WHERE $userFilter
        AND r.$dateColumn >= CURDATE()
        ORDER BY r.$dateColumn ASC, $timeOrder ASC
        LIMIT 5
    ");
    $upcoming_rows = $upcomingResult ? mysqli_fetch_all($upcomingResult, MYSQLI_ASSOC) : [];
    $upcoming_count = count($upcoming_rows);
    $next_booking = $upcoming_rows[0] ?? null;
}

$recent_rows = [];
if ($userFilter !== '') {
    $recentResult = mysqli_query($conn, "
        SELECT
            r.$dateColumn AS reservation_date,
            $timeSelect,
            $statusSelect,
            c.$courtNameColumn AS court_name
        FROM reservations r
        LEFT JOIN courts c ON r.$reservationCourtColumn = c.$courtIdColumn
        WHERE $userFilter
        ORDER BY r.$dateColumn DESC, $timeOrder DESC
        LIMIT 5
    ");
    $recent_rows = $recentResult ? mysqli_fetch_all($recentResult, MYSQLI_ASSOC) : [];
}

$courtsResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM courts");
$court_count = $courtsResult ? (int)(mysqli_fetch_assoc($courtsResult)['total'] ?? 0) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body {
    background: #f3f3f3;
}

.dashboard-card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.08);
}

.card-header {
    font-weight: bold;
    background: white;
    border-bottom: 1px solid #eee;
}

.table thead {
    background: #4F46E5;
    color: white;
}

.value {
    font-size: 30px;
    font-weight: bold;
    color: #4F46E5;
}

.top-bar {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.08);
}

.profile-thumb {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #4F46E5;
}

.nav-link.active {
    background: #4F46E5;
    color: #fff !important;
    border-radius: 8px;
}

.nav-link {
    color: #333;
    font-weight: 500;
    border-radius: 8px;
}

.nav-link:hover {
    background: #eef2ff;
    color: #4F46E5;
}

.welcome-text {
    color: #6b7280;
}

.status-badge {
    font-size: 12px;
    padding: 6px 12px;
    border-radius: 20px;
}
</style>
</head>

<body>

<div class="container py-4">

<div class="top-bar p-3 mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <img src="<?= htmlspecialchars($profile_photo); ?>" alt="Profile" class="profile-thumb">
            <div>
                <h5 class="mb-0">Court 7</h5>
                <small class="welcome-text">Signed in as <?= htmlspecialchars($user['name'] ?? 'User'); ?></small>
            </div>
        </div>
        <ul class="nav nav-pills gap-1 flex-wrap">
            <li class="nav-item"><a class="nav-link active" href="user_dashboard.php">Dashboard</a></li>
            <li class="nav-item"><a class="nav-link" href="profile.php">My Profile</a></li>
            <li class="nav-item"><a class="nav-link" href="booking_calendar.php">Book a Court</a></li>
            <li class="nav-item"><a class="nav-link" href="booking_history.php">Booking History</a></li>
            <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
        </ul>
    </div>
</div>

<h3 class="mb-1">User Dashboard</h3>
<p class="welcome-text mb-4">Here is a quick overview of your court bookings and account activity.</p>

<?php if ($next_booking): ?>
<div class="alert alert-primary alert-dismissible fade show dashboard-card" role="alert">
    <strong>Upcoming booking:</strong>
    <?= htmlspecialchars($next_booking['court_name'] ?? '-'); ?> on
    <?= date('F j, Y', strtotime($next_booking['reservation_date'])); ?> at
    <?= htmlspecialchars($next_booking['time_slot'] ?? '-'); ?>.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card dashboard-card h-100">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Bookings</h6>
                <div class="value"><?= (int)$total; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card dashboard-card h-100">
            <div class="card-body text-center">
                <h6 class="text-muted">Upcoming</h6>
                <div class="value"><?= count($upcoming_rows); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card dashboard-card h-100">
            <div class="card-body text-center">
                <h6 class="text-muted">Courts Available</h6>
                <div class="value"><?= (int)$court_count; ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card dashboard-card mb-4">
    <div class="card-header">Upcoming Bookings</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Court</th>
                        <th>Status</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($upcoming_rows)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No upcoming bookings yet.</td>
                    </tr>
                    <?php else: foreach ($upcoming_rows as $row):
                        $status = strtolower((string)($row['status'] ?? 'pending'));
                        $statusClass = $status === 'completed' || $status === 'paid' ? 'bg-success' : ($status === 'reserved' ? 'bg-info' : 'bg-warning text-dark');
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['reservation_date'] ?? '-'); ?></td>
                        <td><?= htmlspecialchars($row['time_slot'] ?? '-'); ?></td>
                        <td><?= htmlspecialchars($row['court_name'] ?? '-'); ?></td>
                        <td><span class="badge status-badge <?= $statusClass; ?>"><?= ucfirst($row['status'] ?? 'Pending'); ?></span></td>
                        <td>
                            <?php if (($row['payment_status'] ?? '') === 'pending'): ?>
                                <span class="badge status-badge bg-warning text-dark">Pending</span>
                            <?php elseif (($row['payment_status'] ?? '') === 'approved'): ?>
                                <span class="badge status-badge bg-success">Paid</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-primary payBtn" data-id="<?= (int)$row['id']; ?>">Pay</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card dashboard-card mb-4">
    <div class="card-header">Recent Activity</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Court</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_rows)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No recent activity yet.</td>
                    </tr>
                    <?php else: foreach ($recent_rows as $row):
                        $status = strtolower((string)($row['status'] ?? 'pending'));
                        $statusClass = $status === 'completed' || $status === 'paid' ? 'bg-success' : ($status === 'reserved' ? 'bg-info' : 'bg-warning text-dark');
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['reservation_date'] ?? '-'); ?></td>
                        <td><?= htmlspecialchars($row['time_slot'] ?? '-'); ?></td>
                        <td><span class="badge status-badge <?= $statusClass; ?>"><?= ucfirst($row['status'] ?? 'Pending'); ?></span></td>
                        <td><?= htmlspecialchars($row['court_name'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content dashboard-card">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentModalLabel">Submit Downpayment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="reservation_id" id="res_id">
                    <div class="mb-3">
                        <label for="amount" class="form-label">Amount</label>
                        <input type="number" class="form-control" id="amount" name="amount" placeholder="Enter amount" required>
                    </div>
                    <div class="mb-3">
                        <label for="method" class="form-label">Payment Method</label>
                        <select class="form-select" id="method" name="method">
                            <option value="GCash">GCash</option>
                            <option value="Cash">Cash</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="reference" class="form-label">Reference Number</label>
                        <input type="text" class="form-control" id="reference" name="reference" placeholder="Optional reference">
                    </div>
                    <div class="mb-3">
                        <label for="proof" class="form-label">Proof of Payment</label>
                        <input type="file" class="form-control" id="proof" name="proof">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
const resInput = document.getElementById('res_id');

document.querySelectorAll('.payBtn').forEach(btn => {
    btn.addEventListener('click', () => {
        resInput.value = btn.dataset.id;
        paymentModal.show();
    });
});
</script>

</body>
</html>