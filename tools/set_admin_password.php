<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$path = dirname(__DIR__) . '/var/admin_password.hash';
if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
    fwrite(STDERR, "Cannot create var directory\n"); exit(1);
}
fwrite(STDOUT, "New admin password (at least 12 characters): ");
$password = rtrim((string)fgets(STDIN), "\r\n");
if (strlen($password) < 12) {
    fwrite(STDERR, "Password is too short\n"); exit(1);
}
$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false || file_put_contents($path, $hash . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Cannot write password file\n"); exit(1);
}
@chmod($path, 0600);
fwrite(STDOUT, "Password configured. File: var/admin_password.hash\n");
