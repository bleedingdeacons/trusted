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

    public static function rotaTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::ROTA;
    }

    public static function assignmentsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::ASSIGNMENTS;
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

        $sql[] = "CREATE TABLE {$assignments} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rota_id bigint(20) unsigned NOT NULL,
  member_id varchar(191) NOT NULL,
  notes text NULL,
  assigned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY rota_id (rota_id),
  KEY member_id (member_id)
) {$charsetCollate};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        update_option('trusted_db_version', \TRUSTED_VERSION);
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
