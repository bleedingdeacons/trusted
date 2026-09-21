<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Support;

use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;
use Trusted\Support\Database;

/*
 * Covers Database's table naming, install/uninstall and the unique-index
 * migration, against a Mockery wpdb.
 */

covers(Database::class);

function fakeWpdb(): MockInterface
{
    $db = Mockery::mock('wpdb');
    $db->prefix = 'wp_';
    $db->shouldReceive('prepare')->andReturnUsing(static fn (string $q): string => $q);
    $db->shouldReceive('get_charset_collate')->andReturn('DEFAULT CHARSET=utf8mb4');
    $GLOBALS['wpdb'] = $db;

    return $db;
}

function ensureUniqueRotaIndex(): void
{
    (new ReflectionMethod(Database::class, 'ensureUniqueRotaIndex'))->invoke(null);
}

it('prefixes the table names', function () {
    fakeWpdb();

    expect(Database::rotaTable())->toBe('wp_trusted_rota')
        ->and(Database::assignmentsTable())->toBe('wp_trusted_assignments');
});

it('drops both tables on uninstall', function () {
    fakeWpdb()->shouldReceive('query')->twice();

    Database::uninstall();
});

describe('ensureUniqueRotaIndex', function () {
    it('does nothing when the key is already unique', function () {
        $db = fakeWpdb();
        $db->shouldReceive('get_var')->once()->andReturn('0'); // NON_UNIQUE = 0
        $db->shouldNotReceive('query');

        ensureUniqueRotaIndex();
    });

    it('upgrades a non-unique key', function () {
        $db = fakeWpdb();
        $db->shouldReceive('get_var')->once()->andReturn('1'); // NON_UNIQUE = 1
        // dedupe DELETE + DROP INDEX + ADD UNIQUE KEY.
        $db->shouldReceive('query')->times(3);

        ensureUniqueRotaIndex();
    });

    it('adds the key when no index exists', function () {
        $db = fakeWpdb();
        $db->shouldReceive('get_var')->once()->andReturn(null); // index absent
        // dedupe DELETE + ADD UNIQUE KEY (no DROP, since there is no index).
        $db->shouldReceive('query')->times(2);

        ensureUniqueRotaIndex();
    });
});
