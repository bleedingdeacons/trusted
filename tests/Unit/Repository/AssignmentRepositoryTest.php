<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Repository;

use Mockery;
use Trusted\Factory\AssignmentFactory;
use Trusted\Repository\AssignmentRepository;
use Trusted\Tests\Fixtures\InMemoryMemberRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Trusted\Tests\TestCase;

/**
 * Tests for the atomic slot claim.
 *
 * assignIfOpen() is the source of truth for "one member per shift". It does
 * not read-then-write: it relies on INSERT IGNORE against a UNIQUE(rota_id)
 * constraint, so two concurrent sign-ups cannot both land. The contract these
 * tests pin is what ShiftSignup depends on to report a slot as `full` rather
 * than double-booking it.
 */
final class AssignmentRepositoryTest extends TestCase
{
    /**
     * @test
     */
    public function it_returns_the_assignment_when_the_insert_claims_the_slot(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('prepare')->once()->andReturn('INSERT IGNORE ...');
        $db->shouldReceive('query')->once()->with('INSERT IGNORE ...')->andReturn(1);
        $db->insert_id = 55;

        $assignment = $this->makeRepository($db)->assignIfOpen(12, '7', 'Happy to cover');

        self::assertNotNull($assignment);
        self::assertSame(55, $assignment->id(), 'The id comes from the insert.');
        self::assertSame(12, $assignment->rotaId());
        self::assertSame('7', $assignment->memberId());
        self::assertSame('Happy to cover', $assignment->notes());
    }

    /**
     * @test
     */
    public function it_returns_null_when_the_slot_was_already_taken(): void
    {
        // INSERT IGNORE affects zero rows when UNIQUE(rota_id) rejects it.
        // That zero is the whole signal — there is no follow-up SELECT.
        $db = $this->wpdb();
        $db->shouldReceive('prepare')->once()->andReturn('INSERT IGNORE ...');
        $db->shouldReceive('query')->once()->andReturn(0);
        $db->insert_id = 0;

        self::assertNull($this->makeRepository($db)->assignIfOpen(12, '7', ''));
    }

    /**
     * @test
     */
    public function it_returns_null_when_the_query_fails_outright(): void
    {
        // wpdb::query() returns false on error. A failed insert must not be
        // mistaken for a successful claim.
        $db = $this->wpdb();
        $db->shouldReceive('prepare')->once()->andReturn('INSERT IGNORE ...');
        $db->shouldReceive('query')->once()->andReturn(false);
        $db->insert_id = 0;

        self::assertNull($this->makeRepository($db)->assignIfOpen(12, '7', ''));
    }

    /**
     * @test
     */
    public function it_returns_null_when_rows_were_affected_but_no_id_was_produced(): void
    {
        // Belt and braces: the guard checks both the affected count and the
        // insert id, so an inconsistent driver response is still rejected.
        $db = $this->wpdb();
        $db->shouldReceive('prepare')->once()->andReturn('INSERT IGNORE ...');
        $db->shouldReceive('query')->once()->andReturn(1);
        $db->insert_id = 0;

        self::assertNull($this->makeRepository($db)->assignIfOpen(12, '7', ''));
    }

    /**
     * @test
     */
    public function it_attaches_the_unity_member_to_a_successful_claim(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('prepare')->once()->andReturn('INSERT IGNORE ...');
        $db->shouldReceive('query')->once()->andReturn(1);
        $db->insert_id = 55;

        $members = new InMemoryMemberRepository([
            new ResponderStub(id: 7, anonymousName: 'John D', personalEmail: 'john@example.test'),
        ]);

        $assignment = $this->makeRepository($db, $members)->assignIfOpen(12, '7', '');

        self::assertNotNull($assignment?->member());
        self::assertSame('John D', $assignment->member()?->name());
    }

    /**
     * @test
     */
    public function it_leaves_the_member_null_when_the_id_is_not_numeric(): void
    {
        // Member ids are stored as strings; a non-numeric one cannot be looked
        // up in Unity, and must not blow up the claim.
        $db = $this->wpdb();
        $db->shouldReceive('prepare')->once()->andReturn('INSERT IGNORE ...');
        $db->shouldReceive('query')->once()->andReturn(1);
        $db->insert_id = 55;

        $assignment = $this->makeRepository($db)->assignIfOpen(12, 'oauth|abc', '');

        self::assertNotNull($assignment);
        self::assertNull($assignment->member());
    }

    /**
     * @test
     */
    public function it_leaves_the_member_null_when_unity_does_not_know_the_id(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('prepare')->once()->andReturn('INSERT IGNORE ...');
        $db->shouldReceive('query')->once()->andReturn(1);
        $db->insert_id = 55;

        $assignment = $this->makeRepository($db, new InMemoryMemberRepository())->assignIfOpen(12, '404', '');

        self::assertNotNull($assignment);
        self::assertNull($assignment->member());
    }

    /**
     * A wpdb double, installed as the global the repository reads at
     * construction. wpdb does not exist outside WordPress, so Mockery
     * generates the class as well as the double.
     *
     * @return \Mockery\MockInterface
     */
    private function wpdb()
    {
        $db = Mockery::mock('wpdb');
        $db->prefix = 'wp_';

        $GLOBALS['wpdb'] = $db;

        return $db;
    }

    private function makeRepository($db, ?InMemoryMemberRepository $members = null): AssignmentRepository
    {
        $GLOBALS['wpdb'] = $db;

        return new AssignmentRepository(
            new AssignmentFactory(),
            $members ?? new InMemoryMemberRepository(),
        );
    }
}
