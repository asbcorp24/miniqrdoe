<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/app.php';
require dirname(__DIR__).'/src/admin_auth.php';
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
session_start();
if (admin_logged_in()) { header('Location: index.php?page=groups',true,303); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(token(),(string)($_POST['csrf']??''))) {
        $error = 'Сессия формы истекла. Обновите страницу.';
    } elseif (time() < (int)($_SESSION['admin_login_block_until']??0)) {
        $error = 'Слишком много попыток. Повторите через 15 минут.';
    } elseif (admin_login((string)($_POST['password']??''))) {
        header('Location: index.php?page=groups',true,303); exit;
    } else {
        $_SESSION['admin_login_failures'] = (int)($_SESSION['admin_login_failures']??0) + 1;
        if ($_SESSION['admin_login_failures'] >= 5) {
            $_SESSION['admin_login_block_until'] = time() + 900;
            $_SESSION['admin_login_failures'] = 0;
        }
        $error = 'Неверный пароль администратора.';
    }
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Вход администратора — MiniQRDOE</title><link rel="stylesheet" href="assets/style.css?v=3"></head>
<body><header class="app-header"><div class="header-inner"><a class="brand" href="index.php?page=work"><span class="brand-mark">M</span><span><b>MiniQRDOE</b><small>Производственный учёт</small></span></a><nav class="main-nav"><a href="index.php?page=work">← Рабочее место</a></nav></div></header>
<main class="app-main auth-main"><section class="card auth-card"><div class="card-heading"><div class="heading-icon">⌑</div><div><h2>Вход администратора</h2><p>Защищённый доступ к управлению производством</p></div></div>
<?php if(!admin_password_hash()):?><div class="notice error">Пароль не настроен. На сервере запустите: <code>php tools/set_admin_password.php</code>.</div><?php endif; ?>
<?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif; ?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=h(token())?>">
<label for="password">Пароль</label><input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
<button class="primary full" type="submit">Войти</button></form>
<p class="sub">Доступ к справочникам, группам деталей, отчётам и печати QR-этикеток защищён паролем.</p>
</section></main></body></html>
