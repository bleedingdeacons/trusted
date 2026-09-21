<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Repository;

use Mockery;
use Trusted\Factory\RotaFactory;
use Trusted\Repository\RotaRepository;
use Trusted\Testing\Doubles\InMemoryAssignmentRepository;

/*
 * Covers RotaRepository's $wpdb-backed reads and writes against a Mockery wpdb.
 */

covers(RotaRepository::class);

function rotaRepository(): RotaRepository
{
    return new RotaRepository(new RotaFactory(), new InMemoryAssignmentRepository());
}

/**
 * @return array<string, int|string|null>
 */
function rotaRow(int $id = 1): array
{
    return [
        'id'          => $id,
        'slot_date'   => '2026-07-20',
        'start_time'  => '09:00:00',
        'end_time'    => '12:00:00',
        'label'       => 'AM',
        'template_id' => null,
    ];
}

beforeEach(function () {
    $this->db = Mockery::mock('wpdb');
    $this->db->prefix = 'wp_';
    $this->db->insert_id = 0;
    $this->db->shouldReceive('prepare')->andReturnUsing(static fn (string $q): string => $q);
    $GLOBALS['wpdb'] = $this->db;
});

describe('find', function () {
    it('returns null when the row is absent', function () {
        $this->db->shouldReceive('get_row')->once()->andReturn(null);

        expect(rotaRepository()->find(99))->toBeNull();
    });

    it('hydrates a row', function () {
        $this->db->shouldReceive('get_row')->once()->andReturn(rotaRow(5));

        $rota = rotaRepository()->find(5);

        expect($rota)->not->toBeNull()
            ->and($rota->id())->toBe(5)
            ->and($rota->slotDate())->toBe('2026-07-20');
    });
});

describe('findForWeek', function () {
    it('hydrates every row', function () {
        $this->db->shouldReceive('get_results')->once()->andReturn([rotaRow(1), rotaRow(2)]);

        expect(rotaRepository()->findForWeek('2026-07-20'))->toHaveCount(2);
    });

    it('returns empty when the query returns nothing', function () {
        $this->db->shouldReceive('get_results')->once()->andReturn(null);

        expect(rotaRepository()->findForWeek('2026-07-20'))->toBe([]);
    });
});

describe('findForDate', function () {
    it('hydrates every row', function () {
        $this->db->shouldReceive('get_results')->once()->andReturn([rotaRow(1)]);

        expect(rotaRepository()->findForDate('2026-07-20'))->toHaveCount(1);
    });
});

describe('save', function () {
    it('inserts a new slot and takes the insert id', function () {
        $this->db->insert_id = 77;
        $this->db->shouldReceive('insert')->once()->andReturn(1);

        $saved = rotaRepository()->save((new RotaFactory())->create('2026-07-20', '09:00', '12:00', 'AM'));

        expect($saved->id())->toBe(77);
    });

    it('updates an existing slot', function () {
        $this->db->shouldReceive('update')->once()->andReturn(1);

        $existing = (new RotaFactory())->create('2026-07-20', '09:00', '12:00', 'AM')->withId(9);

        expect(rotaRepository()->save($existing)->id())->toBe(9);
    });
});

describe('delete', function () {
    it('deletes one slot', function () {
        $this->db->shouldReceive('delete')->once()->andReturn(1);

        expect(rotaRepository()->delete(9))->toBeTrue();
    });

    it('deletes each slot in a week', function () {
        $this->db->shouldReceive('get_results')->once()->andReturn([rotaRow(1), rotaRow(2)]);
        $this->db->shouldReceive('delete')->twice()->andReturn(1);

        expect(rotaRepository()->deleteWeek('2026-07-20'))->toBe(2);
    });

    it('deletes everything and reports the count', function () {
        $this->db->shouldReceive('get_var')->once()->andReturn('4');
        $this->db->shouldReceive('query')->once()->andReturn(4);

        expect(rotaRepository()->deleteAll())->toBe(4);
    });
});
