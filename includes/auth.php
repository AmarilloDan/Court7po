<?php
declare(strict_types=1);

function require_role($role) {
    if (!isset($_SESSION['role'])) {
        header("Location: index.php");
        exit;
    }

    if ($_SESSION['role'] !== $role) {
        header("Location: index.php");
        exit;
    }
}

function require_user_access(): void {
    if (!isset($_SESSION['id'])) {
        header("Location: index.php");
        exit;
    }

    $role = current_user_role();
    if ($role !== 'user' && $role !== 'admin') {
        header("Location: index.php");
        exit;
    }
}

function current_user_id(): int {
    return (int)($_SESSION['id'] ?? 0);
}

function current_user_name(): string {
    return (string)($_SESSION['name'] ?? '');
}

function current_user_role(): string {
    return (string)($_SESSION['role'] ?? '');
}

function current_user(): array {
    $userId = current_user_id();
    if ($userId <= 0) {
        return [];
    }

    $conn = db();
    if (!$conn) {
        return [];
    }

    $stmt = $conn->prepare('SELECT id, name, username, email, address, contact_number, profile_photo, created_at FROM users WHERE id = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : [];
        $stmt->close();
        if (!empty($user)) {
            $_SESSION['name'] = $user['name'] ?? $_SESSION['name'];
            $_SESSION['user'] = $user['username'] ?? $_SESSION['user'];
            return $user;
        }
    }

    $usersFile = __DIR__ . '/../uploads/users.json';
    if (!file_exists($usersFile)) {
        return [];
    }

    $data = @file_get_contents($usersFile);
    if ($data === false || trim($data) === '') {
        return [];
    }

    $decoded = json_decode($data, true);
    if (!is_array($decoded)) {
        return [];
    }

    foreach ($decoded as $entry) {
        if (isset($entry['id']) && ((int)$entry['id']) === $userId) {
            return [
                'id' => $entry['id'],
                'name' => $entry['name'] ?? '',
                'username' => $entry['username'] ?? '',
                'email' => $entry['email'] ?? '',
                'address' => $entry['address'] ?? '',
                'contact_number' => $entry['contact_number'] ?? '',
                'profile_photo' => $entry['profile_photo'] ?? '',
                'created_at' => $entry['created_at'] ?? null,
            ];
        }
    }

    return [];
}
