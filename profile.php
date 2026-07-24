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

$message = '';
$messageType = '';

$user = [];
if ($conn) {
    $stmt = $conn->prepare(
        'SELECT id, name, username, email, address, contact_number, profile_photo, created_at FROM users WHERE id = ? LIMIT 1'
    );

    if ($stmt !== false) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $user = $result->fetch_assoc() ?: [];
        }
        $stmt->close();
    }
}

if (empty($user) && file_exists($usersFile)) {
    $users = load_users_file($usersFile);
    $fallback = find_user_by_id($users, $user_id);
    if ($fallback !== null) {
        $user = [
            'id' => $fallback['id'] ?? $user_id,
            'name' => $fallback['name'] ?? '',
            'username' => $fallback['username'] ?? '',
            'email' => $fallback['email'] ?? '',
            'address' => $fallback['address'] ?? '',
            'contact_number' => $fallback['contact_number'] ?? '',
            'profile_photo' => $fallback['profile_photo'] ?? '',
            'created_at' => $fallback['created_at'] ?? null,
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_profile'])) {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');

        if ($name === '' || $username === '' || $email === '') {
            $message = 'Please fill in your name, username, and email.';
            $messageType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'error';
        } else {
            $duplicateStmt = $conn->prepare('SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ? LIMIT 1');
            if ($duplicateStmt !== false) {
                $duplicateStmt->bind_param('ssi', $email, $username, $user_id);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                $duplicateRow = $duplicateResult ? $duplicateResult->fetch_assoc() : null;
                $duplicateStmt->close();

                if ($duplicateRow) {
                    $message = 'That email or username is already in use.';
                    $messageType = 'error';
                } else {
                    $newPhotoPath = $user['profile_photo'] ?? '';
                    if (!empty($_FILES['profile_photo']['name'])) {
                        $uploadDir = __DIR__ . '/uploads/profile_photos/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }

                        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['profile_photo']['name']));
                        $targetPath = $uploadDir . $filename;

                        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
                            $newPhotoPath = 'uploads/profile_photos/' . $filename;
                        }
                    }

                    $updateStmt = $conn->prepare('UPDATE users SET name = ?, username = ?, email = ?, address = ?, contact_number = ?, profile_photo = ? WHERE id = ?');
                    $updateStmt->bind_param('ssssssi', $name, $username, $email, $address, $contact_number, $newPhotoPath, $user_id);

                    if ($updateStmt && $updateStmt->execute()) {
                        $_SESSION['name'] = $name;
                        $_SESSION['user'] = $username;
                        $message = 'Your profile was updated successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Unable to save your profile right now.';
                        $messageType = 'error';
                    }
                }
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $password = trim($_POST['password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if ($password === '') {
            $message = 'Password cannot be blank.';
            $messageType = 'error';
        } elseif ($password !== $confirm_password) {
            $message = 'Passwords do not match.';
            $messageType = 'error';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
            $updateStmt->bind_param('si', $hashedPassword, $user_id);
            if ($updateStmt && $updateStmt->execute()) {
                $message = 'Password updated successfully.';
                $messageType = 'success';
            } else {
                $message = 'Unable to update password right now.';
                $messageType = 'error';
            }
        }
    }

    // Reload user profile elements
    $reloadStmt = $conn->prepare('SELECT id, name, username, email, address, contact_number, profile_photo, created_at FROM users WHERE id = ? LIMIT 1');
    if ($reloadStmt !== false) {
        $reloadStmt->bind_param('i', $user_id);
        $reloadStmt->execute();
        $reloadResult = $reloadStmt->get_result();
        if ($reloadResult) {
            $user = $reloadResult->fetch_assoc() ?: $user;
        }
        $reloadStmt->close();
    }
}

$profile_photo = 'images/logoo.png';
if (!empty($user['profile_photo']) && file_exists($user['profile_photo'])) {
    $profile_photo = $user['profile_photo'];
}

$display_name = $user['name'] ?? $user['username'] ?? current_user_name() ?: 'Court7 Member';
$display_username = $user['username'] ?? $_SESSION['user'] ?? 'guest';
$display_email = $user['email'] ?? 'Not provided';
$display_address = $user['address'] ?: 'Not provided';
$display_contact = $user['contact_number'] ?: 'Not provided';
$display_joined = isset($user['created_at']) && $user['created_at'] !== null
    ? date('F j, Y', strtotime($user['created_at']))
    : 'Unknown';
$role = current_user_role() ?: 'user';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Court7</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{
            background:#f3f3f3;
            margin:0;
            font-family:Arial, Helvetica, sans-serif;
        }
        .profile-wall {
            width:100%;
            max-width:1400px;
            margin:25px auto;
            padding:0 24px;
        }
        /* ====================================
           TOP NAVIGATION (MATCHED TO DASHBOARD)
        ======================================= */
        .top-navbar{
            background:#fff;
            border-radius:12px;
            box-shadow:0 0 10px rgba(0,0,0,.08);
            padding:12px 25px;
            margin-bottom:25px;
            display:flex;
            justify-content:between;
            align-items:center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .nav-brand-area {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .nav-avatar-thumb {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #0d6efd;
        }

        .nav-brand-text {
            display: flex;
            flex-direction: column;
        }

        .nav-brand-text h2 {
            color: #1f2937;
            font-size: 1.35rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
        }

        .nav-brand-text .brand-subtext {
            color: #6c757d;
            font-size: 0.85rem;
            margin-top: 2px;
        }

        .nav-menu{
            list-style:none;
            display:flex;
            align-items: center;
            gap:8px;
            margin:0;
            padding:0;
            flex-wrap: wrap;
        }

        .nav-menu li a{
            text-decoration:none;
            color:#333;
            font-weight:600;
            font-size: 0.95rem;
            padding:10px 20px;
            border-radius:8px;
            transition: all 0.15s ease;
            display: inline-block;
        }

        .nav-menu li a:hover{
            background:#eef2ff;
            color:#0d6efd;
        }

        .nav-menu li a.active{
            background:#0d6efd;
            color:#fff !important;
        }

        .logout{
            color:#dc3545 !important;
        }
        
        /* =========================
           CONTENT BLOCKS
        ========================= */
        .profile-card {
            display:grid;
            grid-template-columns:320px 1fr;
            gap:25px;
        }
        .profile-sidebar,
        .profile-main {
            background:#fff;
            border-radius:12px;
            box-shadow:0 0 10px rgba(0,0,0,.08);
            padding:25px;
        }
        .member-since{
            color:#666;
            margin-top:10px;
            font-size:14px;
        }

        .profile-info{
            margin-top:15px;
            color:#444;
            line-height:1.5;
        }

        .profile-btn{
            width:100%;
            margin-top:15px;
        }
        .profile-sidebar {
            position: sticky;
            top: 20px;
            align-self: start;
            border: 1px solid #dbeafe;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }
        .profile-avatar {
            width: 100%;
            max-width: 220px;
            aspect-ratio: 1 / 1;
            border-radius: 28px;
            object-fit: cover;
            border: 4px solid #0c63e4;
            margin-bottom: 20px;
            box-shadow: 0 16px 35px rgba(12, 99, 228, 0.16);
        }
        .profile-name {
            margin: 0 0 8px;
            font-size: 1.95rem;
            line-height: 1.15;
            color: #0f172a;
        }
        .profile-role {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 999px;
            background: #eaf3ff;
            color: black;
            font-weight: 700;
            letter-spacing: 0.4px;
            margin-bottom: 14px;
        }
        .profile-intro {
            color: #475569;
            line-height: 1.75;
            margin-bottom: 0;
        }
        .profile-status {
            margin-top: 20px;
            display: grid;
            gap: 10px;
        }
        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            background: #f2f8ff;
            border-radius: 14px;
            color: #0c63e4;
            font-weight: 700;
            border: 1px solid rgba(12, 99, 228, 0.16);
        }
        .profile-main{
            background:#fff;
            border-radius:12px;
            box-shadow:0 0 10px rgba(0,0,0,.08);
            padding:30px;
        }
        .profile-main h2 {
            background:#fff;
            border-radius:16px;
            padding:30px;
            box-shadow:0 2px 10px rgba(0,0,0,.08);
        }
        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .section-title {
            margin: 0;
            font-size: 1.1rem;
            color: #0f172a;
        }
        .section-subtitle {
            margin: 3px 0 0;
            color: #64748b;
            font-size: 0.95rem;
        }
        .section-card {
            background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
            border: 1px solid #e2ecff;
            border-radius: 20px;
            padding: 22px 24px;
            margin-bottom: 24px;
        }
        .section-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .info-block {
            background: #fff;
            border-radius: 16px;
            padding: 16px 18px;
            border: 1px solid #e8f0ff;
            box-shadow: 0 8px 24px rgba(12, 99, 228, 0.04);
        }
        .info-label {
            display: block;
            color: #64748b;
            font-size: 0.95rem;
            margin-bottom: 6px;
        }
        .info-value {
            color: #0f172a;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .profile-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
        }
        .profile-actions a,
        .profile-form button {
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 700;
            min-width: 160px;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
            border: none;
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(90deg, #0c63e4 0%, #1356c7 100%);
            color: #fff;
            box-shadow: 0 12px 24px rgba(12, 99, 228, 0.16);
        }
        .btn-secondary {
            background: #eef4ff;
            color: #0c63e4;
            border: 1px solid #dbeafe;
        }
        .profile-actions a:hover,
        .profile-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(12, 99, 228, 0.12);
        }
        .profile-form input,
        .profile-form textarea {
            width: 100%;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid #cbd5e1;
            margin-top: 8px;
            background: #fff;
            color: #0f172a;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .profile-form input:focus,
        .profile-form textarea:focus {
            outline: none;
            border-color: #0c63e4;
            box-shadow: 0 0 0 3px rgba(12, 99, 228, 0.12);
        }
        .profile-form .alert {
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 18px;
            font-weight: 700;
        }
        .profile-form .alert.success { background: #ecfdf5; color: #166534; }
        .profile-form .alert.error { background: #fef2f2; color: #991b1b; }
        .profile-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-top: 18px;
        }
        .summary-item {
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            border: 1px solid #e7f0ff;
            border-radius: 18px;
            padding: 18px 16px;
            text-align: center;
            box-shadow: 0 10px 24px rgba(12, 99, 228, 0.04);
        }
        .summary-value {
            font-size: 1.2rem;
            font-weight: 800;
            color: #0c63e4;
            margin-bottom: 8px;
            word-break: break-all;
        }
        .summary-label {
            color: #64748b;
            font-size: 0.95rem;
        }
        @media (max-width: 960px) {
            .profile-card { grid-template-columns: 1fr; }
            .section-grid { grid-template-columns: 1fr; }
            .profile-summary { grid-template-columns: 1fr; }
            .profile-sidebar { position: static; }
        }
    </style>
</head>
<body class="auth-page">

    <div class="profile-wall">

        <!-- TOP NAVIGATION MATCHED FOR SAME SIZE AND COLORS -->
        <nav class="top-navbar d-flex justify-content-between align-items-center">
            <div class="nav-brand-area">
                <img src="<?php echo htmlspecialchars($profile_photo); ?>" class="nav-avatar-thumb" alt="Profile">
                <div class="nav-brand-text">
                    <h2>Court 7</h2>
                    <span class="brand-subtext">Signed in as <?php echo htmlspecialchars($display_username); ?></span>
                </div>
            </div>

            <ul class="nav-menu">
                <li><a href="user_dashboard.php">Dashboard</a></li>
                <li><a href="profile.php" class="active">My Profile</a></li>
                <li><a href="booking_calendar.php">Book a Court</a></li>
                <li><a href="booking_history.php">Booking History</a></li>
                <li><a href="logout.php" class="logout">Logout</a></li>
            </ul>
        </nav>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="profile-card">

            <aside class="profile-sidebar">
                <img src="<?php echo htmlspecialchars($profile_photo); ?>" class="profile-avatar" alt="Profile">

                <h2 class="profile-name">
                    <?php echo htmlspecialchars($display_name); ?>
                </h2>

                <p class="member-since">
                    Member Since:<br>
                    <?php echo htmlspecialchars($display_joined); ?>
                </p>

                <p class="profile-info">
                    <strong>Username:</strong><br>
                    <?php echo htmlspecialchars($display_username); ?>
                </p>

                <p class="profile-info">
                    <strong>Address:</strong><br>
                    <?php echo htmlspecialchars($display_address); ?>
                </p>

                <button type="button" class="btn-primary profile-btn" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                    Edit Profile
                </button>

                <button type="button" class="btn-secondary profile-btn" data-bs-toggle="modal" data-bs-target="#passwordModal">
                    Change Password
                </button>
            </aside>

            <section class="profile-main">
                <div class="section-head">
                    <div>
                        <h2>Profile Details</h2>
                        <p class="section-subtitle">Keep your account information clear, updated, and easy to manage.</p>
                    </div>
                </div>
                
                <div class="profile-summary">
                    <div class="summary-item">
                        <div class="summary-value"><?php echo htmlspecialchars($display_username); ?></div>
                        <div class="summary-label">Username</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value"><?php echo htmlspecialchars($display_email); ?></div>
                        <div class="summary-label">Email Address</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value"><?php echo htmlspecialchars($display_contact); ?></div>
                        <div class="summary-label">Contact Number</div>
                    </div>
                </div>

                <div class="section-card mt-4">
                    <div class="section-head">
                        <div>
                            <h3 class="section-title">Personal Information Overview</h3>
                            <p class="section-subtitle">Read-only account overview. Use "Edit Profile" to modify files.</p>
                        </div>
                    </div>
                    <div class="section-grid">
                        <div class="info-block">
                            <span class="info-label">Full Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['name'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-block">
                            <span class="info-label">Username</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['username'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-block">
                            <span class="info-label">Email Address</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['email'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-block">
                            <span class="info-label">Contact Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['contact_number'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-block" style="grid-column: span 2;">
                            <span class="info-label">Address</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['address'] ?? 'Not provided'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="section-card">
                    <div class="section-head">
                        <div>
                            <h3 class="section-title">Quick Links</h3>
                            <p class="section-subtitle">Jump back to your booking tools whenever you need them.</p>
                        </div>
                    </div>
                    <div class="profile-actions" style="justify-content:flex-start;">
                        <a href="booking_calendar.php" class="btn-primary">Book a Court</a>
                        <a href="booking_history.php" class="btn-secondary">Booking History</a>
                        <a href="user_dashboard.php" class="btn-secondary">Back to Dashboard</a>
                    </div>
                </div>
            </section>
            
        </div>

        <!-- MODAL: EDIT PROFILE -->
        <div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-md modal-dialog-centered">
                <div class="modal-content profile-form" style="border-radius: 16px; padding: 15px;">
                    <div class="modal-header border-0">
                        <div>
                            <h4 class="modal-title fw-bold">Personal Information</h4>
                            <p class="text-muted small m-0">Update your personal details below.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="fw-bold text-secondary small">Full Name</label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold text-secondary small">Username</label>
                                <input type="text" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold text-secondary small">Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold text-secondary small">Contact Number</label>
                                <input type="text" name="contact_number" value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold text-secondary small">Address</label>
                                <input type="text" name="address" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
                            </div>
                            <div class="mb-4">
                                <label class="fw-bold text-secondary small">Profile Photo</label>
                                <input type="file" name="profile_photo" accept="image/*">
                            </div>
                            <button type="submit" name="save_profile" class="btn-primary w-100 py-2">
                                Save Changes
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL: CHANGE PASSWORD -->
        <div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered" style="max-width:400px;">
                <div class="modal-content profile-form" style="border-radius: 16px; padding: 15px;">
                    <div class="modal-header border-0">
                        <div>
                            <h4 class="modal-title fw-bold">Change Password</h4>
                            <p class="text-muted small m-0">Update your account credentials safely.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="fw-bold text-secondary small">New Password</label>
                                <input type="password" name="password" placeholder="Enter new password" required>
                            </div>
                            <div class="mb-4">
                                <label class="fw-bold text-secondary small">Confirm New Password</label>
                                <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                            </div>
                            <button type="submit" name="change_password" class="btn-primary w-100 py-2">
                                Save Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>