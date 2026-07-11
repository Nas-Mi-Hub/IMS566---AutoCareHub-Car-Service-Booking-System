<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
ensureSchemaUpdates();

function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE id=? AND is_active=1');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) redirect(baseUrl('auth/login.php'));
    return $user;
}

function requireRole(string ...$roles): array
{
    $user = requireLogin();
    if (!in_array($user['role'], $roles, true)) {
        flash('error', 'Access denied.');
        redirect(dashboardForRole($user['role']));
    }
    return $user;
}

function dashboardForRole(string $role): string
{
    return match ($role) {
        'admin'    => baseUrl('admin/dashboard.php'),
        'mechanic' => baseUrl('mechanic/dashboard.php'),
        default    => baseUrl('user/dashboard.php'),
    };
}

function attemptLogin(string $username, string $password): bool
{
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE (username=? OR email=?) AND is_active=1');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        return true;
    }
    return false;
}

function registerUser(string $username, string $email, string $password, string $fullName, string $contactNo = ''): array
{
    $username = trim($username);
    $email = trim($email);
    $fullName = trim($fullName);
    $contactNo = trim($contactNo);

    if ($username === '' || $email === '' || $password === '' || $fullName === '') {
        return ['ok' => false, 'error' => 'All required fields must be filled.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please enter a valid email address.'];
    }
    if (strlen($password) < 6) {
        return ['ok' => false, 'error' => 'Password must be at least 6 characters.'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        return ['ok' => false, 'error' => 'Username must be 3–50 characters (letters, numbers, underscore).'];
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE username=? OR email=? LIMIT 1');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'error' => 'Username or email is already registered.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare('INSERT INTO users (username,email,password_hash,role,full_name,contact_no) VALUES (?,?,?,?,?,?)')
       ->execute([$username, $email, $hash, 'customer', $fullName, $contactNo ?: null]);

    $userId = (int) $db->lastInsertId();

    notify($userId, 'Welcome to AutoCare Hub', 'Your customer account is ready. Add a vehicle to start booking services.', 'success', baseUrl('user/vehicles.php'));

    return ['ok' => true, 'user_id' => $userId];
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}