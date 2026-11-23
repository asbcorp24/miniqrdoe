<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/app.php';
require dirname(__DIR__).'/src/admin_auth.php';
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
if (!hash_equals(token(), (string)($_POST['csrf']??''))) { http_response_code(403); exit('Invalid CSRF token'); }
admin_logout();
header('Location: index.php?page=work', true, 303);
exit;
