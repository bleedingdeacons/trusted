<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Support;

use Mockery;
use ReflectionMethod;
use Trusted\Support\Database;
use Trusted\Tests\TestCase;

/**
 * Covers Database's table naming, install/uninstall and the unique-index
 * migration, against a Mockery wpdb.
 *
 * @covers \Trusted\Support\Database
 */
final class DatabaseTest extends TestCase
{
    /** @return \Mockery\MockInterface */
    private function wpdb()
    {
        $db = Mockery::mock('wpdb');
        $db->prefix = 'wp_';
        $db->shouldReceive('prepare')->andReturnUsing(static fn (string $q): string => $q);
        $db->shouldReceive('get_charset_collate')->andReturn('DEFAULT CHARSET=utf8mb4');
        $GLOBALS['wpdb'] = $db;
        return $db;
    }

    public function testTableNamesUseThePrefix(): void
    {
        $this->wpdb();
        self::assertSame('wp_trusted_rota', Database::rotaTable());
        self::assertSame('wp_trusted_assignments', Database::assignmentsTable());
    }

    public function testUninstallDropsTables(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('query')->twice();

        Database::uninstall();
        self::assertTrue(true);
    }

    public function testEnsureUniqueRotaIndexIsANoopWhenAlreadyUnique(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_var')->once()->andReturn('0'); // NON_UNIQUE = 0
        $db->shouldNotReceive('query');

        (new ReflectionMethod(Database::class, 'ensureUniqueRotaIndex'))->invoke(null);
        self::assertTrue(true);
    }

    public function testEnsureUniqueRotaIndexUpgradesANonUniqueKey(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_var')->once()->andReturn('1'); // NON_UNIQUE = 1
        // dedupe DELETE + DROP INDEX + ADD UNIQUE KEY.
        $db->shouldReceive('query')->times(3);

        (new ReflectionMethod(Database::class, 'ensureUniqueRotaIndex'))->invoke(null);
        self::assertTrue(true);
    }

    public function testEnsureUniqueRotaIndexAddsKeyWhenNoIndexExists(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_var')->once()->andReturn(null); // index absent
        // dedupe DELETE + ADD UNIQUE KEY (no DROP, since there is no index).
        $db->shouldReceive('query')->times(2);

        (new ReflectionMethod(Database::class, 'ensureUniqueRotaIndex'))->invoke(null);
        self::assertTrue(true);
    }
}
