<?php
declare(strict_types=1);
function route_schema(PDO $p): void {
$p->exec("CREATE TABLE IF NOT EXISTS routes(id INTEGER PRIMARY KEY,name TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS route_steps(id INTEGER PRIMARY KEY,route_id INTEGER NOT NULL REFERENCES routes(id),position INTEGER NOT NULL,name TEXT NOT NULL,machines TEXT NOT NULL,UNIQUE(route_id,position));
CREATE TABLE IF NOT EXISTS group_steps(id INTEGER PRIMARY KEY,group_id INTEGER NOT NULL REFERENCES part_groups(id),position INTEGER NOT NULL,name TEXT NOT NULL,machines TEXT NOT NULL,UNIQUE(group_id,position));
CREATE TABLE IF NOT EXISTS route_operations(entry_id INTEGER PRIMARY KEY REFERENCES work_entries(id),step_id INTEGER NOT NULL REFERENCES group_steps(id),quantity INTEGER NOT NULL CHECK(quantity>0),good INTEGER NOT NULL DEFAULT 0,scrap INTEGER NOT NULL DEFAULT 0,rework INTEGER NOT NULL DEFAULT 0, CHECK(good>=0 AND scrap>=0 AND rework>=0 AND good+scrap+rework<=quantity));
CREATE INDEX IF NOT EXISTS route_operations_step ON route_operations(step_id);");
}
function route_tx(PDO $p, callable $fn): void {$p->exec('BEGIN IMMEDIATE');try{$fn();$p->exec('COMMIT');}catch(Throwable $e){$p->exec('ROLLBACK');throw $e;}}
function route_create(PDO $p): void {
 $name=required('name');$names=$_POST['step_name']??[];$machines=$_POST['step_machines']??[];
 if(!is_array($names)||count($names)>50)throw new DomainException('Неверные этапы');
 route_tx($p,function()use($p,$name,$names,$machines){
 $p->prepare('INSERT INTO routes(name) VALUES(?)')->execute([$name]);$id=(int)$p->lastInsertId();$pos=0;
 foreach($names as $i=>$raw){$n=trim((string)$raw);if($n==='')continue;if(strlen($n)>360)throw new DomainException('Слишком длинное название этапа');$ids=array_values(array_unique(array_map('intval',(array)($machines[$i]??[]))));if(!$ids)throw new DomainException('Выберите станки для этапа '.$n);foreach($ids as $m)if(!first('SELECT id FROM machines WHERE id=? AND active=1',[$m]))throw new DomainException('Станок недоступен');
 $p->prepare('INSERT INTO route_steps(route_id,position,name,machines) VALUES(?,?,?,?)')->execute([$id,++$pos,$n,json_encode($ids)]);}
 if(!$pos)throw new DomainException('Добавьте хотя бы один этап');
 });
}
function route_group(PDO $p): void {
 $route=positive('route_id');$name=required('name');$received=dateField('received_at');$planned=dateField('planned_at',true);$qty=positive('quantity');
 route_tx($p,function()use($p,$route,$name,$received,$planned,$qty){
 $steps=query('SELECT * FROM route_steps WHERE route_id=? ORDER BY position',[$route]);if(!$steps)throw new DomainException('Маршрут не найден');
 $p->prepare('INSERT INTO part_groups(name,received_at,planned_at,quantity) VALUES(?,?,?,?)')->execute([$name,$received,$planned,$qty]);$id=(int)$p->lastInsertId();
 foreach($steps as $s)$p->prepare('INSERT INTO group_steps(group_id,position,name,machines) VALUES(?,?,?,?)')->execute([$id,$s['position'],$s['name'],$s['machines']]);
 });
}
function route_steps(int $group): array {
 $steps=query("SELECT s.*,COALESCE(SUM(o.good),0) good,COALESCE(SUM(o.scrap),0) scrap,COALESCE(SUM(o.rework),0) rework,COALESCE(SUM(CASE WHEN e.status='active' THEN o.quantity ELSE 0 END),0) reserved FROM group_steps s LEFT JOIN route_operations o ON o.step_id=s.id LEFT JOIN work_entries e ON e.id=o.entry_id WHERE s.group_id=? GROUP BY s.id ORDER BY s.position",[$group]);
 if(!$steps)return [];
 $g=first('SELECT quantity FROM part_groups WHERE id=?',[$group]);$input=(int)$g['quantity']+array_sum(array_column($steps,'scrap'));
 foreach($steps as &$s){$s['available']=$input-(int)$s['good']-(int)$s['scrap']-(int)$s['reserved'];$input=(int)$s['good'];}unset($s);return $steps;
}
function route_start(PDO $p,int $group,int $machine,int $worker,int $n): void {
 $steps=route_steps($group);$id=positive('step_id');$qty=positive('batch_quantity');$step=null;foreach($steps as $s)if((int)$s['id']===$id)$step=$s;
 if(!$step||!in_array($machine,json_decode($step['machines'],true),true))throw new DomainException('Станок не подходит для этапа');
 if($qty>$step['available'])throw new DomainException('Доступно деталей: '.$step['available']);
 $p->prepare("INSERT INTO work_entries(machine_id,worker_id,group_id,part_number,started_at) VALUES(?,?,?,?,datetime('now'))")->execute([$machine,$worker,$group,$n]);$entry=(int)$p->lastInsertId();
 $p->prepare('INSERT INTO route_operations(entry_id,step_id,quantity) VALUES(?,?,?)')->execute([$entry,$id,$qty]);
}
function route_finish(PDO $p,array $entry): bool {
 $o=first('SELECT o.*,s.position FROM route_operations o JOIN group_steps s ON s.id=o.step_id WHERE o.entry_id=?',[$entry['id']]);if(!$o)return false;
 $values=[];foreach(['batch_good','batch_scrap','batch_rework'] as $key){$v=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($v===false)throw new DomainException('Укажите количество по каждому результату');$values[]=$v;}
 [$good,$scrap,$rework]=$values;if(array_sum($values)!==(int)$o['quantity'])throw new DomainException('Сумма результатов должна совпадать с резервом');
 $reason=$scrap||$rework?required('quality_reason'):null;$quality=$scrap?'scrap':($rework?'rework':'good');
 $p->prepare('UPDATE route_operations SET good=?,scrap=?,rework=? WHERE entry_id=?')->execute([$good,$scrap,$rework,$entry['id']]);
 $p->prepare("UPDATE work_entries SET status='done',quality_result=?,quality_reason=?,ended_at=datetime('now'),duration_seconds=MAX(0,strftime('%s','now')-strftime('%s',started_at)) WHERE id=? AND status='active'")->execute([$quality,$reason,$entry['id']]);
 if(!first("SELECT id FROM work_entries WHERE id=? AND status='done'",[$entry['id']]))throw new DomainException('Операция не сохранена');
 if(!first('SELECT id FROM group_steps WHERE group_id=? AND position>?',[$entry['group_id'],$o['position']])){
 $p->prepare('UPDATE part_groups SET completed=completed+? WHERE id=?')->execute([$good,$entry['group_id']]);
 $p->prepare("UPDATE part_groups SET status='closed',closed_at=datetime('now') WHERE id=? AND completed=quantity")->execute([$entry['group_id']]);}
 return true;
}
