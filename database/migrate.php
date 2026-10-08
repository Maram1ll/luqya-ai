<?php
// Luqya AI – automatic database migration runner
// ---------------------------------------------------------------------------
// Applies database/schema.sql to the environment-configured database (the one
// resolved in config/db.php: .env, Railway MYSQL_URL/MYSQLHOST..., or
// config/config.local.php), then aligns older tables via ensureSchemaUpgrades().
//
// Safe to run repeatedly:
//   * DDL uses CREATE TABLE IF NOT EXISTS (no-ops when tables already exist).
//   * Seed (demo) rows are inserted into each EMPTY table only, so they are
//     never duplicated on re-runs, real data is never overwritten, and a
//     database whose tables exist but hold no rows (e.g. one table auto-created
//     earlier by the app before the migration existed) still gets fully seeded
//     on the next boot. TRUNCATE / DELETE reset statements in the dump are
//     NEVER run at boot — they would wipe user data on every deploy.
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

/**
 * Returns the number of rows a table currently holds.
 * @return int row count, or -1 when the table does not exist / cannot be read.
 */
function migrateTableRowCount(PDO $pdo, string $table): int {
    try {
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $stmt = $pdo->query('SELECT COUNT(*) FROM `' . $safe . '`');
        return $stmt ? (int) $stmt->fetchColumn() : -1;
    } catch (Throwable $e) {
        return -1;
    }
}

/**
 * Extracts the target table name of an INSERT statement.
 */
function migrateSeedTable(string $stmt): string {
    if (preg_match('/^INSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?/i', ltrim($stmt), $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Parses the demo users INSERT statement (schema.sql) into a map of the
 * canonical demo user id => university_id, e.g. [1 => 'SEC-USR-1', ...].
 *
 * @return array<int,string>
 */
function migrateDemoUserUniversities(string $stmt): array {
    $map = [];
    if (preg_match('/\)\s*VALUES\s*(.*)$/is', $stmt, $m)) {
        if (preg_match_all('/\(\s*([0-9]+)\s*,\s*(\x27[^\x27]*\x27|\x22[^\x22]*\x22),/is', $m[1], $rows)) {
            foreach ($rows[1] as $i => $_id) {
                $map[(int) $_id] = trim($rows[2][$i], "'\"");
            }
        }
    }
    return $map;
}

/**
 * Rewrites the user_id column of a multi-row demo seed (reports/notifications)
 * from the schema's canonical demo user ids (1..8) to the ids the demo
 * accounts actually hold in the target database.
 *
 * Background: the app auto-provisions demo officers (ensureSecurityOfficers) on
 * any login attempt, so on a fresh deploy the demo accounts can exist under
 * ids other than the canonical 1..8 (e.g. 24..31). Seeding reports/
 * notifications with canonical user ids then violates the users FK and the
 * whole demo seed dies on every boot (the NULL user ids are matched by the
 * UNIQUE university_id, switching only when the account exists elsewhere).
 */
function migrateRemapSeedUserColumn(PDO $pdo, string $stmt, string $usersStmt): string {
    $pos = stripos($stmt, 'VALUES');
    if ($pos === false) {
        return $stmt;
    }

    $actual = [];
    foreach (migrateDemoUserUniversities($usersStmt) as $canonicalId => $universityId) {
        $query = $pdo->prepare('SELECT id FROM users WHERE university_id = ?');
        $query->execute([$universityId]);
        $real = $query->fetchColumn();
        if ($real !== false) { $actual[$canonicalId] = (int) $real; }
    }
    if (!$actual) {
        return $stmt;
    }

    $head = substr($stmt, 0, $pos + 6);
    $body = substr($stmt, $pos + 6);
    $out  = '';
    $len  = strlen($body);
    $i    = 0;
    $inS = $inD = false;
    $depth = 0;
    $col   = 0;

    while ($i < $len) {
        $c = $body[$i];

        if ($inS) {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $len) { $out .= $body[$i + 1]; $i += 2; continue; }
            if ($c === "'") { $inS = false; }
            $i++;
            continue;
        }
        if ($inD) {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $len) { $out .= $body[$i + 1]; $i += 2; continue; }
            if ($c === '"') { $inD = false; }
            $i++;
            continue;
        }

        if ($c === "'") { $inS = true; $out .= $c; $i++; continue; }
        if ($c === '"') { $inD = true; $out .= $c; $i++; continue; }
        if ($c === '(') { $depth++; if ($depth === 1) { $col = 0; } $out .= $c; $i++; continue; }
        if ($c === ')') { $depth--; $out .= $c; $i++; continue; }
        if ($c === ',' && $depth === 1) { $col++; $out .= $c; $i++; continue; }

        if ($depth === 1 && $col === 1) {
            $j = $i;
            while ($j < $len && $body[$j] !== ',' && $body[$j] !== ')') { $j++; }
            $token = ltrim(substr($body, $i, $j - $i));
            $canon = (int) $token;
            $out  .= isset($actual[$canon]) ? (string) $actual[$canon] : $token;
            $i = $j;
            continue;
        }

        $out .= $c;
        $i++;
    }

    return $head . $out;
}

$sql         = file_get_contents($schemaFile);
$statements  = migrateSplitStatements($sql);
$dirty       = false;
$seeded      = [];
$demoUsersStmt = '';

foreach ($statements as $stmt) {
    $head = strtoupper(substr(ltrim($stmt), 0, 24));

    // Skipped, never executed at boot:
    //   * CREATE DATABASE / USE  — the DSN already targets DB_NAME, so tables
    //     are created directly inside the configured (Railway) database.
    //   * TRUNCATE TABLE / DELETE FROM  — destructive "reset the database"
    //     statements from schema.sql. Running them on every boot would wipe
    //     real user data on each deploy; seeding is idempotent instead.
    if (str_starts_with($head, 'CREATE DATABASE')
        || str_starts_with($head, 'USE ')
        || str_starts_with($head, 'TRUNCATE TABLE')
        || str_starts_with($head, 'DELETE FROM')) {
        continue;
    }

    $isSeed = str_starts_with($head, 'INSERT INTO');

    if ($isSeed) {
        $seedTable = migrateSeedTable($stmt);
        if ($seedTable === '') {
            continue;
        }

        // users is special: the app auto-provisions the demo security officers
        // (ensureSecurityOfficers) on ANY login attempt, so this table can hold
        // rows before the first migration ever runs — exactly what happened on
        // Railway, leaving the demo students missing while reports never seeded.
        // A plain "only seed empty tables" rule would then NEVER add demo
        // students (e.g. S202403400), breaking the demo login permanently.
        // So we UPSERT the schema's demo accounts with INSERT IGNORE: rows that
        // already exist (by UNIQUE university_id/email) are left untouched and
        // only the missing demo accounts are inserted. Real rows are never
        // modified or deleted.
        if ($seedTable === 'users') {
            $demoUsersStmt = $stmt;
            try {
                $upsert = preg_replace('/^INSERT\s+INTO/i', 'INSERT IGNORE INTO', ltrim($stmt));
                $added  = (int) $pdo->exec($upsert);
                $seeded[] = 'users';
                migrateLog('UPSERT demo users (added: ' . $added . ')', $quiet);
            } catch (Throwable $e) {
                migrateLog('WARNING: seeding ' . $seedTable . ' failed (' . $e->getMessage() . ')', $quiet);
            }
            continue;
        }

        // All other tables: seed only when that specific table is empty.
        // Idempotent: once any row exists (ours or real user data) the demo
        // rows are never added.
        $count = migrateTableRowCount($pdo, $seedTable);
        if ($count > 0) {
            migrateLog('SKIP seeding ' . $seedTable . ' (already has ' . $count . ' rows)', $quiet);
            continue;
        }
        if ($count < 0) {
            // Table missing at this point (should not happen after CREATE IF
            // NOT EXISTS) — skip rather than abort the boot.
            migrateLog('WARNING: seeding skipped, table not found: ' . $seedTable, $quiet);
            continue;
        }
    }

    try {
        if ($isSeed && $demoUsersStmt !== '' && in_array($seedTable, ['reports', 'notifications'], true)) {
            $stmt = migrateRemapSeedUserColumn($pdo, $stmt, $demoUsersStmt);
        }
        $pdo->exec($stmt);
        if ($isSeed) { $seeded[] = $seedTable; }
    } catch (Throwable $e) {
        if ($isSeed) {
            migrateLog('WARNING: seeding ' . $seedTable . ' failed (' . $e->getMessage() . ')', $quiet);
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
        ? 'Migration finished WITH ERRORS (seeded: ' . implode(', ', array_unique($seeded)) . ').'
        : 'Migration OK (seeded: ' . (count($seeded) ? implode(', ', array_unique($seeded)) : 'none') . ').',
    $quiet
);

exit($dirty ? 1 : 0);