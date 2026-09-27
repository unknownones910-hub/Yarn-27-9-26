<?php
/**
 * mysqli data layer.
 *
 * Every query in this project goes through these helpers, and every helper uses
 * a prepared statement. There is no path here that concatenates a value into
 * SQL — if you find yourself wanting one, you want a placeholder instead.
 *
 * Types are derived from the PHP values by default, which removes the classic
 * "placeholder count != type-string length != bound variable count" bug. You
 * can still pass an explicit type string when you need to force one (e.g. 's'
 * for a numeric string like a pincode that must not lose its leading zero).
 */
declare(strict_types=1);

function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    /** @var array $DB */
    require __DIR__ . '/../config/db-config.php';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['name'], (int) $DB['port']);
    } catch (Throwable $e) {
        // Throw rather than exit: bootstrap catches this and sends a fresh
        // install to install.php instead of showing a 500.
        error_log('DB connect failed: ' . $e->getMessage());
        $conn = null;
        throw new RuntimeException('DB_UNAVAILABLE', 0, $e);
    }

    $conn->set_charset($DB['charset']);
    // Predictable dates and strict-ish behaviour regardless of server defaults.
    $conn->query("SET time_zone = '+05:30'");

    return $conn;
}

/**
 * Derive a mysqli type string from PHP values.
 */
function db_types(array $params): string
{
    $types = '';
    foreach ($params as $p) {
        if (is_int($p))        { $types .= 'i'; }
        elseif (is_float($p))  { $types .= 'd'; }
        elseif (is_bool($p))   { $types .= 'i'; }
        else                   { $types .= 's'; }   // string and null
    }
    return $types;
}

/**
 * Prepare + bind + execute. Returns the statement.
 * @param array<int,mixed> $params
 */
function db_stmt(string $sql, array $params = [], string $types = ''): mysqli_stmt
{
    $conn = db();

    $placeholders = substr_count($sql, '?');
    if ($placeholders !== count($params)) {
        throw new InvalidArgumentException(
            "SQL has {$placeholders} placeholders but " . count($params) . ' params were given.'
        );
    }

    if ($types === '') {
        $types = db_types($params);
    }
    if (strlen($types) !== count($params)) {
        throw new InvalidArgumentException(
            'Type string length (' . strlen($types) . ') != param count (' . count($params) . ').'
        );
    }

    $t0 = microtime(true);

    $stmt = $conn->prepare($sql);
    if ($params) {
        // Booleans must become ints before binding.
        $bound = array_map(static fn($v) => is_bool($v) ? (int) $v : $v, $params);
        $stmt->bind_param($types, ...$bound);
    }
    $stmt->execute();

    db_stats(microtime(true) - $t0, $sql);

    return $stmt;
}

/**
 * Query counter. Two integers and a float in production — the cost of the
 * `if` is smaller than the cost of wondering why a page feels slow.
 *
 * Call with no arguments to read: ['count' => int, 'seconds' => float, 'slowest' => string].
 * `?debug=1` prints it in the footer on a local dev machine.
 *
 * @return array{count:int,seconds:float,slowest:string,slowest_seconds:float}
 */
function db_stats(?float $elapsed = null, string $sql = ''): array
{
    static $count = 0, $seconds = 0.0, $slowest = '', $slowestSecs = 0.0;

    if ($elapsed !== null) {
        $count++;
        $seconds += $elapsed;
        if ($elapsed > $slowestSecs) {
            $slowestSecs = $elapsed;
            $slowest     = preg_replace('/\s+/', ' ', trim($sql));
        }
    }

    return ['count' => $count, 'seconds' => $seconds,
            'slowest' => $slowest, 'slowest_seconds' => $slowestSecs];
}

/** All matching rows as associative arrays. */
function db_all(string $sql, array $params = [], string $types = ''): array
{
    $stmt = db_stmt($sql, $params, $types);
    $res  = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/** First matching row, or null. */
function db_row(string $sql, array $params = [], string $types = ''): ?array
{
    $stmt = db_stmt($sql, $params, $types);
    $res  = $stmt->get_result();
    $row  = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/** First column of the first row, or null. */
function db_val(string $sql, array $params = [], string $types = '')
{
    $row = db_row($sql, $params, $types);
    return $row ? reset($row) : null;
}

/** A single column from every row, as a flat list. */
function db_col(string $sql, array $params = [], string $types = ''): array
{
    return array_map(static fn($r) => reset($r), db_all($sql, $params, $types));
}

/** Run an INSERT/UPDATE/DELETE. Returns affected rows. */
function db_exec(string $sql, array $params = [], string $types = ''): int
{
    $stmt = db_stmt($sql, $params, $types);
    $n    = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

/** Run an INSERT. Returns the new auto-increment id. */
function db_insert(string $sql, array $params = [], string $types = ''): int
{
    $stmt = db_stmt($sql, $params, $types);
    $id   = (int) db()->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Run a callable inside a transaction. Commits on clean return, rolls back on
 * any throwable, and rethrows. Use this for anything that touches stock, money
 * or order state.
 */
function db_tx(callable $fn)
{
    $conn = db();
    $conn->begin_transaction();
    try {
        $result = $fn($conn);
        $conn->commit();
        return $result;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/** Build "?,?,?" for an IN() clause. Never call with an empty array. */
function db_in(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

/** Does a table exist? Used by the installer / migration check. */
function db_table_exists(string $table): bool
{
    $sql = 'SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?';
    return (int) db_val($sql, [$table]) > 0;
}
