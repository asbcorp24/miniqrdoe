<?php
declare(strict_types=1);
function route_schema(PDO $p): void {
$p->exec("CREATE TABLE IF NOT EXISTS routes(id INTEGER PRIMARY KEY,name TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS route_steps(id INTEGER PRIMARY KEY,route_id INTEGER NOT NULL REFERENCES routes(id),position INTEGER NOT NULL,name TEXT NOT NULL,machines TEXT NOT NULL,UNIQUE(route_id,position));
CREATE TABLE IF NOT EXISTS group_steps(id INTEGER PRIMARY KEY,group_id INTEGER NOT NULL REFERENCES part_groups(id),position INTEGER NOT NULL,name TEXT NOT NULL,machines TEXT NOT NULL,UNIQUE(group_id,position));
CREATE TABLE IF NOT EXISTS route_operations(entry_id INTEGER PRIMARY KEY REFERENCES work_entries(id),step_id INTEGER NOT NULL REFERENCES group_steps(id),quantity INTEGER NOT NULL CHECK(quantity>0),good INTEGER NOT NULL DEFAULT 0,scrap INTEGER NOT NULL DEFAULT 0,rework INTEGER NOT NULL DEFAULT 0, CHECK(good>=0 AND scrap>=0 AND rework>=0 AND good+scrap+rework<=quantity));
CREATE INDEX IF NOT EXISTS route_operations_step ON route_operations(step_id);
CREATE TABLE IF NOT EXISTS route_operation_allocations(id INTEGER PRIMARY KEY,entry_id INTEGER NOT NULL REFERENCES work_entries(id),group_id INTEGER NOT NULL REFERENCES part_groups(id),step_id INTEGER NOT NULL REFERENCES group_steps(id),quantity INTEGER NOT NULL CHECK(quantity>0),good INTEGER NOT NULL DEFAULT 0,scrap INTEGER NOT NULL DEFAULT 0,rework INTEGER NOT NULL DEFAULT 0,UNIQUE(entry_id,group_id),CHECK(good>=0 AND scrap>=0 AND rework>=0 AND good+scrap+rework<=quantity));
CREATE INDEX IF NOT EXISTS route_alloc_group_step ON route_operation_allocations(group_id,step_id);");
 foreach(["routes"=>["archived"=>"INTEGER NOT NULL DEFAULT 0","previous_id"=>"INTEGER"],"route_steps"=>["parents"=>"TEXT","quota"=>"INTEGER NOT NULL DEFAULT 0","norm_seconds"=>"INTEGER NOT NULL DEFAULT 0","instruction"=>"TEXT NOT NULL DEFAULT ''"],"group_steps"=>["parents"=>"TEXT","quota"=>"INTEGER NOT NULL DEFAULT 0","norm_seconds"=>"INTEGER NOT NULL DEFAULT 0","instruction"=>"TEXT NOT NULL DEFAULT ''"]] as $table=>$cols){$existing=array_column($p->query("PRAGMA table_info($table)")->fetchAll(),"name");foreach($cols as $key=>$type)if(!in_array($key,$existing,true)){try{$p->exec("ALTER TABLE $table ADD COLUMN $key $type");}catch(PDOException $e){if(!in_array($key,array_column($p->query("PRAGMA table_info($table)")->fetchAll(),"name"),true))throw $e;}}}
 $p->exec("CREATE TABLE IF NOT EXISTS route_inputs(entry_id INTEGER NOT NULL REFERENCES work_entries(id),source_position INTEGER NOT NULL,quantity INTEGER NOT NULL CHECK(quantity>0),PRIMARY KEY(entry_id,source_position))");
}
function route_tx(PDO $p, callable $fn): void {$p->exec('BEGIN IMMEDIATE');try{$fn();$p->exec('COMMIT');}catch(Throwable $e){$p->exec('ROLLBACK');throw $e;}}
function route_create(PDO $p): void {
 $previous=(int)($_POST['previous_id']??0);$name=required('name');$names=$_POST['step_name']??[];$machines=$_POST['step_machines']??[];
 if(!is_array($names)||count($names)>50)throw new DomainException('Неверные этапы');
 route_tx($p,function()use($p,$name,$names,$machines,$previous){
 if($previous&&!first('SELECT id FROM routes WHERE id=? AND archived=0',[$previous]))throw new DomainException('Версия уже архивирована');
 $p->prepare('INSERT INTO routes(name,previous_id) VALUES(?,?)')->execute([$name,$previous?:null]);$id=(int)$p->lastInsertId();$pos=0;
 foreach($names as $i=>$raw){$n=trim((string)$raw);if($n==='')continue;if(strlen($n)>360)throw new DomainException('Слишком длинное название этапа');$ids=array_values(array_unique(array_map('intval',(array)($machines[$i]??[]))));if(!$ids)throw new DomainException('Выберите станки для этапа '.$n);foreach($ids as $m)if(!first('SELECT id FROM machines WHERE id=? AND active=1',[$m]))throw new DomainException('Станок недоступен');
 $pos++;$rawParents=trim((string)($_POST['step_parents'][$i]??''));if($rawParents!==''&&!preg_match('/^\d+(\s*,\s*\d+)*$/D',$rawParents))throw new DomainException('Неверный список предшественников');$parents=$rawParents===''?[$pos-1]:array_map('intval',explode(',',$rawParents));$parents=array_values(array_unique($parents));foreach($parents as $parent)if($parent<0||$parent>=$pos)throw new DomainException('Предшественники должны иметь меньший номер');if(in_array(0,$parents,true)&&count($parents)>1)throw new DomainException('Вход партии нельзя объединять с этапами');$norm=filter_var($_POST['step_norm'][$i]??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($norm===false)throw new DomainException('Неверный норматив');$quota=filter_var($_POST['step_quota'][$i]??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($quota===false)throw new DomainException('Неверный лимит ветви');$instruction=trim((string)($_POST['step_instruction'][$i]??''));if(strlen($instruction)>12000)throw new DomainException('Инструкция слишком длинная');
 $p->prepare('INSERT INTO route_steps(route_id,position,name,machines,parents,norm_seconds,instruction,quota) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$pos,$n,json_encode($ids),json_encode($parents),$norm,$instruction,$quota]);}
 if(!$pos)throw new DomainException('Добавьте хотя бы один этап');if($previous)$p->prepare('UPDATE routes SET archived=1 WHERE id=?')->execute([$previous]);
 });
}
function route_group(PDO $p): void {
 $route=positive('route_id');$name=required('name');$received=dateField('received_at');$planned=dateField('planned_at',true);$qty=positive('quantity');
 route_tx($p,function()use($p,$route,$name,$received,$planned,$qty){
 $steps=query('SELECT s.* FROM route_steps s JOIN routes r ON r.id=s.route_id WHERE s.route_id=? AND r.archived=0 ORDER BY s.position',[$route]);if(!$steps)throw new DomainException('Маршрут не найден');
 $p->prepare('INSERT INTO part_groups(name,received_at,planned_at,quantity) VALUES(?,?,?,?)')->execute([$name,$received,$planned,$qty]);$id=(int)$p->lastInsertId();
 foreach($steps as $s)$p->prepare('INSERT INTO group_steps(group_id,position,name,machines,parents,norm_seconds,instruction,quota) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$s['position'],$s['name'],$s['machines'],$s['parents'],$s['norm_seconds'],$s['instruction'],$s['quota']]);
 });
}
function route_steps(int $group): array {
 $steps=query("SELECT s.*,COALESCE((SELECT SUM(o.good) FROM route_operations o JOIN work_entries e ON e.id=o.entry_id WHERE o.step_id=s.id AND e.group_id=s.group_id),0)+COALESCE((SELECT SUM(a.good) FROM route_operation_allocations a WHERE a.step_id=s.id AND a.group_id=s.group_id),0) good,COALESCE((SELECT SUM(o.scrap) FROM route_operations o JOIN work_entries e ON e.id=o.entry_id WHERE o.step_id=s.id AND e.group_id=s.group_id),0)+COALESCE((SELECT SUM(a.scrap) FROM route_operation_allocations a WHERE a.step_id=s.id AND a.group_id=s.group_id),0) scrap,COALESCE((SELECT SUM(o.rework) FROM route_operations o JOIN work_entries e ON e.id=o.entry_id WHERE o.step_id=s.id AND e.group_id=s.group_id),0)+COALESCE((SELECT SUM(a.rework) FROM route_operation_allocations a WHERE a.step_id=s.id AND a.group_id=s.group_id),0) rework,COALESCE((SELECT SUM(o.quantity) FROM route_operations o JOIN work_entries e ON e.id=o.entry_id WHERE o.step_id=s.id AND e.group_id=s.group_id AND e.status='active'),0)+COALESCE((SELECT SUM(a.quantity) FROM route_operation_allocations a JOIN work_entries e ON e.id=a.entry_id WHERE a.step_id=s.id AND a.group_id=s.group_id AND e.status='active'),0) reserved FROM group_steps s WHERE s.group_id=? ORDER BY s.position",[$group]);
 if(!$steps)return [];
 if($steps[0]['parents']!==null)return route_graph_balances($group,$steps);
 $g=first('SELECT quantity FROM part_groups WHERE id=?',[$group]);$input=(int)$g['quantity']+array_sum(array_column($steps,'scrap'));
 foreach($steps as &$s){$s['available']=$input-(int)$s['good']-(int)$s['scrap']-(int)$s['reserved'];$input=(int)$s['good'];}unset($s);return $steps;
}
function route_start(PDO $p,int $group,int $machine,int $worker,int $n): void {
 $steps=route_steps($group);$id=positive('step_id');$qty=positive('batch_quantity');$step=null;foreach($steps as $s)if((int)$s['id']===$id)$step=$s;
 if(!$step||!in_array($machine,json_decode($step['machines'],true),true))throw new DomainException('Станок не подходит для этапа');
 if($qty>$step['available'])throw new DomainException('Доступно деталей: '.$step['available']);
 $p->prepare("INSERT INTO work_entries(machine_id,worker_id,group_id,part_number,started_at,quantity) VALUES(?,?,?,?,datetime('now'),?)")->execute([$machine,$worker,$group,$n,$qty]);$entry=(int)$p->lastInsertId();
 $p->prepare('INSERT INTO route_operations(entry_id,step_id,quantity) VALUES(?,?,?)')->execute([$entry,$id,$qty]);
 if($step['parents']!==null){$left=$qty;foreach($step['sources'] as $source=>$available){$take=min($left,$available);if($take>0){$p->prepare('INSERT INTO route_inputs(entry_id,source_position,quantity) VALUES(?,?,?)')->execute([$entry,$source,$take]);$left-=$take;}}if($left)throw new DomainException('Недостаточный остаток');}
}
function route_allocate_active_split(PDO $p,int $parent,array $children): void {
 $active=query("SELECT e.id,e.status,o.step_id,o.quantity,o.good,o.scrap,o.rework FROM work_entries e JOIN route_operations o ON o.entry_id=e.id WHERE e.group_id=? ORDER BY e.id",[$parent]);
 foreach($active as $op){$left=(int)$op['quantity'];foreach($children as $child){if($left<=0)break;$take=min($left,(int)$child['quantity']);if($take<=0)continue;$step=first('SELECT id FROM group_steps WHERE group_id=? AND position=(SELECT position FROM group_steps WHERE id=?)',[$child['id'],$op['step_id']]);if(!$step)throw new DomainException('Не найден этап маршрута подгруппы');$p->prepare('INSERT INTO route_operation_allocations(entry_id,group_id,step_id,quantity,good,scrap,rework) VALUES(?,?,?,?,?,?,?)')->execute([$op['id'],$child['id'],$step['id'],$take,min((int)$op['good'],$take),min((int)$op['scrap'],max(0,$take-(int)$op['good'])) ,min((int)$op['rework'],max(0,$take-(int)$op['good']-(int)$op['scrap']))]);$left-=$take;}if($left&&$op['status']==='active')throw new DomainException('Не удалось распределить активную операцию');}
}
function route_finish(PDO $p,array $entry): bool {
 $o=first('SELECT o.*,s.position FROM route_operations o JOIN group_steps s ON s.id=o.step_id WHERE o.entry_id=?',[$entry['id']]);if(!$o)return false;
 $values=[];foreach(['batch_good','batch_scrap','batch_rework'] as $key){$v=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($v===false)throw new DomainException('Укажите количество по каждому результату');$values[]=$v;}
 [$good,$scrap,$rework]=$values;if(array_sum($values)!==(int)$o['quantity'])throw new DomainException('Сумма результатов должна совпадать с резервом');
 $reason=$scrap||$rework?required('quality_reason'):null;$quality=$scrap?'scrap':($rework?'rework':'good');
  $allocs=query('SELECT * FROM route_operation_allocations WHERE entry_id=? ORDER BY id',[$entry['id']]);
 if($allocs&&$entry['status']==='active'){$leftGood=$good;$leftScrap=$scrap;$leftRework=$rework;$leftQty=(int)$o['quantity'];foreach($allocs as $ix=>$al){$last=$ix===count($allocs)-1;$q=(int)$al['quantity'];$g=$last?$leftGood:min($leftGood,(int)floor($leftGood*$q/$leftQty));$sc=$last?$leftScrap:min($leftScrap,(int)floor($leftScrap*$q/$leftQty));$rw=$last?$leftRework:min($leftRework,(int)floor($leftRework*$q/$leftQty));$leftGood-=$g;$leftScrap-=$sc;$leftRework-=$rw;$leftQty-=$q;$p->prepare('UPDATE route_operation_allocations SET good=?,scrap=?,rework=? WHERE id=?')->execute([$g,$sc,$rw,$al['id']]);}
  $p->prepare('UPDATE route_operations SET good=?,scrap=?,rework=? WHERE entry_id=?')->execute([$good,$scrap,$rework,$entry['id']]);}
 else{$p->prepare('UPDATE route_operations SET good=?,scrap=?,rework=? WHERE entry_id=?')->execute([$good,$scrap,$rework,$entry['id']]);}
 $p->prepare("UPDATE work_entries SET status='done',quality_result=?,quality_reason=?,ended_at=datetime('now'),duration_seconds=MAX(0,strftime('%s','now')-strftime('%s',started_at)) WHERE id=? AND status='active'")->execute([$quality,$reason,$entry['id']]);
 if(!first("SELECT id FROM work_entries WHERE id=? AND status='done'",[$entry['id']]))throw new DomainException('Операция не сохранена');
 $steps=route_steps((int)$entry['group_id']);$terminal=true;if($steps[0]['parents']!==null){foreach($steps as $s)if(in_array((int)$o['position'],json_decode($s['parents'],true),true))$terminal=false;}else $terminal=!first('SELECT id FROM group_steps WHERE group_id=? AND position>?',[$entry['group_id'],$o['position']]);
 if($terminal){if($allocs){foreach($allocs as $al){$done=first('SELECT good FROM route_operation_allocations WHERE id=?',[$al['id']]);credit_group($p,(int)$al['group_id'],(int)$done['good']);}}else credit_group($p,(int)$entry['group_id'],$good);}
 return true;
}

function route_graph_balances(int $group,array $steps): array {
 $g=first('SELECT quantity FROM part_groups WHERE id=?',[$group]);$pool=[0=>(int)$g['quantity']];foreach($steps as $s){$pos=(int)$s['position'];$pool[$pos]=(int)$s['good'];$pool[-$pos]=(int)$s['rework'];}
 foreach(query('SELECT i.source_position,SUM(i.quantity) used FROM route_inputs i JOIN work_entries e ON e.id=i.entry_id WHERE e.group_id=? GROUP BY i.source_position',[$group]) as $u)$pool[(int)$u['source_position']]-=(int)$u['used'];
 foreach(query('SELECT s.position,SUM(a.quantity) used FROM route_operation_allocations a JOIN group_steps s ON s.id=a.step_id WHERE a.group_id=? GROUP BY s.position',[$group]) as $u)$pool[(int)$u['position']-1]-=(int)$u['used'];
 foreach($steps as &$s){$pos=(int)$s['position'];$sources=[-$pos,...json_decode($s['parents'],true)];$s['sources']=[];foreach($sources as $source)$s['sources'][$source]=max(0,$pool[$source]??0);$s['available']=array_sum($s['sources']);if((int)$s['quota']>0)$s['available']=min($s['available'],max(0,(int)$s['quota']-(int)$s['good']-(int)$s['scrap']-(int)$s['reserved']));}unset($s);return $steps;
}
function route_archive(PDO $p): void {$p->prepare('UPDATE routes SET archived=1 WHERE id=?')->execute([positive('route_id')]);}
