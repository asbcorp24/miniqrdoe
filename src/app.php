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
function perform(string $action): void {
 $pdo=db();
 if(in_array($action,['machine','worker'],true)){
  $table=$action==='machine'?'machines':'workers';
  $pdo->prepare("INSERT INTO $table(name,code) VALUES(?,?)")->execute([required('name'),required('code')]);return;
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
  $id=positive('entry_id');$pdo->exec('BEGIN IMMEDIATE');
  try {
   $entry=first('SELECT * FROM work_entries WHERE id=?',[$id]);
   if(!$entry||$entry['status']!=='active')throw new DomainException('Работа уже закрыта');
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
