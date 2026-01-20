<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__);
$companiesFile = $baseDir . '/data/companies.php';
$migrationsDir = $baseDir . '/migrations';

if (!file_exists($companiesFile)) {
    fwrite(STDERR, "Missing companies file: {$companiesFile}\n");
    exit(1);
}

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Missing migrations directory: {$migrationsDir}\n");
    exit(1);
}

$companies = require $companiesFile;
if (!is_array($companies) || !$companies) {
    fwrite(STDERR, "No companies configured in {$companiesFile}\n");
    exit(1);
}

$files = glob($migrationsDir . '/*.sql');
if (!$files) {
    fwrite(STDERR, "No migrations found in {$migrationsDir}\n");
    exit(1);
}
sort($files, SORT_STRING);

$exitCode = 0;
foreach ($companies as $nif => $cfg) {
    $label = $cfg['slug'] ?? (string) $nif;
    if (!empty($cfg['skip_migrations'])) {
        echo "[{$label}] Skipped (skip_migrations)\n";
        continue;
    }
    $dsn = buildDsn($cfg);
    try {
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable $e) {
        fwrite(STDERR, "[{$label}] DB connection failed: {$e->getMessage()}\n");
        $exitCode = 1;
        continue;
    }

    ensureMigrationsTable($pdo);
    $applied = getAppliedMigrations($pdo);
    $appliedCount = 0;

    foreach ($files as $file) {
        $filename = basename($file);
        if (isset($applied[$filename])) {
            continue;
        }
        $sql = file_get_contents($file);
        $statements = splitSqlStatements($sql);
        if (!$statements) {
            markMigrationApplied($pdo, $filename);
            continue;
        }

        try {
            $startedTransaction = $pdo->beginTransaction();
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            markMigrationApplied($pdo, $filename);
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            $appliedCount++;
            echo "[{$label}] Applied {$filename}\n";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (shouldSkipMigrationError($e)) {
                markMigrationApplied($pdo, $filename);
                fwrite(STDERR, "[{$label}] Skipped {$filename}: {$e->getMessage()}\n");
                continue;
            }
            fwrite(STDERR, "[{$label}] Failed {$filename}: {$e->getMessage()}\n");
            $exitCode = 1;
            break;
        }
    }

    if ($appliedCount === 0) {
        echo "[{$label}] No new migrations\n";
    }
}

exit($exitCode);

function buildDsn(array $cfg): string {
    $host = $cfg['db_host'] ?? 'localhost';
    $port = isset($cfg['db_port']) && $cfg['db_port'] !== '' ? ';port=' . $cfg['db_port'] : '';
    $db = $cfg['db_name'] ?? '';
    $socket = isset($cfg['db_socket']) && $cfg['db_socket'] !== '' ? ';unix_socket=' . $cfg['db_socket'] : '';
    return "mysql:host={$host}{$port}{$socket};dbname={$db};charset=utf8mb4";
}

function ensureMigrationsTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function getAppliedMigrations(PDO $pdo): array {
    $stmt = $pdo->query('SELECT filename FROM migrations');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return array_fill_keys($rows, true);
}

function markMigrationApplied(PDO $pdo, string $filename): void {
    $stmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
    $stmt->execute([$filename]);
}

function splitSqlStatements(string $sql): array {
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $lines = explode("\n", $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
            continue;
        }
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);
    $parts = array_map('trim', explode(';', $sql));
    $statements = [];
    foreach ($parts as $part) {
        if ($part !== '') {
            $statements[] = $part;
        }
    }
    return $statements;
}

function shouldSkipMigrationError(Throwable $e): bool {
    if (!$e instanceof PDOException) {
        return false;
    }
    $code = $e->errorInfo[1] ?? null;
    return in_array($code, [1050, 1060, 1091, 1146], true);
}
