<?php
declare(strict_types=1);

// CLI only: never expose database internals through a public HTTP URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'journal.sqlite';
echo "MiniQRDOE SQLite diagnostics (read-only)\n";
echo "Database: " . $file . "\n";
if (!is_file($file)) {
    fwrite(STDERR, "Database file not found. The application may be using another installation.\n");
    exit(1);
}
echo "Size (bytes): " . filesize($file) . "\n";
echo "Last modification: " . date('Y-m-d H:i:s', (int)filemtime($file)) . "\n";

try {
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA query_only=ON');
    $pdo->exec('PRAGMA busy_timeout=3000');

    echo "SQLite: " . $pdo->query('SELECT sqlite_version()')->fetchColumn() . "\n";
    foreach ($pdo->query('PRAGMA database_list') as $db) {
        echo "Attached DB [" . $db['name'] . "]: " . $db['file'] . "\n";
    }

    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='work_entries'")->fetchColumn();
    if (!$exists) {
        echo "Table work_entries does not exist in this file.\n";
        exit(1);
    }

    echo "\nEntries by status:\n";
    $total = 0;
    foreach ($pdo->query('SELECT status, COUNT(*) count FROM work_entries GROUP BY status ORDER BY status') as $row) {
        echo "  " . $row['status'] . ": " . $row['count'] . "\n";
        $total += (int)$row['count'];
    }
    echo "Total: $total\n";

    echo "\nLast 15 work entries:\n";
    foreach ($pdo->query('SELECT id,machine_id,worker_id,group_id,part_number,status,started_at,ended_at FROM work_entries ORDER BY id DESC LIMIT 15') as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    echo "\nSQLite triggers (all tables):\n";
    $triggers = $pdo->query("SELECT name,tbl_name,sql FROM sqlite_master WHERE type='trigger' ORDER BY name")->fetchAll();
    if (!$triggers) echo "  (none)\n";
    foreach ($triggers as $trigger) {
        echo "  " . $trigger['name'] . " ON " . $trigger['tbl_name'] . ":\n" . $trigger['sql'] . "\n";
    }

    echo "\nIntegrity check: " . $pdo->query('PRAGMA quick_check')->fetchColumn() . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Diagnostic failed: " . $e->getMessage() . "\n");
    exit(1);
}
