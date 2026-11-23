<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/app.php';
require dirname(__DIR__).'/src/admin_auth.php';
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
session_start();
$error='';$success=$_SESSION['success']??'';unset($_SESSION['success']);
try {
 db();
 $requestedPage=(string)($_GET['page']??'work');
 if (in_array($requestedPage,['groups','directory','reports'],true) || ($_GET['action']??'')==='csv') admin_require();
 if(($_GET['action']??'')==='csv')exportCsv();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals(token(),(string)($_POST['csrf']??'')))throw new DomainException('Сессия устарела: перезагрузите страницу');
  $requestedAction=(string)($_POST['action']??'');
  if (in_array($requestedAction,['machine','worker','group'],true)) admin_require();
  perform($requestedAction);
  $_SESSION['success']='Изменения сохранены';
  $target=in_array($_POST['back']??'',['work','groups','directory'],true)?$_POST['back']:'work';
  header('Location: ?page='.$target,true,303);exit;
 }
}catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'Ошибка базы данных или дублирующийся код: '.$e->getMessage();}
$page=(string)($_GET['page']??'work');
if(!in_array($page,['work','groups','directory','reports'],true))$page='work';
$machines=query('SELECT * FROM machines WHERE active=1 ORDER BY name');
$workers=query('SELECT * FROM workers WHERE active=1 ORDER BY name');
$groups=query('SELECT * FROM part_groups ORDER BY id DESC LIMIT 1000');
$active=query("SELECT e.*,m.name machine,w.name worker,g.name grp FROM work_entries e JOIN machines m ON m.id=e.machine_id JOIN workers w ON w.id=e.worker_id JOIN part_groups g ON g.id=e.group_id WHERE e.status='active' ORDER BY e.id DESC");
$entries=query("SELECT e.*,m.name machine,w.name worker,g.name grp FROM work_entries e JOIN machines m ON m.id=e.machine_id JOIN workers w ON w.id=e.worker_id JOIN part_groups g ON g.id=e.group_id ORDER BY e.id DESC LIMIT 70");
$csrf=token();
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#172442"><title>MiniQRDOE — Маршрутные карты</title><link rel="stylesheet" href="assets/style.css"></head><body>
<header><div class="brand">▦ <span><b>MiniQRDOE</b><small>Маршрутные карты</small></span></div><nav><a href="?page=work" <?= $page==='work'?'class="on"':'' ?>>Работа</a><?php if(admin_logged_in()):?><a href="?page=groups" <?= $page==='groups'?'class="on"':'' ?>>Группы</a><?php endif;?><?php if(admin_logged_in()):?><a href="?page=directory" <?= $page==='directory'?'class="on"':'' ?>>Справочники</a><?php endif;?><?php if(admin_logged_in()):?><a href="?page=reports" <?= $page==='reports'?'class="on"':'' ?>>Аналитика</a><?php endif;?><?php if(admin_logged_in()):?><a href="labels.php">QR-этикетки</a><a href="index.php?page=groups">Админка</a><form method="post" action="logout.php" class="logout-inline"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><button type="submit">Выход</button></form><?php else:?><a href="login.php">Админка</a><?php endif;?></nav></header>
<main>
<?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif;?>
<?php if($success):?><div class="notice ok"><?=h($success)?></div><?php endif;?>
<?php if($page==='work'):?>
<div class="headline"><div><small>ПРОИЗВОДСТВЕННЫЙ ЖУРНАЛ</small><h1>Учёт изготовления деталей</h1><p>Сканируйте QR станка и работника, выберите группу, нажмите «Начал работу».</p></div><div class="chip"><?=count($active)?> в работе</div></div><div class="notice" id="usb-scan-status" role="status" aria-live="polite">USB-сканер готов: M:003 — станок, W:015 — работник, G:104 — группа. Сканируйте на странице «Работа»; сканер должен отправлять Enter.</div>
<div class="columns"><section class="card"><h2>Начать работу</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="start"><input type="hidden" name="back" value="work">
<label for="machine">Станок</label><div class="scanner"><select required name="machine_id" id="machine"><option value="">Выберите станок</option><?php foreach($machines as $m):?><option value="<?=$m['id']?>" data-code="<?=h($m['code'])?>"><?=h($m['name'])?> (<?=h($m['code'])?>)</option><?php endforeach;?></select><button type="button" class="scan" data-target="machine">▣ QR</button></div>
<label for="worker">Работник</label><div class="scanner"><select required name="worker_id" id="worker"><option value="">Выберите работника</option><?php foreach($workers as $w):?><option value="<?=$w['id']?>" data-code="<?=h($w['code'])?>"><?=h($w['name'])?> (<?=h($w['code'])?>)</option><?php endforeach;?></select><button type="button" class="scan" data-target="worker">▣ QR</button></div>
<label for="group">Группа деталей</label><select required name="group_id" id="group"><option value="">Выберите открытую группу</option><?php foreach($groups as $g):if($g['status']!=='open')continue;?><option value="<?=$g['id']?>"><?=h($g['name'])?> — <?=$g['completed']?> / <?=$g['quantity']?></option><?php endforeach;?></select><p class="sub">Группы нет? <a href="?page=groups">Добавить новую</a></p><button class="primary full">▶ Начал работу</button></form></section>
<section class="card"><h2>Сейчас в работе <span class="chip"><?=count($active)?></span></h2><?php if(!$active):?><p class="empty">Активных операций пока нет</p><?php endif;?>
<?php foreach($active as $e):?><div class="item"><div><strong><?=h($e['grp'])?> · деталь №<?=$e['part_number']?></strong><p><?=h($e['worker'])?> · <?=h($e['machine'])?></p><small>Начало <?=h($e['started_at'])?> UTC</small></div><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="finish"><input type="hidden" name="back" value="work"><input type="hidden" name="entry_id" value="<?=$e['id']?>"><button class="finish">✓ Закончил</button></form></div><?php endforeach;?></section></div>
<section class="card"><h2>Журнал последних операций</h2><div class="tablewrap"><table><thead><tr><th>ID</th><th>Деталь</th><th>Станок</th><th>Работник</th><th>Начало UTC</th><th>Конец UTC</th><th>Минут</th><th>Статус</th></tr></thead><tbody><?php foreach($entries as $e):?><tr><td><?=$e['id']?></td><td><?=h($e['grp'])?> №<?=$e['part_number']?></td><td><?=h($e['machine'])?></td><td><?=h($e['worker'])?></td><td><?=h($e['started_at'])?></td><td><?=h($e['ended_at']??'—')?></td><td><?=$e['duration_seconds']!==null?round($e['duration_seconds']/60,1):'—'?></td><td><span class="chip <?= $e['status']==='done'?'green':'' ?>"><?=h(['done'=>'Готово','active'=>'В работе','cancelled'=>'Отменено'][$e['status']]??$e['status'])?></span></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($page==='groups'):?>
<div class="headline"><div><small>ПАРТИИ ИЗДЕЛИЙ</small><h1>Группы деталей</h1><p>Группа закрывается автоматически, когда изготовлено установленное количество.</p></div></div>
<div class="columns"><section class="card"><h2>Создать группу</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="group"><input type="hidden" name="back" value="groups"><label>Название / номер группы</label><input name="name" required maxlength="180" placeholder="Заказ 104-А"><label>Время приёма</label><input type="datetime-local" name="received_at" required value="<?=date('Y-m-d\TH:i')?>"><label>Плановое время изготовления</label><input type="datetime-local" name="planned_at"><label>Количество деталей</label><input type="number" min="1" name="quantity" required><button class="primary full">+ Создать группу</button></form></section><section class="card"><h2>Состояние производства</h2><div class="metrics"><div><b><?=count(array_filter($groups,fn($g)=>$g['status']==='open'))?></b><span>Открытых групп</span></div><div><b><?=count(array_filter($groups,fn($g)=>$g['status']==='closed'))?></b><span>Закрытых групп</span></div></div><p class="sub">Номер детали назначается в начале работы. Счётчик готовых увеличивается только при завершении.</p></section></div>
<section class="card"><h2>Все группы</h2><div class="tablewrap"><table><thead><tr><th>ID</th><th>Название</th><th>Приём</th><th>План</th><th>Готово</th><th>Статус</th></tr></thead><tbody><?php foreach($groups as $g):?><tr><td><?=$g['id']?></td><td><?=h($g['name'])?></td><td><?=h($g['received_at'])?></td><td><?=h($g['planned_at']??'—')?></td><td><div class="bar"><i style="width:<?=100*$g['completed']/$g['quantity']?>%"></i></div><?=$g['completed']?> / <?=$g['quantity']?></td><td><span class="chip <?= $g['status']==='closed'?'green':'' ?>"><?= $g['status']==='closed'?'Закрыта':'Открыта' ?></span></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($page==='directory'):?>
<div class="headline"><div><small>СПРАВОЧНИКИ</small><h1>Станки и работники</h1><p>На наклейке QR должен быть уникальный код соответствующей записи.</p></div></div>
<div class="columns">
<?php foreach([['machine','Станки',$machines],['worker','Работники',$workers]] as [$kind,$title,$items]):?><section class="card"><h2><?=$title?></h2><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="<?=$kind?>"><input type="hidden" name="back" value="directory"><label>Название / ФИО</label><input name="name" required maxlength="180"><p class="sub">Уникальный QR-код создаётся автоматически после сохранения. Вводить код не нужно.</p><button class="primary full">+ Добавить</button></form><h3>Список</h3><?php foreach($items as $i):?><div class="item"><b><?=h($i['name'])?></b><code><?=h($i['code'])?></code></div><?php endforeach;?></section><?php endforeach;?></div>
<?php elseif($page==='reports'):
try {[$from,$to,$start,$end]=period();}catch(DomainException $e){$from=date('Y-m-01');$to=date('Y-m-d');$start=$from.' 00:00:00';$end=date('Y-m-d',strtotime($to.' +1 day')).' 00:00:00';}
$params=[$start,$end];
$stats=first("SELECT COUNT(*) total,COALESCE(SUM(status='done'),0) done,COALESCE(SUM(status='active'),0) active,COALESCE(SUM(CASE WHEN status='done' THEN duration_seconds ELSE 0 END),0) seconds FROM work_entries WHERE started_at>=? AND started_at<?",$params);
$workerStats=query("SELECT w.name,COUNT(*) total,COALESCE(SUM(e.status='done'),0) done,COALESCE(SUM(CASE WHEN e.status='done' THEN e.duration_seconds ELSE 0 END),0) seconds FROM work_entries e JOIN workers w ON e.worker_id=w.id WHERE e.started_at>=? AND e.started_at<? GROUP BY w.id ORDER BY done DESC",$params);
$machineStats=query("SELECT m.name,COUNT(*) total,COALESCE(SUM(e.status='done'),0) done,COALESCE(SUM(CASE WHEN e.status='done' THEN e.duration_seconds ELSE 0 END),0) seconds FROM work_entries e JOIN machines m ON e.machine_id=m.id WHERE e.started_at>=? AND e.started_at<? GROUP BY m.id ORDER BY done DESC",$params);
$days=query("SELECT substr(started_at,1,10) day,COUNT(*) total,COALESCE(SUM(status='done'),0) done FROM work_entries WHERE started_at>=? AND started_at<? GROUP BY substr(started_at,1,10) ORDER BY day",$params);
?>
<div class="headline"><div><small>ПРОИЗВОДСТВЕННАЯ АНАЛИТИКА</small><h1>Отчёты</h1><p>Показатели по работникам, станкам и дням; время хранится в UTC.</p></div></div>
<form class="filters" method="get"><input type="hidden" name="page" value="reports"><label>С даты<input type="date" name="from" value="<?=h($from)?>"></label><label>По дату<input type="date" name="to" value="<?=h($to)?>"></label><button class="primary">Применить</button><a class="export" href="?action=csv&amp;from=<?=h($from)?>&amp;to=<?=h($to)?>">↓ Скачать CSV</a></form>
<div class="kpis"><div><span>Операций</span><b><?=$stats['total']?></b></div><div><span>Изготовлено</span><b><?=$stats['done']?></b></div><div><span>В работе</span><b><?=$stats['active']?></b></div><div><span>Время работы</span><b><?=round($stats['seconds']/3600,1)?> ч</b></div></div>
<div class="columns"><?php foreach([['По работникам',$workerStats],['По станкам',$machineStats]] as [$title,$data]):?><section class="card"><h2><?=$title?></h2><div class="tablewrap"><table><thead><tr><th>Наименование</th><th>Операций</th><th>Готово</th><th>Часов</th><th>Мин/деталь</th></tr></thead><tbody><?php foreach($data as $r):?><tr><td><?=h($r['name'])?></td><td><?=$r['total']?></td><td><?=$r['done']?></td><td><?=round($r['seconds']/3600,2)?></td><td><?=$r['done']?round($r['seconds']/60/$r['done'],1):'—'?></td></tr><?php endforeach;?></tbody></table></div></section><?php endforeach;?></div>
<section class="card"><h2>Изготовление по дням</h2><?php $max=max(1,...array_map(fn($x)=>(int)$x['total'],$days));foreach($days as $r):?><div class="day"><span><?=h($r['day'])?></span><div class="bar"><i style="width:<?=100*$r['total']/$max?>%"></i></div><b><?=$r['done']?> / <?=$r['total']?></b></div><?php endforeach;?></section>
<?php endif;?></main>
<div id="scanner-modal" class="modal" hidden><div class="modal-body"><div class="item"><h2>Сканировать QR</h2><button class="scan" id="close-scanner">Закрыть</button></div><video id="scanner-video" autoplay playsinline muted></video><p id="scanner-status"></p><p class="sub">Для камеры нужен HTTPS или localhost. Если сканер не поддерживается, выберите запись вручную.</p></div></div>
<script src="assets/app.js" defer></script></body></html>
