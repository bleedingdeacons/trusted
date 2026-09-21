<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Repository;

use Mockery;
use Trusted\Factory\AssignmentFactory;
use Trusted\Repository\AssignmentRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Unity\Members\Interfaces\Member;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/*
 * Covers the AssignmentRepository read/write/delete methods that the atomic
 * assignIfOpen suite (AssignmentRepositoryTest) does not.
 */

covers(AssignmentRepository::class);

/**
 * @param list<Member> $members
 */
function assignmentRepository(array $members = []): AssignmentRepository
{
    return new AssignmentRepository(new AssignmentFactory(), new InMemoryMemberRepository($members));
}

/**
 * @return array<string, int|string>
 */
function assignmentRow(int $id, int $rotaId = 12, string $memberId = '7'): array
{
    return ['id' => $id, 'rota_id' => $rotaId, 'member_id' => $memberId, 'notes' => 'n', 'assigned_at' => '2026-07-20 10:00:00'];
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

        expect(assignmentRepository()->find(99))->toBeNull();
    });

    it('hydrates the assignment with its member', function () {
        $this->db->shouldReceive('get_row')->once()->andReturn(assignmentRow(3, 12, '7'));

        $assignment = assignmentRepository([new ResponderStub(id: 7)])->find(3);

        expect($assignment->id())->toBe(3)
            ->and($assignment->member())->not->toBeNull();
    });

    it('yields no member for a non-numeric member id', function () {
        $this->db->shouldReceive('get_row')->once()->andReturn(assignmentRow(1, 12, 'not-numeric'));

        expect(assignmentRepository()->find(1)->member())->toBeNull();
    });
});

describe('findByRota', function () {
    it('hydrates every row for the rota', function () {
        $this->db->shouldReceive('get_results')->once()->andReturn([assignmentRow(1), assignmentRow(2)]);

        expect(assignmentRepository()->findByRota(12))->toHaveCount(2);
    });
});

describe('findByRotaIds', function () {
    it('groups the rows by rota', function () {
        $this->db->shouldReceive('get_results')->once()->andReturn([
            assignmentRow(1, 12), assignmentRow(2, 13),
        ]);

        expect(assignmentRepository()->findByRotaIds([12, 13, 12, 0])) // dedupe/filter
            ->toHaveKey(12)
            ->toHaveKey(13);
    });

    it('returns empty for input with no usable ids', function () {
        expect(assignmentRepository()->findByRotaIds(['x', 0, '0']))->toBe([]);
    });
});

describe('save', function () {
    it('inserts a new assignment and takes the insert id', function () {
        $this->db->insert_id = 88;
        $this->db->shouldReceive('insert')->once()->andReturn(1);

        $saved = assignmentRepository([new ResponderStub(id: 7)])
            ->save((new AssignmentFactory())->create(12, '7', 'note'));

        expect($saved->id())->toBe(88);
    });

    it('updates an existing assignment', function () {
        $this->db->shouldReceive('update')->once()->andReturn(1);

        $existing = (new AssignmentFactory())->create(12, '7', 'note')->withId(5);

        expect(assignmentRepository()->save($existing)->id())->toBe(5);
    });
});

describe('delete', function () {
    it('deletes one assignment', function () {
        $this->db->shouldReceive('delete')->once()->andReturn(1);

        expect(assignmentRepository()->delete(5))->toBeTrue();
    });

    it("deletes a rota's assignments", function () {
        $this->db->shouldReceive('delete')->once()->andReturn(2);

        expect(assignmentRepository()->deleteByRota(12))->toBeTrue();
    });

    it('deletes everything and reports the count', function () {
        $this->db->shouldReceive('get_var')->once()->andReturn('3');
        $this->db->shouldReceive('query')->once()->andReturn(3);

        expect(assignmentRepository()->deleteAll())->toBe(3);
    });
});
