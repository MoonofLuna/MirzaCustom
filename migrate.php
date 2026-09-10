<?php
/**
 * Standalone, idempotent schema migration for the x_ui / vpn_ui panel types.
 *
 * Safe to run any number of times. It ONLY adds columns that are missing
 * (nullable / defaulted) — it never renames, drops, or rewrites existing data.
 * The same column set is also added automatically by table.php on install /
 * update; this script exists so an operator can apply it on demand without
 * touching anything else.
 *
 * Run from the shell:   php migrate.php
 * Or from a browser:     https://<your-domain>/migrate.php
 */

ini_set('error_log', 'error_log');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/function.php';

$isCli = (php_sapi_name() === 'cli');
$eol   = $isCli ? "\n" : "<br>\n";

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

/**
 * columns to ensure: [table, column, default, type]
 * default === null  -> column added, no table-wide backfill
 * default !== null  -> existing rows get that value on the run that adds it
 */
$columns = [
    // marzban_panel — multi-inbound 3x-ui / vpn-ui panel configuration
    ['marzban_panel', 'inbound_list',     null, 'TEXT'],
    ['marzban_panel', 'twofa_secret',     null, 'VARCHAR(64)'],
    ['marzban_panel', 'sub_json',         null, 'VARCHAR(20)'],
    ['marzban_panel', 'default_protocol', null, 'VARCHAR(32)'],

    // product — per-plan overrides
    ['product', 'inbound_list', null, 'TEXT'],
    ['product', 'protocol',     null, 'VARCHAR(32)'],

    // invoice — records which inbound ids an account was created in, and the
    // credentials needed to rebuild non-Xray links (mtproto secret, passwords)
    ['invoice', 'panel_inbounds', null, 'TEXT'],
    ['invoice', 'xui_creds',      null, 'TEXT'],
];

echo "mirza_pro :: x_ui / vpn_ui schema migration" . $eol;
echo "-------------------------------------------" . $eol;

$added = 0;
$present = 0;
$failed = 0;

foreach ($columns as [$table, $column, $default, $type]) {
    try {
        if (!columnMissingForMigration($pdo, $table, $column)) {
            echo "  = {$table}.{$column} already present" . $eol;
            $present++;
            continue;
        }
        // addFieldToTable() re-checks existence before ALTER, so this stays safe
        addFieldToTable($table, $column, $default, $type);
        if (columnMissingForMigration($pdo, $table, $column)) {
            echo "  ! {$table}.{$column} could NOT be added" . $eol;
            $failed++;
        } else {
            echo "  + {$table}.{$column} added" . $eol;
            $added++;
        }
    } catch (Throwable $e) {
        echo "  ! {$table}.{$column} error: " . $e->getMessage() . $eol;
        $failed++;
    }
}

echo "-------------------------------------------" . $eol;
echo "added: {$added}   already present: {$present}   failed: {$failed}" . $eol;
echo ($failed === 0 ? "OK" : "COMPLETED WITH ERRORS — see error_log") . $eol;

/**
 * True when the column does not yet exist on the table.
 * Mirrors the check inside addFieldToTable() but returns a value we can log.
 */
function columnMissingForMigration(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return ((int) $stmt->fetchColumn()) === 0;
}
