<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Repository;

use Mockery;
use Trusted\Factory\AssignmentFactory;
use Trusted\Repository\AssignmentRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/*
 * Tests for the atomic slot claim.
 *
 * assignIfOpen() is the source of truth for "one member per shift". It does
 * not read-then-write: it relies on INSERT IGNORE against a UNIQUE(rota_id)
 * constraint, so two concurrent sign-ups cannot both land. The contract these
 * tests pin is what ShiftSignup depends on to report a slot as `full` rather
 * than double-booking it.
 */

function claimRepository(?InMemoryMemberRepository $members = null): AssignmentRepository
{
    return new AssignmentRepository(
        new AssignmentFactory(),
        $members ?? new InMemoryMemberRepository(),
    );
}

beforeEach(function () {
    // A wpdb double, installed as the global the repository reads at
    // construction. wpdb does not exist outside WordPress, so Mockery
    // generates the class as well as the double.
    $this->db = Mockery::mock('wpdb');
    $this->db->prefix = 'wp_';
    $this->db->shouldReceive('prepare')->once()->andReturn('INSERT IGNORE ...');
    $GLOBALS['wpdb'] = $this->db;
});

describe('assignIfOpen', function () {
    it('returns the assignment when the insert claims the slot', function () {
        $this->db->shouldReceive('query')->once()->with('INSERT IGNORE ...')->andReturn(1);
        $this->db->insert_id = 55;

        $assignment = claimRepository()->assignIfOpen(12, '7', 'Happy to cover');

        expect($assignment)->not->toBeNull()
            ->and($assignment->id())->toBe(55, 'The id comes from the insert.')
            ->and($assignment->rotaId())->toBe(12)
            ->and($assignment->memberId())->toBe('7')
            ->and($assignment->notes())->toBe('Happy to cover');
    });

    it('returns null when the slot was already taken', function () {
        // INSERT IGNORE affects zero rows when UNIQUE(rota_id) rejects it.
        // That zero is the whole signal — there is no follow-up SELECT.
        $this->db->shouldReceive('query')->once()->andReturn(0);
        $this->db->insert_id = 0;

        expect(claimRepository()->assignIfOpen(12, '7', ''))->toBeNull();
    });

    it('returns null when the query fails outright', function () {
        // wpdb::query() returns false on error. A failed insert must not be
        // mistaken for a successful claim.
        $this->db->shouldReceive('query')->once()->andReturn(false);
        $this->db->insert_id = 0;

        expect(claimRepository()->assignIfOpen(12, '7', ''))->toBeNull();
    });

    it('returns null when rows were affected but no id was produced', function () {
        // Belt and braces: the guard checks both the affected count and the
        // insert id, so an inconsistent driver response is still rejected.
        $this->db->shouldReceive('query')->once()->andReturn(1);
        $this->db->insert_id = 0;

        expect(claimRepository()->assignIfOpen(12, '7', ''))->toBeNull();
    });

    it('attaches the Unity member to a successful claim', function () {
        $this->db->shouldReceive('query')->once()->andReturn(1);
        $this->db->insert_id = 55;

        $members = new InMemoryMemberRepository([
            new ResponderStub(id: 7, anonymousName: 'John D', personalEmail: 'john@example.test'),
        ]);

        $assignment = claimRepository($members)->assignIfOpen(12, '7', '');

        expect($assignment?->member())->not->toBeNull()
            ->and($assignment->member()?->name())->toBe('John D');
    });

    it('leaves the member null when the id is not numeric', function () {
        // Member ids are stored as strings; a non-numeric one cannot be looked
        // up in Unity, and must not blow up the claim.
        $this->db->shouldReceive('query')->once()->andReturn(1);
        $this->db->insert_id = 55;

        $assignment = claimRepository()->assignIfOpen(12, 'oauth|abc', '');

        expect($assignment)->not->toBeNull()
            ->and($assignment->member())->toBeNull();
    });

    it('leaves the member null when Unity does not know the id', function () {
        $this->db->shouldReceive('query')->once()->andReturn(1);
        $this->db->insert_id = 55;

        $assignment = claimRepository(new InMemoryMemberRepository())->assignIfOpen(12, '404', '');

        expect($assignment)->not->toBeNull()
            ->and($assignment->member())->toBeNull();
    });
});
