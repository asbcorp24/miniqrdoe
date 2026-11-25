<?php
declare(strict_types=1);
require_once __DIR__.'/routes.php';
function db(): PDO {
 static $pdo;
 if(isset($pdo)) return $pdo;
 $dir=dirname(__DIR__).'/var';
 if(!is_dir($dir)) mkdir($dir,0770,true);
 $pdo=new PDO('sqlite:'.$dir.'/journal.sqlite');
 $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
 $pdo->exec("PRAGMA foreign_keys=ON; PRAGMA busy_timeout=8000; PRAGMA journal_mode=WAL");
 $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS machines(id INTEGER PRIMARY KEY,name TEXT NOT NULL,code TEXT NOT NULL UNIQUE,active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS workers(id INTEGER PRIMARY KEY,name TEXT NOT NULL,code TEXT NOT NULL UNIQUE,active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS part_groups(
 id INTEGER PRIMARY KEY,name TEXT NOT NULL,received_at TEXT NOT NULL,planned_at TEXT,
 quantity INTEGER NOT NULL CHECK(quantity>0),completed INTEGER NOT NULL DEFAULT 0 CHECK(completed>=0 AND completed<=quantity),
 status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed')),
 closed_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,parent_group_id INTEGER REFERENCES part_groups(id),prefix TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS work_entries(
 id INTEGER PRIMARY KEY,machine_id INTEGER NOT NULL REFERENCES machines(id),
 worker_id INTEGER NOT NULL REFERENCES workers(id),group_id INTEGER NOT NULL REFERENCES part_groups(id),
 part_number INTEGER NOT NULL CHECK(part_number>0),started_at TEXT NOT NULL,
 ended_at TEXT,duration_seconds INTEGER,
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','done','cancelled')),
 UNIQUE(group_id,part_number)
);
CREATE INDEX IF NOT EXISTS idx_work_group ON work_entries(group_id,status);
CREATE INDEX IF NOT EXISTS idx_work_worker ON work_entries(worker_id,status);
CREATE INDEX IF NOT EXISTS idx_work_machine_status ON work_entries(machine_id,status);
CREATE INDEX IF NOT EXISTS idx_work_machine ON work_entries(machine_id,started_at);
CREATE INDEX IF NOT EXISTS idx_work_date ON work_entries(started_at);
CREATE TABLE IF NOT EXISTS app_settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
SQL);
 $groupColumns=array_column($pdo->query('PRAGMA table_info(part_groups)')->fetchAll(),'name');
 foreach(['parent_group_id'=>'INTEGER REFERENCES part_groups(id)','prefix'=>"TEXT NOT NULL DEFAULT ''"] as $column=>$type)if(!in_array($column,$groupColumns,true))$pdo->exec("ALTER TABLE part_groups ADD COLUMN $column $type");
 $pdo->exec('CREATE INDEX IF NOT EXISTS idx_part_groups_parent ON part_groups(parent_group_id)');
 $columns=array_column($pdo->query('PRAGMA table_info(work_entries)')->fetchAll(),'name');
 $migratingBatchQuality=!in_array('batch_good',$columns,true);
 foreach(['quality_result'=>"TEXT NOT NULL DEFAULT 'good'",'quality_reason'=>'TEXT','rework_of'=>'INTEGER REFERENCES work_entries(id)','quantity'=>'INTEGER NOT NULL DEFAULT 1 CHECK(quantity>0)','batch_good'=>'INTEGER NOT NULL DEFAULT 0','batch_scrap'=>'INTEGER NOT NULL DEFAULT 0','batch_rework'=>'INTEGER NOT NULL DEFAULT 0'] as $column=>$type){if(!in_array($column,$columns,true)){try{$pdo->exec("ALTER TABLE work_entries ADD COLUMN $column $type");}catch(PDOException $e){if(!in_array($column,array_column($pdo->query('PRAGMA table_info(work_entries)')->fetchAll(),'name'),true))throw $e;}}}
 if($migratingBatchQuality)$pdo->exec("UPDATE work_entries SET batch_good=CASE WHEN status='done' THEN quantity ELSE 0 END,batch_scrap=CASE WHEN quality_result='scrap' THEN quantity ELSE 0 END,batch_rework=CASE WHEN quality_result='rework' THEN quantity ELSE 0 END");
 route_schema($pdo);
 // Convert stored legacy labels to numeric-only QR values without touching record IDs.
 // The old printed QR labels must be replaced after this one-time migration.
 foreach (['machines'=>'1','workers'=>'2'] as $table=>$prefix) {
  $rows=$pdo->query("SELECT id,code FROM $table")->fetchAll();
  $update=$pdo->prepare("UPDATE $table SET code=? WHERE id=?");
  foreach ($rows as $row) {
   if (preg_match('/^'.$prefix.'[0-9]{11}$/D',(string)$row['code'])) continue;
   $code=generate_qr_code($pdo,$table,$prefix);
   $update->execute([$code,(int)$row['id']]);
  }
 }
 return $pdo;
}
function app_setting(string $key,string $default='0'): string {$r=first('SELECT value FROM app_settings WHERE key=?',[$key]);return $r?(string)$r['value']:$default;}
function h(mixed $x): string {return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function query(string $sql,array $args=[]): array {$s=db()->prepare($sql);$s->execute($args);return $s->fetchAll();}
function first(string $sql,array $args=[]): ?array {return query($sql,$args)[0]??null;}
function token(): string {return $_SESSION['csrf']??=bin2hex(random_bytes(24));}
function positive(string $key): int {$v=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($v===false)throw new DomainException("Неверное число: $key");return $v;}
function required(string $key): string {$v=trim((string)($_POST[$key]??''));if($v===''||strlen($v)>360)throw new DomainException("Заполните поле: $key");return $v;}
function dateField(string $key,bool $optional=false): ?string {
 $s=trim((string)($_POST[$key]??''));
 if($optional&&$s==='')return null;
 $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$s);
 if(!$d||$d->format('Y-m-d\TH:i')!==$s)throw new DomainException('Неверная дата');
 return $d->format('Y-m-d H:i:s');
}
function generate_qr_code(PDO $pdo, string $table, string $prefix): string {
 // Server-side CSPRNG; do not trust a browser-supplied identifier.
 for ($attempt=0; $attempt<10; $attempt++) {
  $code=$prefix.str_pad((string)random_int(0,99999999999),11,'0',STR_PAD_LEFT);
  $stmt=$pdo->prepare("SELECT 1 FROM $table WHERE code=? LIMIT 1");
  $stmt->execute([$code]);
  if (!$stmt->fetchColumn()) return $code;
 }
 throw new RuntimeException('Не удалось сгенерировать уникальный QR-код');
}
function credit_group(PDO $pdo,int $groupId,int $good): void {
 if($good<=0)return;
 $current=$groupId;$first=true;
 while($current>0){
  $g=first('SELECT id,parent_group_id,quantity,completed,status FROM part_groups WHERE id=?',[$current]);
  if(!$g)throw new DomainException('Группа для учёта выпуска не найдена');
  $s=$pdo->prepare("UPDATE part_groups SET completed=completed+?,status=CASE WHEN completed+?=quantity AND NOT EXISTS(SELECT 1 FROM part_groups c WHERE c.parent_group_id=part_groups.id AND c.status<>'closed') THEN 'closed' ELSE 'open' END,closed_at=CASE WHEN completed+?=quantity THEN datetime('now') ELSE NULL END WHERE id=? AND completed+?<=quantity");
  $s->execute([$good,$good,$good,$current,$good]);
  if($s->rowCount()!==1)throw new DomainException('Количество годных превышает план группы '.$g['id']);
  $current=(int)($g['parent_group_id']??0);
 }
}
function perform(string $action): void {
 $pdo=db();
 if(in_array($action,['machine','worker'],true)){
  $table=$action==='machine'?'machines':'workers';
  $prefix=$action==='machine'?'1':'2';
  $pdo->prepare("INSERT INTO $table(name,code) VALUES(?,?)")->execute([required('name'),generate_qr_code($pdo,$table,$prefix)]);return;
 }
 if(in_array($action,['directory_delete','directory_restore'],true)){
  $kind=(string)($_POST['kind']??'');
  if(!in_array($kind,['machine','worker'],true))throw new DomainException('Неизвестный тип справочника');
  $table=$kind==='machine'?'machines':'workers';
  $column=$kind==='machine'?'machine_id':'worker_id';
  $title=$kind==='machine'?'Станок':'Работник';
  $id=positive('record_id');
  $pdo->exec('BEGIN IMMEDIATE');
  try {
   $record=first("SELECT id,active FROM $table WHERE id=?",[$id]);
   if(!$record)throw new DomainException('Запись не найдена');
   if($action==='directory_restore'){
    $pdo->prepare("UPDATE $table SET active=1 WHERE id=?")->execute([$id]);
    $_SESSION['success']=$title.' восстановлен в справочнике';
   }else{
    if(!(int)$record['active'])throw new DomainException('Запись уже находится в архиве');
    if(first("SELECT id FROM work_entries WHERE $column=? AND status='active' LIMIT 1",[$id]))
     throw new DomainException('Нельзя удалить запись, пока по ней есть незавершённая работа');
    if(first("SELECT id FROM work_entries WHERE $column=? LIMIT 1",[$id])){
     $pdo->prepare("UPDATE $table SET active=0 WHERE id=?")->execute([$id]);
     $_SESSION['success']=$title.' перенесён в архив — история операций сохранена';
    }else{
     $pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
     $_SESSION['success']=$title.' удалён из справочника';
    }
   }
   $pdo->exec('COMMIT');return;
  }catch(Throwable $e){try {$pdo->exec('ROLLBACK');}catch(Throwable $rollbackError){error_log('SQLite rollback failed: '.$rollbackError->getMessage());}throw $e;}
 }
 if($action==='save_settings'){admin_require();$pdo->prepare('INSERT INTO app_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute(['finish_worker_scan',isset($_POST['finish_worker_scan'])?'1':'0']);$_SESSION['success']='Настройки сохранены';return;}
 if($action==='route_archive'){route_archive($pdo);return;}
 if($action==='route_create'){route_create($pdo);return;}
 if($action==='group'){
  if(!empty($_POST['route_id'])){route_group($pdo);return;}
  $pdo->prepare('INSERT INTO part_groups(name,received_at,planned_at,quantity) VALUES(?,?,?,?)')->execute([required('name'),dateField('received_at'),dateField('planned_at',true),positive('quantity')]);return;
 }
 if($action==='group_split'){
  $group=positive('group_id');$raw=$_POST['allocations']??'';if(!is_string($raw)||strlen($raw)>12000)throw new DomainException('Неверный список подгрупп');
  $lines=preg_split('/\\R/u',trim($raw));$alloc=[];$sum=0;
  foreach($lines as $line){if(trim($line)==='')continue;$parts=array_map('trim',explode(',',$line));if(count($parts)!==2||!preg_match('/^[\\p{L}\\p{N}][\\p{L}\\p{N}._-]{0,19}$/u',$parts[0]))throw new DomainException('Формат строки: префикс, количество (например A, 120)');
   $qty=filter_var($parts[1],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($qty===false)throw new DomainException('Количество каждой подгруппы должно быть положительным');
   $alloc[]=['prefix'=>$parts[0],'quantity'=>$qty];$sum+=$qty;
  }
  if(count($alloc)<2)throw new DomainException('Для деления укажите минимум две подгруппы');
  if(count($alloc)>100)throw new DomainException('За одно деление можно создать не более 100 подгрупп');
  if(count(array_unique(array_column($alloc,'prefix')))!==count($alloc))throw new DomainException('Префиксы подгрупп должны отличаться');
  $pdo->exec('BEGIN IMMEDIATE');
  try{
   $g=first('SELECT * FROM part_groups WHERE id=?',[$group]);if(!$g||$g['status']!=='open')throw new DomainException('Группа закрыта или не найдена');
   if(first('SELECT id FROM part_groups WHERE parent_group_id=? LIMIT 1',[$group]))throw new DomainException('Эта группа уже разделена. Выберите конечную подгруппу.');
    $reserved=(int)(first("SELECT COALESCE(SUM(quantity),0) n FROM work_entries WHERE group_id=? AND status='active'",[$group])['n']??0);
   $available=(int)$g['quantity']-(int)$g['completed'];
   if($available<=0)throw new DomainException('Нет свободных деталей для распределения');
   if($sum!==$available)throw new DomainException('Распределите ровно '.$available.' свободных деталей. Введено: '.$sum);
   $steps=query('SELECT * FROM group_steps WHERE group_id=? ORDER BY position',[$group]);
    $children=[];$insert=$pdo->prepare('INSERT INTO part_groups(name,received_at,planned_at,quantity,parent_group_id,prefix) VALUES(?,?,?,?,?,?)');
   $copy=$pdo->prepare('INSERT INTO group_steps(group_id,position,name,machines,parents,quota,norm_seconds,instruction) VALUES(?,?,?,?,?,?,?,?)');
   foreach($alloc as $a){
    $name=$g['name'].'-'.$a['prefix'];if(first('SELECT id FROM part_groups WHERE name=?',[$name]))throw new DomainException('Группа с названием '.$name.' уже существует');
    $insert->execute([$name,$g['received_at'],$g['planned_at'],$a['quantity'],$group,$a['prefix']]);$child=(int)$pdo->lastInsertId();
    foreach($steps as $s)$copy->execute([$child,$s['position'],$s['name'],$s['machines'],$s['parents'],$s['quota'],$s['norm_seconds'],$s['instruction']]);
    $children[]=['id'=>$child,'quantity'=>$a['quantity']];
   }
   if($steps)route_allocate_active_split($pdo,$group,$children);
   $pdo->exec('COMMIT');$_SESSION['success']='Группа разделена на '.count($alloc).' подгруппы. Распределено '.$sum.' шт.';return;
  }catch(Throwable $e){try{$pdo->exec('ROLLBACK');}catch(Throwable $x){}throw $e;}
 }
 if($action==='start'){
  $machine=positive('machine_id');$worker=positive('worker_id');$group=positive('group_id');
  $pdo->exec('BEGIN IMMEDIATE');
  try {
   if(!first('SELECT id FROM machines WHERE id=? AND active=1',[$machine])||!first('SELECT id FROM workers WHERE id=? AND active=1',[$worker])) throw new DomainException('Неизвестный станок или работник');
   $busy=first("SELECT e.id,w.name worker FROM work_entries e LEFT JOIN workers w ON w.id=e.worker_id WHERE e.machine_id=? AND e.status='active' LIMIT 1",[$machine]);
   if($busy)throw new DomainException('Станок уже в работе (операция №'.$busy['id'].', работник: '.($busy['worker']??'неизвестен').'). Сначала завершите предыдущую операцию.');
   $g=first('SELECT * FROM part_groups WHERE id=?',[$group]);
   if(!$g||$g['status']!=='open')throw new DomainException('Группа закрыта или не найдена');
   if(first('SELECT id FROM part_groups WHERE parent_group_id=? LIMIT 1',[$group]))throw new DomainException('Группа разделена. Выберите конечную подгруппу.');
   if(first("SELECT id FROM work_entries WHERE worker_id=? AND status='active'",[$worker]))throw new DomainException('Работник уже выполняет операцию');
   $n=(int)(first('SELECT MAX(part_number+quantity-1) n FROM work_entries WHERE group_id=?',[$group])['n']??0)+1;
   $qty=positive('batch_quantity');
   if(route_steps($group)){route_start($pdo,$group,$machine,$worker,$n);$pdo->exec('COMMIT');return;}
   $reserved=(int)(first("SELECT COALESCE(SUM(quantity),0) n FROM work_entries WHERE group_id=? AND status='active'",[$group])['n']??0);
   $remaining=(int)$g['quantity']-(int)$g['completed']-$reserved;
   if($qty>$remaining)throw new DomainException('Доступно для запуска: '.$remaining.' шт.');
   $rework=(int)($_POST['rework_of']??0);
   if($rework && $qty!==1)throw new DomainException('Повторная обработка одной детали запускается отдельно');
   if($rework && !first("SELECT id FROM work_entries e WHERE id=? AND group_id=? AND status='cancelled' AND quality_result='rework' AND NOT EXISTS(SELECT 1 FROM work_entries r WHERE r.rework_of=e.id)",[$rework,$group]))throw new DomainException('Доработка недоступна или уже начата');
   $pdo->prepare("INSERT INTO work_entries(machine_id,worker_id,group_id,part_number,started_at,rework_of,quantity) VALUES(?,?,?,?,datetime('now'),?,?)")->execute([$machine,$worker,$group,$n,$rework?:null,$qty]);
   $pdo->exec('COMMIT');return;
  }catch(Throwable $e){try {$pdo->exec('ROLLBACK');}catch(Throwable $rollbackError){error_log('SQLite rollback failed: '.$rollbackError->getMessage());}throw $e;}
 }
 if($action==='finish'){
  $id=positive('entry_id');
  $workerCode=trim((string)($_POST['worker_code']??''));
  $requireWorkerScan=app_setting('finish_worker_scan','1')!=='0';
  if($requireWorkerScan&&!preg_match('/^2[0-9]{11}$/D',$workerCode))throw new DomainException('Для завершения отсканируйте QR-код работника');
  $pdo->exec('BEGIN IMMEDIATE');
  try {
   $entry=first('SELECT e.*,w.code worker_code FROM work_entries e JOIN workers w ON w.id=e.worker_id WHERE e.id=?',[$id]);
   if(!$entry||$entry['status']!=='active')throw new DomainException('Работа уже закрыта');
   if($requireWorkerScan&&!hash_equals((string)$entry['worker_code'],$workerCode))throw new DomainException('QR-код не соответствует работнику, начавшему работу');
   if(route_finish($pdo,$entry)){$pdo->exec('COMMIT');return;}
   $total=(int)($entry['quantity']??1);
   if($total>1){
    $good=filter_var($_POST['batch_good']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    $scrap=filter_var($_POST['batch_scrap']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    $rework=filter_var($_POST['batch_rework']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if($good===false||$scrap===false||$rework===false||$good+$scrap+$rework!==$total)throw new DomainException('Сумма годных, брака и доработки должна равняться '.$total.' шт.');
   }else{
    $quality=(string)($_POST['quality_result']??'good');
    if(!in_array($quality,['good','scrap','rework'],true))throw new DomainException('Неизвестный результат');
    $good=$quality==='good'?1:0;$scrap=$quality==='scrap'?1:0;$rework=$quality==='rework'?1:0;
   }
   $quality=$good===$total?'good':($scrap===$total?'scrap':($rework===$total?'rework':'mixed'));
   $reason=($scrap+$rework)>0?required('quality_reason'):null;
   $finalStatus=$good>0?'done':'cancelled';
   $finish=$pdo->prepare("UPDATE work_entries SET status=?,quality_result=?,quality_reason=?,batch_good=?,batch_scrap=?,batch_rework=?,ended_at=datetime('now'),duration_seconds=MAX(0,strftime('%s','now')-strftime('%s',started_at)) WHERE id=? AND status='active'");
   $finish->execute([$finalStatus,$quality,$reason,$good,$scrap,$rework,$id]);
   if($finish->rowCount()!==1)throw new DomainException('Не удалось завершить операцию: запись не обновлена');
   if($good>0){
    credit_group($pdo,(int)$entry['group_id'],$good);
   }
   // A legacy SQLite trigger may delete or alter the row on UPDATE.
   // Check before commit, so a disappearing operation rolls back completely.
   $saved=first('SELECT id,status FROM work_entries WHERE id=?',[$id]);
   if(!$saved||$saved['status']!==$finalStatus)
    throw new DomainException('Операция исчезла при завершении. Изменения отменены; проверьте триггеры SQLite.');
   $database=first('PRAGMA database_list');
   $pdo->exec('COMMIT');
   $_SESSION['finish_check']=['id'=>$id,'status'=>$finalStatus,'db_file'=>(string)($database['file']??'')];
   return;
  }catch(Throwable $e){try {$pdo->exec('ROLLBACK');}catch(Throwable $rollbackError){error_log('SQLite rollback failed: '.$rollbackError->getMessage());}throw $e;}
 }
 throw new DomainException('Недопустимое действие');
}
function period(): array {
 $a=(string)($_GET['from']??date('Y-m-01'));$b=(string)($_GET['to']??date('Y-m-d'));
 foreach([$a,$b] as $d)if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)||!DateTimeImmutable::createFromFormat('!Y-m-d',$d))throw new DomainException('Неправильный период');
 if($b<$a)throw new DomainException('Дата окончания раньше начала');
 return [$a,$b,$a.' 00:00:00',(new DateTimeImmutable($b))->modify('+1 day')->format('Y-m-d').' 00:00:00'];
}
function exportCsv(): never {
 [$a,$b,$from,$to]=period();
 $data=query("SELECT e.id,COALESCE(m.name,'Станок #' || e.machine_id) machine,COALESCE(w.name,'Работник #' || e.worker_id) worker,COALESCE(g.name,'Группа #' || e.group_id) group_name,e.part_number,e.started_at,e.ended_at,e.duration_seconds,e.status,e.quality_result,e.quality_reason,e.rework_of,rs.name route_step,COALESCE(ro.quantity,e.quantity) quantity,COALESCE(ro.good,e.batch_good) good,COALESCE(ro.scrap,e.batch_scrap) scrap,COALESCE(ro.rework,e.batch_rework) rework FROM work_entries e LEFT JOIN machines m ON m.id=e.machine_id LEFT JOIN workers w ON w.id=e.worker_id LEFT JOIN part_groups g ON g.id=e.group_id LEFT JOIN route_operations ro ON ro.entry_id=e.id LEFT JOIN group_steps rs ON rs.id=ro.step_id WHERE e.started_at>=? AND e.started_at<? ORDER BY e.id DESC",[$from,$to]);
 header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="miniqrdoe.csv"');
 $f=fopen('php://output','wb');fwrite($f,"\xEF\xBB\xBF");
 fputcsv($f,['ID','Станок','Работник','Группа','Номер детали','Начало UTC','Окончание UTC','Секунды','Статус','Результат','Причина','Доработка операции','Этап','Количество','Годные','Брак','Доработка'],';');
 foreach($data as $r)fputcsv($f,array_values($r),';');
 fclose($f);exit;
}
