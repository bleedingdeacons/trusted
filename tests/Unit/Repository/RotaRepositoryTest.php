<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Repository;

use Mockery;
use Trusted\Factory\RotaFactory;
use Trusted\Repository\RotaRepository;
use Trusted\Testing\Doubles\InMemoryAssignmentRepository;
use Trusted\Tests\TestCase;

/**
 * Covers RotaRepository's $wpdb-backed reads and writes against a Mockery wpdb.
 *
 * @covers \Trusted\Repository\RotaRepository
 */
final class RotaRepositoryTest extends TestCase
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

    private function make($db): RotaRepository
    {
        $GLOBALS['wpdb'] = $db;
        return new RotaRepository(new RotaFactory(), new InMemoryAssignmentRepository());
    }

    private function row(int $id = 1): array
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

    public function testFindReturnsNullWhenAbsent(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_row')->once()->andReturn(null);
        self::assertNull($this->make($db)->find(99));
    }

    public function testFindHydratesARow(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_row')->once()->andReturn($this->row(5));

        $rota = $this->make($db)->find(5);
        self::assertNotNull($rota);
        self::assertSame(5, $rota->id());
        self::assertSame('2026-07-20', $rota->slotDate());
    }

    public function testFindForWeekHydratesRows(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_results')->once()->andReturn([$this->row(1), $this->row(2)]);

        $slots = $this->make($db)->findForWeek('2026-07-20');
        self::assertCount(2, $slots);
    }

    public function testFindForWeekEmpty(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_results')->once()->andReturn(null);
        self::assertSame([], $this->make($db)->findForWeek('2026-07-20'));
    }

    public function testFindForDate(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_results')->once()->andReturn([$this->row(1)]);
        self::assertCount(1, $this->make($db)->findForDate('2026-07-20'));
    }

    public function testSaveInsertsANewSlot(): void
    {
        $db = $this->wpdb();
        $db->insert_id = 77;
        $db->shouldReceive('insert')->once()->andReturn(1);

        $saved = $this->make($db)->save((new RotaFactory())->create('2026-07-20', '09:00', '12:00', 'AM'));
        self::assertSame(77, $saved->id());
    }

    public function testSaveUpdatesAnExistingSlot(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('update')->once()->andReturn(1);

        $existing = (new RotaFactory())->create('2026-07-20', '09:00', '12:00', 'AM')->withId(9);
        $saved = $this->make($db)->save($existing);
        self::assertSame(9, $saved->id());
    }

    public function testDelete(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('delete')->once()->andReturn(1);
        self::assertTrue($this->make($db)->delete(9));
    }

    public function testDeleteWeekDeletesEachSlot(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_results')->once()->andReturn([$this->row(1), $this->row(2)]);
        $db->shouldReceive('delete')->twice()->andReturn(1);

        self::assertSame(2, $this->make($db)->deleteWeek('2026-07-20'));
    }

    public function testDeleteAll(): void
    {
        $db = $this->wpdb();
        $db->shouldReceive('get_var')->once()->andReturn('4');
        $db->shouldReceive('query')->once()->andReturn(4);

        self::assertSame(4, $this->make($db)->deleteAll());
    }
}
