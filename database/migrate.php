<?php
// Luqya AI – automatic database migration runner
// ---------------------------------------------------------------------------
// Applies database/schema.sql to the database that config/db.php resolves
// (real env vars, Railway's MYSQL_URL/MYSQLHOST..., or config/config.local.php),
// then runs ensureSchemaUpgrades() to bring older tables in line.
//
// Safe to run as often as you like:
//   * CREATE TABLE IF NOT EXISTS leaves existing tables alone.
//   * Demo rows only go into tables that are empty, so re-runs never duplicate
//     anything, real data is never overwritten, and a table the app created
//     earlier (without rows) still gets seeded on the next boot. The dump's own
//     TRUNCATE / DELETE statements are never executed here — running those at
//     boot would wipe user data on every deploy.
//   * CREATE DATABASE / USE are skipped too: the DSN already targets DB_NAME,
//     so tables are created in the configured schema instead of a hardcoded one.
//
// Usage:
//   * Container / CLI:   php database/migrate.php [--quiet]
//                        (the Docker entrypoint also runs this before Apache
//                         starts, retrying until it succeeds).
//   * Web:               /database/migrate.php only runs while the database is
//                        empty; once users exist it refuses unless
//                        ALLOW_DB_RESET=1 is set.
// ---------------------------------------------------------------------------

require __DIR__ . '/../config/db.php';

$isCli          = (PHP_SAPI === 'cli');
$args           = isset($argv) ? $argv : [];
$quiet          = in_array('--quiet', $args, true);
$forceCliOrWeb  = ($isCli || getenv('ALLOW_DB_RESET') === '1') && in_array('--force', $args, true);

// When called over the web, refuse to run once the database already has data.
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
        // No database yet just means this is a first-time setup; let it through.
    }
}

/**
 * Splits an SQL script into individual statements. Ignores line comments, block
 * comments, and semicolons that sit inside quoted strings (', ", `) or after
 * backslash escapes. It behaves like the web installer's splitter, but is kept
 * self-contained so the migration runner doesn't need setup_db.php.
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
 * How many rows a table currently holds.
 * @return int -1 if the table is missing or can't be read.
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
 * The table name an INSERT statement targets.
 */
function migrateSeedTable(string $stmt): string {
    if (preg_match('/^INSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?/i', ltrim($stmt), $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Reads the demo users INSERT (from schema.sql) and maps each demo user's
 * canonical id to its university_id, e.g. 1 => 'SEC-USR-1'.
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
 * Swaps the user_id values inside a multi-row demo seed (reports or
 * notifications) from the schema's canonical demo ids (1..8) to the ids those
 * accounts actually hold in the target database.
 *
 * Why this exists: the app auto-provisions the demo security officers the first
 * time anyone tries to log in, so on a fresh deploy the demo accounts may live
 * under ids like 24..31 instead of 1..8. Inserting reports/notifications with
 * the canonical ids would trip the users foreign key, and the whole demo seed
 * would silently fail on every boot. We match accounts by their UNIQUE
 * university_id and only rewrite the references we can resolve.
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

    // Statements that must never run at boot:
    //   * CREATE DATABASE / USE — the DSN already targets DB_NAME, so tables
    //     are created inside the configured (Railway) database.
    //   * TRUNCATE TABLE / DELETE FROM — schema.sql's "reset the database"
    //     statements. Running them on every deploy would wipe real user data;
    //     the seeding below is idempotent instead.
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
        // (ensureSecurityOfficers) on any login attempt, so this table can hold
        // rows before the migration has ever run — exactly what happened on
        // Railway, where the officers existed but the demo students were missing
        // (and reports never seeded). A plain "only seed empty tables" rule
        // would never have added the students and would have broken the demo
        // login for good.
        // So the demo accounts are upserted with INSERT IGNORE: rows that
        // already exist (uniquely identified by university_id/email) stay as-is,
        // and only the missing accounts are added. Real rows are never modified.
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

        // Every other table just gets seeded when it's empty. Once it holds any
        // row — ours or real user data — the demo rows are never re-added.
        $count = migrateTableRowCount($pdo, $seedTable);
        if ($count > 0) {
            migrateLog('SKIP seeding ' . $seedTable . ' (already has ' . $count . ' rows)', $quiet);
            continue;
        }
        if ($count < 0) {
            // The table wasn't found (unexpected after CREATE IF NOT EXISTS) —
            // skip it rather than abort the boot.
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

// Bring any pre-existing tables up to date with what schema.sql declares.
ensureSchemaUpgrades($pdo);

migrateLog(
    $dirty
        ? 'Migration finished WITH ERRORS (seeded: ' . implode(', ', array_unique($seeded)) . ').'
        : 'Migration OK (seeded: ' . (count($seeded) ? implode(', ', array_unique($seeded)) : 'none') . ').',
    $quiet
);

exit($dirty ? 1 : 0);