<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Repository;

use Mockery;
use Trusted\Factory\AssignmentFactory;
use Trusted\Repository\AssignmentRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Trusted\Tests\TestCase;

/**
 * Covers the AssignmentRepository read/write/delete methods that the atomic
 * assignIfOpen suite (AssignmentRepositoryTest) does not.
 *
 * @covers \Trusted\Repository\AssignmentRepository
 */
final class AssignmentRepositoryReadWriteTest extends TestCase
{
    /** @return \Mockery\MockInterface */
    private function wpdb()
    {
        $db = Mockery::mock('wpdb');
        $db->prefix = 'wp_';
        $db->insert_id = 0;
        $db->shouldReceive('prepare')->andReturnUsing(static fn (string $q): string => $q);
        $GLOBALS['wpdb'] = $db;
        return $db;
    }

    private function make($db, array $members = []): AssignmentRepository
    {
        $GLOBALS['wpdb'] = $db;
        return new AssignmentRepository(new AssignmentFactory(), new InMemoryMemberRepository($members));
    }

    private function row(int $id, int $rotaId = 12, string $memberId = '7'): array
    {
        return ['id' => $id, 'rota_id' => $rotaId, 'member_id' => $memberId, 'notes' => 'n', 'assigned_at' => '2026-07-20 10:00:00'];
    }

    public function testFindReturnsNullWhenAbsent(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_row')->once()->andReturn(null);
        self::assertNull($this->make($db)->find(99));
    }

    public function testFindHydratesWithMember(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_row')->once()->andReturn($this->row(3, 12, '7'));

        $assignment = $this->make($db, [new ResponderStub(id: 7)])->find(3);
        self::assertSame(3, $assignment->id());
        self::assertNotNull($assignment->member());
    }

    public function testFindByRota(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_results')->once()->andReturn([$this->row(1), $this->row(2)]);
        self::assertCount(2, $this->make($db)->findByRota(12));
    }

    public function testFindByRotaIdsGroupsByRota(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_results')->once()->andReturn([
            $this->row(1, 12), $this->row(2, 13),
        ]);

        $grouped = $this->make($db)->findByRotaIds([12, 13, 12, 0]); // dedupe/filter
        self::assertArrayHasKey(12, $grouped);
        self::assertArrayHasKey(13, $grouped);
    }

    public function testFindByRotaIdsEmptyInput(): void
    {
        $db = $this->wpdb();
        self::assertSame([], $this->make($db)->findByRotaIds(['x', 0, '0']));
    }

    public function testSaveInserts(): void
    {
        $db = $this->wpdb();
        $db->insert_id = 88;
        $db->shouldReceive('insert')->once()->andReturn(1);

        $saved = $this->make($db, [new ResponderStub(id: 7)])
            ->save((new AssignmentFactory())->create(12, '7', 'note'));
        self::assertSame(88, $saved->id());
    }

    public function testSaveUpdates(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('update')->once()->andReturn(1);

        $existing = (new AssignmentFactory())->create(12, '7', 'note')->withId(5);
        self::assertSame(5, $this->make($db)->save($existing)->id());
    }

    public function testDelete(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('delete')->once()->andReturn(1);
        self::assertTrue($this->make($db)->delete(5));
    }

    public function testDeleteByRota(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('delete')->once()->andReturn(2);
        self::assertTrue($this->make($db)->deleteByRota(12));
    }

    public function testDeleteAll(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_var')->once()->andReturn('3');
        $db->shouldReceive('query')->once()->andReturn(3);
        self::assertSame(3, $this->make($db)->deleteAll());
    }

    public function testHydrateWithNonNumericMemberYieldsNoMember(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_row')->once()->andReturn($this->row(1, 12, 'not-numeric'));
        self::assertNull($this->make($db)->find(1)->member());
    }
}
