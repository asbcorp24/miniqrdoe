<?php
declare(strict_types=1);
// Included only from the authenticated reports page.
if(!admin_logged_in()) {http_response_code(403);return;}
$id=max(0,(int)($_GET['group_id']??0));
$q=trim((string)($_GET['q']??''));
$status=(string)($_GET['status']??'');
$from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
$args=[];$where=['1=1'];
if($q!==''){$where[]='instr(g.name,?)>0';$args[]=$q;}
if(in_array($status,['open','closed'],true)){$where[]='g.status=?';$args[]=$status;}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$from)){$where[]='date(g.received_at)>=?';$args[]=$from;}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$to)){$where[]='date(g.received_at)<=?';$args[]=$to;}
$list=query("SELECT g.*,COALESCE(a.done,0) done,COALESCE(a.active,0) active FROM part_groups g LEFT JOIN (SELECT group_id,SUM(status='done') done,SUM(status='active') active FROM work_entries GROUP BY group_id) a ON a.group_id=g.id WHERE ".implode(' AND ',$where)." ORDER BY g.id DESC",$args);
$g=$id?first('SELECT * FROM part_groups WHERE id=?',[$id]):null;
$rows=$g?query("SELECT e.*,COALESCE(w.name,'Работник #'||e.worker_id) worker,COALESCE(m.name,'Станок #'||e.machine_id) machine FROM work_entries e LEFT JOIN workers w ON w.id=e.worker_id LEFT JOIN machines m ON m.id=e.machine_id WHERE e.group_id=? ORDER BY e.part_number",[$id]):[];
if(($_GET['group_csv']??'')==='1'){
 header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="groups.csv"');
 $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
 $safe=fn($v)=>is_string($v)&&preg_match('/^[\s]*[=+@-]/u',$v)?"'".$v:$v;
 if($g){fputcsv($out,['Деталь','Работник','Станок','Начало UTC','Конец UTC','Секунды','Статус'],';');foreach($rows as $e)fputcsv($out,array_map($safe,[$e['part_number'],$e['worker'],$e['machine'],$e['started_at'],$e['ended_at'],$e['duration_seconds'],$e['status']]),';');}
 else{fputcsv($out,['Группа','План','Готово','В работе','Осталось','Приём','Срок','Статус'],';');foreach($list as $a)fputcsv($out,array_map($safe,[$a['name'],$a['quantity'],$a['done'],$a['active'],max(0,$a['quantity']-$a['done']),$a['received_at'],$a['planned_at'],$a['status']]),';');}
 fclose($out);exit;
}
if(defined('GROUP_EXPORT_ONLY'))return;
?>
<div class="headline"><div><small>ПРОИЗВОДСТВЕННАЯ АНАЛИТИКА</small><h1>По группам</h1><p>Фактические данные журнала. Время операций — UTC.</p></div><a class="export" href="?page=reports">Общие отчёты</a></div>
<form class="filters" method="get"><input type="hidden" name="page" value="reports"><input type="hidden" name="tab" value="groups"><label>Группа<input name="q" value="<?=h($q)?>"></label><label>Статус<select name="status"><option value="">Все</option><option value="open" <?=$status==='open'?'selected':''?>>Открытые</option><option value="closed" <?=$status==='closed'?'selected':''?>>Закрытые</option></select></label><label>Приём с<input type="date" name="from" value="<?=h($from)?>"></label><label>По<input type="date" name="to" value="<?=h($to)?>"></label><button class="primary">Применить</button><a class="export" href="?<?=h(http_build_query(['page'=>'reports','tab'=>'groups','q'=>$q,'status'=>$status,'from'=>$from,'to'=>$to,'group_csv'=>1]))?>">CSV групп</a></form>
<section class="card"><h2>Группы деталей</h2><div class="tablewrap"><table><thead><tr><th>Группа</th><th>План</th><th>Готово</th><th>В работе</th><th>Осталось</th><th>Выполнено</th><th>Срок</th></tr></thead><tbody>
<?php foreach($list as $a):$pct=100*$a['done']/max(1,$a['quantity']);?><tr><td><a href="?page=reports&amp;tab=groups&amp;group_id=<?=(int)$a['id']?>"><?=h($a['name'])?></a></td><td><?=$a['quantity']?></td><td><?=$a['done']?></td><td><?=$a['active']?></td><td><?=max(0,$a['quantity']-$a['done'])?></td><td><div class="bar"><i style="width:<?=min(100,$pct)?>%"></i></div><?=round($pct,1)?>%</td><td><?=h($a['planned_at']??'—')?></td></tr><?php endforeach;?>
</tbody></table></div><?php if(!$list):?><p>Нет групп по выбранным условиям.</p><?php endif;?></section>
<?php if($id&&!$g):?><p>Группа не найдена.</p><?php endif;?>
<?php if($g):
$done=array_values(array_filter($rows,fn($e)=>$e['status']==='done'));
$dur=array_values(array_map(fn($e)=>(int)$e['duration_seconds'],array_filter($done,fn($e)=>$e['duration_seconds']!==null)));
$remaining=max(0,(int)$g['quantity']-count($done));
$firstStart=$rows?min(array_column($rows,'started_at')):null;
$lastEnd=$done?max(array_column($done,'ended_at')):null;
$now=time();$elapsed=$firstStart?max(0,($remaining===0&&$lastEnd?strtotime($lastEnd.' UTC'):$now)-strtotime($firstStart.' UTC')):0;
$eta=$remaining&&count($done)&&$elapsed>0?$now+(int)($elapsed/count($done)*$remaining):null;
$deadline=$g['planned_at']?strtotime($g['planned_at']):null;
$comparison=$remaining===0&&$lastEnd?strtotime($lastEnd.' UTC'):($eta??$now);
$days=[];foreach($done as $e){$d=substr((string)$e['ended_at'],0,10);$days[$d]=($days[$d]??0)+1;}ksort($days);
?>
<section class="card"><h2><?=h($g['name'])?> — подробный отчёт</h2><a class="export" href="?page=reports&amp;tab=groups&amp;group_id=<?=$id?>&amp;group_csv=1">CSV деталей</a><div class="kpis"><div><span>Готово / план</span><b><?=count($done)?> / <?=$g['quantity']?></b></div><div><span>Трудозатраты, ч</span><b><?=round(array_sum($dur)/3600,2)?></b></div><div><span>Среднее, мин</span><b><?=$dur?round(array_sum($dur)/count($dur)/60,1):'—'?></b></div><div><span>Мин / макс, мин</span><b><?=$dur?round(min($dur)/60,1).' / '.round(max($dur)/60,1):'—'?></b></div></div>
<p>Приём: <?=h($g['received_at'])?> · План: <?=h($g['planned_at']??'—')?> · Закрытие: <?=h($g['closed_at']??'—')?></p><p>Календарное время с первой операции: <?=round($elapsed/3600,2)?> ч. Прогноз по фактическому темпу: <?=$eta?h(gmdate('Y-m-d H:i',$eta).' UTC'):'—'?>.</p><p><?php if($deadline&&($eta||$remaining===0||$now>$deadline)):?><?=$comparison>$deadline?'Отставание':'Опережение'?>: <?=round(abs($comparison-$deadline)/3600,1)?> ч<?php else:?>Недостаточно данных для сравнения с планом.<?php endif;?></p></section>
<section class="card"><h2>Накопительный выпуск по дням</h2><?php $cum=0;foreach($days as $day=>$n):$cum+=$n;?><div class="day"><span><?=h($day)?></span><div class="bar"><i style="width:<?=min(100,100*$cum/max(1,$g['quantity']))?>%"></i></div><b><?=$cum?> / <?=$g['quantity']?></b></div><?php endforeach;?><?php if(!$days):?><p>Нет завершённых деталей.</p><?php endif;?><p class="sub">Шкала — полный план группы; дневной план не задан.</p></section>
<div class="columns"><?php foreach(['worker'=>'По работникам','machine'=>'По станкам'] as $key=>$title):$stats=[];foreach($rows as $e){$k=$e[$key.'_id'];$stats[$k]??=['name'=>$e[$key],'done'=>0,'active'=>0,'seconds'=>0];if($e['status']==='done'){$stats[$k]['done']++;$stats[$k]['seconds']+=(int)$e['duration_seconds'];}elseif($e['status']==='active')$stats[$k]['active']++;}?><section class="card"><h2><?=$title?></h2><div class="tablewrap"><table><thead><tr><th>Название</th><th>Готово</th><th>В работе</th><th>Часов</th><th>Мин/деталь</th></tr></thead><tbody><?php foreach($stats as $s):?><tr><td><?=h($s['name'])?></td><td><?=$s['done']?></td><td><?=$s['active']?></td><td><?=round($s['seconds']/3600,2)?></td><td><?=$s['done']?round($s['seconds']/60/$s['done'],1):'—'?></td></tr><?php endforeach;?></tbody></table></div></section><?php endforeach;?></div>
<section class="card"><h2>История деталей</h2><div class="tablewrap"><table><thead><tr><th>№</th><th>Работник</th><th>Станок</th><th>Начало UTC</th><th>Конец UTC</th><th>Минут</th><th>Статус</th></tr></thead><tbody><?php foreach($rows as $e):?><tr><td><?=$e['part_number']?></td><td><?=h($e['worker'])?></td><td><?=h($e['machine'])?></td><td><?=h($e['started_at'])?></td><td><?=h($e['ended_at']??'—')?></td><td><?=$e['duration_seconds']===null?'—':round($e['duration_seconds']/60,1)?></td><td><?=h(['done'=>'Готово','active'=>'В работе','cancelled'=>'Отменено'][$e['status']]??$e['status'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?>
