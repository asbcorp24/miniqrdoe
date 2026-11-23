<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/app.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
try {
    $machines = query('SELECT id,name,code FROM machines WHERE active=1 ORDER BY name');
    $workers = query('SELECT id,name,code FROM workers WHERE active=1 ORDER BY name');
    $groups = query('SELECT id,name,status FROM part_groups ORDER BY id DESC');
} catch (Throwable $e) {
    http_response_code(503);
    exit('Ошибка загрузки справочников. Проверьте доступ к SQLite.');
}
function qrCode(string $kind, string $value): string {
    $value = trim($value);
    if (preg_match('/^[MWG]:/i', $value)) $value = substr($value, 2);
    return $kind.':'.$value;
}
$labels = [];
foreach ($machines as $m) $labels[] = ['type'=>'machine', 'type_name'=>'Станок', 'name'=>$m['name'], 'code'=>qrCode('M',$m['code'])];
foreach ($workers as $w) $labels[] = ['type'=>'worker', 'type_name'=>'Работник', 'name'=>$w['name'], 'code'=>qrCode('W',$w['code'])];
foreach ($groups as $g) $labels[] = ['type'=>'group', 'type_name'=>'Группа деталей', 'name'=>$g['name'], 'code'=>'G:'.$g['id'], 'note'=>$g['status']==='closed'?'Закрыта':'Открыта'];
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>QR-этикетки — MiniQRDOE</title>
<link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/labels.css">
<script src="assets/qrcode.min.js" defer></script><script src="assets/labels.js" defer></script>
</head><body>
<header><div class="brand">▦ <span><b>MiniQRDOE</b><small>Печать QR-этикеток</small></span></div>
<nav><a href="index.php?page=work">Работа</a><a href="index.php?page=groups">Группы</a><a href="index.php?page=directory">Справочники</a><a href="index.php?page=reports">Аналитика</a><a class="on" href="labels.php">QR-этикетки</a></nav></header>
<main><div class="headline"><div><small>ПЕЧАТЬ И МАРКИРОВКА</small><h1>QR-этикетки</h1><p>Локальный генератор QR для USB-сканера. Форматы M:, W: и G:.</p></div></div>
<section class="card labels-toolbar"><label for="label-type">Что печатать</label>
<select id="label-type"><option value="all">Все этикетки</option><option value="machine">Станки</option><option value="worker">Работники</option><option value="group">Группы деталей</option></select>
<label for="label-size">Размер наклейки</label><select id="label-size"><option value="60">60 × 40 мм</option><option value="70">70 × 50 мм</option></select>
<button type="button" class="scan" id="select-all-labels">Выбрать все</button>
<button type="button" class="scan" id="clear-all-labels">Снять выбор</button>
<button type="button" class="primary" id="print-labels">Печать выбранных</button>
<p class="sub">Отметьте нужные этикетки. Перед печатью отключите колонтитулы браузера и установите масштаб 100%. Выберите фактический формат бумаги в диалоге принтера.</p>
<div role="status" aria-live="polite" id="label-message"></div></section>
<div class="labels-grid" id="labels-grid">
<?php foreach ($labels as $item): ?>
<article class="qr-label" data-type="<?=h($item['type'])?>" data-payload="<?=h($item['code'])?>">
<label class="label-check"><input type="checkbox" class="choose-label" checked><span>Выбрать</span></label>
<div class="qr-label-body"><div class="qr-graphic" aria-label="QR <?=h($item['code'])?>"></div>
<div class="qr-meta"><small><?=h($item['type_name'])?></small><strong><?=h($item['name'])?></strong><code><?=h($item['code'])?></code></div></div>
</article>
<?php endforeach; ?>
</div>
<?php if (!$labels): ?><div class="card">Сначала добавьте станки, работников или группы деталей.</div><?php endif; ?>
</main></body></html>
