<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Factory;

use PHPUnit\Framework\TestCase;
use Trusted\Factory\AssignmentFactory;
use Trusted\Factory\RotaFactory;

/**
 * Tests for the database-row to value-object mapping.
 *
 * These factories are the boundary between the custom tables and the domain,
 * so the interesting cases are the shapes a row can arrive in: MySQL TIME
 * columns carrying seconds, nullable columns, and missing keys.
 */
final class FactoryTest extends TestCase
{
    /**
     * @test
     */
    public function rota_factory_maps_a_full_row(): void
    {
        $rota = (new RotaFactory())->fromRow([
            'id'          => '12',
            'slot_date'   => '2026-07-20',
            'start_time'  => '09:00:00',
            'end_time'    => '17:30:00',
            'label'       => 'Day shift',
            'template_id' => '4',
        ]);

        self::assertSame(12, $rota->id(), 'Numeric strings from the driver become ints.');
        self::assertSame('2026-07-20', $rota->slotDate());
        self::assertSame('Day shift', $rota->label());
        self::assertSame(4, $rota->templateId());
    }

    /**
     * @test
     */
    public function rota_factory_trims_seconds_off_mysql_time_columns(): void
    {
        // MySQL TIME returns H:i:s; the UI and the template grammar work in H:i.
        $rota = (new RotaFactory())->fromRow([
            'start_time' => '09:00:00',
            'end_time'   => '17:30:00',
        ]);

        self::assertSame('09:00', $rota->startTime());
        self::assertSame('17:30', $rota->endTime());
    }

    /**
     * @test
     */
    public function rota_factory_defaults_every_missing_column(): void
    {
        $rota = (new RotaFactory())->fromRow([]);

        self::assertNull($rota->id(), 'No id means an unsaved row.');
        self::assertSame('', $rota->slotDate());
        self::assertSame('', $rota->startTime());
        self::assertSame('', $rota->label());
        self::assertNull($rota->templateId());
    }

    /**
     * @test
     */
    public function rota_factory_treats_a_null_template_id_as_absent(): void
    {
        // template_id is nullable in the schema, so the column is present and
        // null for a rota that came from no template. isset() is false for
        // null, which is what makes the single check sufficient here.
        $rota = (new RotaFactory())->fromRow(['template_id' => null]);

        self::assertNull($rota->templateId());
    }

    /**
     * @test
     */
    public function rota_factory_create_leaves_the_id_unset_and_normalises_times(): void
    {
        $rota = (new RotaFactory())->create('2026-07-20', '09:00:00', '17:00:00', 'Day shift', 4);

        self::assertNull($rota->id(), 'A created rota is not yet persisted.');
        self::assertSame('09:00', $rota->startTime());
        self::assertSame(4, $rota->templateId());
    }

    /**
     * @test
     */
    public function assignment_factory_maps_a_full_row(): void
    {
        $assignment = (new AssignmentFactory())->fromRow([
            'id'          => '5',
            'rota_id'     => '12',
            'member_id'   => '99',
            'notes'       => 'Swapped with Jane',
            'assigned_at' => '2026-07-19 10:30:00',
        ]);

        self::assertSame(5, $assignment->id());
        self::assertSame(12, $assignment->rotaId());
        self::assertSame('99', $assignment->memberId(), 'Member ids stay strings.');
        self::assertSame('Swapped with Jane', $assignment->notes());
        self::assertSame('2026-07-19 10:30:00', $assignment->assignedAt());
    }

    /**
     * @test
     */
    public function assignment_factory_defaults_every_missing_column(): void
    {
        $assignment = (new AssignmentFactory())->fromRow([]);

        self::assertNull($assignment->id());
        self::assertSame(0, $assignment->rotaId());
        self::assertSame('', $assignment->memberId());
        self::assertSame('', $assignment->notes());
        self::assertNull($assignment->assignedAt());
    }

    /**
     * @test
     */
    public function assignment_factory_create_leaves_id_and_timestamp_to_the_database(): void
    {
        $assignment = (new AssignmentFactory())->create(12, '99', 'Cover');

        self::assertNull($assignment->id());
        self::assertNull($assignment->assignedAt(), 'assigned_at is set by the insert, not the factory.');
        self::assertSame(12, $assignment->rotaId());
        self::assertSame('Cover', $assignment->notes());
    }
}
