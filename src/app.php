<?php
declare(strict_types=1);
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
 closed_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
CREATE INDEX IF NOT EXISTS idx_work_machine ON work_entries(machine_id,started_at);
CREATE INDEX IF NOT EXISTS idx_work_date ON work_entries(started_at);
SQL);
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
   $pdo->commit();return;
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
 }
 if($action==='group'){
  $pdo->prepare('INSERT INTO part_groups(name,received_at,planned_at,quantity) VALUES(?,?,?,?)')->execute([required('name'),dateField('received_at'),dateField('planned_at',true),positive('quantity')]);return;
 }
 if($action==='start'){
  $machine=positive('machine_id');$worker=positive('worker_id');$group=positive('group_id');
  $pdo->exec('BEGIN IMMEDIATE');
  try {
   if(!first('SELECT id FROM machines WHERE id=? AND active=1',[$machine])||!first('SELECT id FROM workers WHERE id=? AND active=1',[$worker])) throw new DomainException('Неизвестный станок или работник');
   $g=first('SELECT * FROM part_groups WHERE id=?',[$group]);
   if(!$g||$g['status']!=='open')throw new DomainException('Группа закрыта или не найдена');
   if(first("SELECT id FROM work_entries WHERE worker_id=? AND status='active'",[$worker]))throw new DomainException('Работник уже выполняет операцию');
   $n=(int)(first('SELECT MAX(part_number) n FROM work_entries WHERE group_id=?',[$group])['n']??0)+1;
   if($n>(int)$g['quantity'])throw new DomainException('Все номера деталей заняты');
   $pdo->prepare("INSERT INTO work_entries(machine_id,worker_id,group_id,part_number,started_at) VALUES(?,?,?,?,datetime('now'))")->execute([$machine,$worker,$group,$n]);
   $pdo->commit();return;
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
 }
 if($action==='finish'){
  $id=positive('entry_id');
  $workerCode=trim((string)($_POST['worker_code']??''));
  if(!preg_match('/^2[0-9]{11}$/D',$workerCode))throw new DomainException('Для завершения отсканируйте QR-код работника');
  $pdo->exec('BEGIN IMMEDIATE');
  try {
   $entry=first('SELECT e.*,w.code worker_code FROM work_entries e JOIN workers w ON w.id=e.worker_id WHERE e.id=?',[$id]);
   if(!$entry||$entry['status']!=='active')throw new DomainException('Работа уже закрыта');
   if(!hash_equals((string)$entry['worker_code'],$workerCode))throw new DomainException('QR-код не соответствует работнику, начавшему работу');
   $pdo->prepare("UPDATE work_entries SET status='done',ended_at=datetime('now'),duration_seconds=MAX(0,strftime('%s','now')-strftime('%s',started_at)) WHERE id=? AND status='active'")->execute([$id]);
   $s=$pdo->prepare("UPDATE part_groups SET completed=completed+1 WHERE id=? AND status='open' AND completed<quantity");
   $s->execute([$entry['group_id']]);
   if($s->rowCount()!==1)throw new DomainException('Группа уже закрыта');
   $pdo->prepare("UPDATE part_groups SET status='closed',closed_at=datetime('now') WHERE id=? AND completed=quantity")->execute([$entry['group_id']]);
   $pdo->commit();return;
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
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
 $data=query("SELECT e.id,m.name machine,w.name worker,g.name group_name,e.part_number,e.started_at,e.ended_at,e.duration_seconds,e.status FROM work_entries e JOIN machines m ON m.id=e.machine_id JOIN workers w ON w.id=e.worker_id JOIN part_groups g ON g.id=e.group_id WHERE e.started_at>=? AND e.started_at<? ORDER BY e.id DESC",[$from,$to]);
 header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="miniqrdoe.csv"');
 $f=fopen('php://output','wb');fwrite($f,"\xEF\xBB\xBF");
 fputcsv($f,['ID','Станок','Работник','Группа','Номер детали','Начало UTC','Окончание UTC','Секунды','Статус'],';');
 foreach($data as $r)fputcsv($f,array_values($r),';');
 fclose($f);exit;
}
