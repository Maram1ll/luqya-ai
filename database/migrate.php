<?php
// Luqya AI – automatic database migration runner
// ---------------------------------------------------------------------------
// Applies database/schema.sql to the environment-configured database (the one
// resolved in config/db.php: .env, Railway MYSQL_URL/MYSQLHOST..., or
// config/config.local.php), then aligns older tables via ensureSchemaUpgrades().
//
// Safe to run repeatedly:
//   * DDL uses CREATE TABLE IF NOT EXISTS (no-ops when tables already exist).
//   * Seed (demo) rows are inserted ONLY into a pristine database, so they are
//     never duplicated on re-runs and real data is never overwritten.
//   * CREATE DATABASE / USE statements are skipped: the PDO DSN already
//     targets DB_NAME (the Railway database), so tables are created inside the
//     configured schema rather than a hardcoded `luqya_ai`.
//
// Entry points:
//   * Container/CLI:   php database/migrate.php [--quiet]
//                      (also invoked automatically by the Docker entrypoint
//                       before Apache starts, with retries).
//   * Safe web route:  /database/migrate.php  – allowed only while the
//                      database is empty; refuses once users exist unless
//                      ALLOW_DB_RESET=1 is set.
// ---------------------------------------------------------------------------

require __DIR__ . '/../config/db.php';

$isCli          = (PHP_SAPI === 'cli');
$args           = isset($argv) ? $argv : [];
$quiet          = in_array('--quiet', $args, true);
$forceCliOrWeb  = ($isCli || getenv('ALLOW_DB_RESET') === '1') && in_array('--force', $args, true);

// Web safety guard: block re-runs once the database already holds data.
if (!$isCli && !$forceCliOrWeb) {
    header('Content-Type: text/plain; charset=utf-8');
    try {
        $probe = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $existingUsers = (int) $probe->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($existingUsers > 0) {
            http_response_code(403);
            echo "Migration refused: the database is already initialised (users found).\n"
               . "First-time setup only runs on an empty database. To force a rebuild "
               . "set ALLOW_DB_RESET=1 or use the CLI.\n";
            exit(0);
        }
    } catch (Throwable $e) {
        // Database missing/empty -> first-time setup is allowed.
    }
}

/**
 * Splits an SQL text into executable statements, ignoring line comments, block
 * comments, semicolons inside quoted strings (', ", `) and backslash escapes.
 * Behaviour identical to the web installer's splitter, kept self-contained so
 * the migration runner never depends on setup_db.php.
 *
 * @return array<int,string>
 */
function migrateSplitStatements(string $sql): array {
    $statements = [];
    $current    = '';
    $len        = strlen($sql);
    $inSingle   = false;
    $inDouble   = false;
    $inBacktick = false;

    for ($i = 0; $i < $len; $i++) {
        $ch   = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inSingle || $inDouble || $inBacktick) {
            $current .= $ch;
            if ($ch === '\\' && $next !== '') {
                $current .= $next;
                $i++;
                continue;
            }
            if ($inSingle   && $ch === "'")  { $inSingle   = false; continue; }
            if ($inDouble   && $ch === '"')  { $inDouble   = false; continue; }
            if ($inBacktick && $ch === '`')  { $inBacktick = false; continue; }
            continue;
        }

        // Line comments (-- or #)
        if (($ch === '-' && $next === '-') || $ch === '#') {
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            $current .= "\n";
            continue;
        }

        // Block comments
        if ($ch === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i   = ($end === false) ? $len : $end + 1;
            $current .= ' ';
            continue;
        }

        if ($ch === "'")  { $inSingle   = true; $current .= $ch; continue; }
        if ($ch === '"')  { $inDouble   = true; $current .= $ch; continue; }
        if ($ch === '`')  { $inBacktick = true; $current .= $ch; continue; }

        if ($ch === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') { $statements[] = $trimmed; }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') { $statements[] = $trimmed; }

    return $statements;
}

function migrateLog(string $msg, bool $quiet): void {
    if (!$quiet) { fwrite(STDOUT, $msg . "\n"); }
}

$schemaFile = __DIR__ . '/schema.sql';
if (!is_file($schemaFile)) {
    migrateLog('ERROR: schema.sql not found at ' . $schemaFile, $quiet);
    exit(1);
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
} catch (Throwable $e) {
    migrateLog('ERROR: cannot connect to the configured database: ' . $e->getMessage(), $quiet);
    exit(1);
}

// Detect whether the database is pristine (no tracked table exists yet).
$tables = ['users', 'reports', 'matches', 'notifications', 'activity_logs', 'password_resets'];
$fresh  = true;
try {
    foreach ($tables as $t) {
        $found = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = ' . $pdo->quote(DB_NAME)
            . ' AND table_name = ' . $pdo->quote($t)
        )->fetchColumn();
        if ($found > 0) { $fresh = false; break; }
    }
} catch (Throwable $e) {
    $fresh = true;
}

$sql         = file_get_contents($schemaFile);
$statements  = migrateSplitStatements($sql);
$dirty       = false;
$seedApplied = false;

foreach ($statements as $stmt) {
    $head = strtoupper(substr(ltrim($stmt), 0, 24));

    // CREATE DATABASE / USE are skipped: the DSN already targets DB_NAME, so
    // tables are created directly inside the configured (Railway) database.
    if (str_starts_with($head, 'CREATE DATABASE') || str_starts_with($head, 'USE ')) {
        continue;
    }

    $isSeed = str_starts_with($head, 'INSERT INTO');

    // Seed rows only into a pristine database (no duplicates, no overwrites).
    if ($isSeed && !$fresh) {
        continue;
    }

    try {
        $pdo->exec($stmt);
        if ($isSeed) { $seedApplied = true; }
    } catch (Throwable $e) {
        if ($isSeed) {
            migrateLog('WARNING: seed skipped (' . $e->getMessage() . ')', $quiet);
        } else {
            $dirty = true;
            migrateLog(
                'ERROR: ' . $e->getMessage()
                . "\n  statement: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 180),
                $quiet
            );
        }
    }
}

// Align any pre-existing tables with the schema.sql definitions.
ensureSchemaUpgrades($pdo);

migrateLog(
    $dirty
        ? 'Migration finished WITH ERRORS (fresh=' . ($fresh ? 'yes' : 'no') . ', seeded=' . ($seedApplied ? 'yes' : 'no') . ').'
        : 'Migration OK (fresh=' . ($fresh ? 'yes' : 'no') . ', seeded=' . ($seedApplied ? 'yes' : 'no') . ').',
    $quiet
);

exit($dirty ? 1 : 0);