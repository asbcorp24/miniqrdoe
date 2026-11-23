<?php
declare(strict_types=1);

function admin_password_file(): string {
    return dirname(__DIR__) . '/var/admin_password.hash';
}
function admin_password_hash(): ?string {
    $file = admin_password_file();
    if (!is_file($file)) return null;
    $hash = trim((string)file_get_contents($file));
    return str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2') ? $hash : null;
}
function admin_logged_in(): bool {
    $hash = admin_password_hash();
    return $hash !== null && isset($_SESSION['admin_password_marker'])
        && hash_equals(hash('sha256', $hash), (string)$_SESSION['admin_password_marker']);
}
function admin_require(): void {
    if (admin_logged_in()) return;
    header('Location: login.php', true, 303);
    exit;
}
function admin_login(string $password): bool {
    $hash = admin_password_hash();
    if ($hash === null || !password_verify($password, $hash)) return false;
    session_regenerate_id(true);
    $_SESSION['admin_password_marker'] = hash('sha256', $hash);
    unset($_SESSION['admin_login_failures'], $_SESSION['admin_login_block_until']);
    return true;
}
function admin_logout(): void {
    unset($_SESSION['admin_password_marker']);
    session_regenerate_id(true);
}
