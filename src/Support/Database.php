<?php

declare(strict_types=1);

namespace Trusted\Support;

/**
 * Central source of truth for custom table names and schema.
 */
final class Database
{
    public const ROTA        = 'trusted_rota';
    public const ASSIGNMENTS = 'trusted_assignments';

    /**
     * @return literal-string
     *
     * wpdb::prepare() only accepts a literal-string query, and the repositories
     * interpolate this into every one of theirs. PHPStan types $wpdb->prefix as
     * a plain string so it cannot derive that on its own — the assertion below
     * supplies it, and it holds: the prefix comes from wp-config.php and the
     * table name is a class constant, so no part is reachable from user input.
     *
     * Asserted on the prefix rather than on the concatenation, because PHPStan
     * infers the joined string as non-falsy-string and literal-string is not a
     * subtype of that, so a @var on the result is rejected outright.
     */
    public static function rotaTable(): string
    {
        global $wpdb;

        /** @var literal-string $prefix */
        $prefix = $wpdb->prefix;

        return $prefix . self::ROTA;
    }

    /**
     * @return literal-string
     *
     * See rotaTable() on why the assertion is needed and why it holds.
     */
    public static function assignmentsTable(): string
    {
        global $wpdb;

        /** @var literal-string $prefix */
        $prefix = $wpdb->prefix;

        return $prefix . self::ASSIGNMENTS;
    }

    /**
     * Create or update the custom tables. Safe to run repeatedly (dbDelta).
     */
    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $rota           = self::rotaTable();
        $assignments    = self::assignmentsTable();

        // dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one
        // definition per line, KEY names lower case.
        $sql = [];

        $sql[] = "CREATE TABLE {$rota} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slot_date date NOT NULL,
  start_time time NOT NULL,
  end_time time NOT NULL,
  label varchar(191) NOT NULL DEFAULT '',
  template_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY slot_date (slot_date),
  KEY template_id (template_id)
) {$charsetCollate};";

        // rota_id is UNIQUE: one assignment per shift slot, enforced by the
        // database so the sign-up path can rely on an atomic insert rather
        // than a racy check-then-insert.
        $sql[] = "CREATE TABLE {$assignments} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rota_id bigint(20) unsigned NOT NULL,
  member_id varchar(191) NOT NULL,
  notes text NULL,
  assigned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY rota_id (rota_id),
  KEY member_id (member_id)
) {$charsetCollate};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        self::ensureUniqueRotaIndex();

        update_option('trusted_db_version', \TRUSTED_VERSION);
    }

    /**
     * Guarantee the one-member-per-shift rule at the database level.
     *
     * Earlier versions gave the assignments table a *non-unique* KEY on
     * rota_id, so "one member per slot" depended on a check-then-insert in
     * the service layer — racy under concurrent sign-ups. dbDelta won't
     * upgrade an existing KEY to UNIQUE (the index name already exists), so
     * on such an install we dedupe (keep the earliest sign-up per slot) and
     * swap the index here. A no-op once rota_id is already unique — including
     * fresh installs, where dbDelta created it unique above.
     */
    private static function ensureUniqueRotaIndex(): void
    {
        global $wpdb;

        $table = self::assignmentsTable();

        $nonUnique = $wpdb->get_var($wpdb->prepare(
            "SELECT NON_UNIQUE FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                AND INDEX_NAME = 'rota_id' AND SEQ_IN_INDEX = 1",
            $table
        ));

        // Already unique — nothing to do.
        if ($nonUnique !== null && (int) $nonUnique === 0) {
            return;
        }

        // Remove duplicate sign-ups for the same slot, keeping the earliest
        // (lowest id). Table name is built from the trusted $wpdb prefix and a
        // class constant, never user input.
        $wpdb->query(
            "DELETE a FROM {$table} a
               JOIN {$table} b ON a.rota_id = b.rota_id AND a.id > b.id"
        ); // phpcs:ignore

        if ($nonUnique !== null) {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX rota_id"); // phpcs:ignore
        }
        $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY rota_id (rota_id)"); // phpcs:ignore
    }

    /**
     * Drop the custom tables. Used on uninstall.
     */
    public static function uninstall(): void
    {
        global $wpdb;

        $rota        = self::rotaTable();
        $assignments = self::assignmentsTable();

        // Table names cannot be parameterised; they are built from the trusted
        // $wpdb prefix and class constants, never from user input.
        $wpdb->query("DROP TABLE IF EXISTS {$assignments}"); // phpcs:ignore
        $wpdb->query("DROP TABLE IF EXISTS {$rota}");        // phpcs:ignore

        delete_option('trusted_db_version');
    }
}
