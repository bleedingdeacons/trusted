<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Factory;

use Trusted\Factory\AssignmentFactory;
use Trusted\Factory\RotaFactory;

/*
 * Tests for the database-row to value-object mapping.
 *
 * These factories are the boundary between the custom tables and the domain,
 * so the interesting cases are the shapes a row can arrive in: MySQL TIME
 * columns carrying seconds, nullable columns, and missing keys.
 */

describe('RotaFactory', function () {
    it('maps a full row', function () {
        $rota = (new RotaFactory())->fromRow([
            'id'          => '12',
            'slot_date'   => '2026-07-20',
            'start_time'  => '09:00:00',
            'end_time'    => '17:30:00',
            'label'       => 'Day shift',
            'template_id' => '4',
        ]);

        expect($rota->id())->toBe(12, 'Numeric strings from the driver become ints.')
            ->and($rota->slotDate())->toBe('2026-07-20')
            ->and($rota->label())->toBe('Day shift')
            ->and($rota->templateId())->toBe(4);
    });

    it('trims seconds off MySQL TIME columns', function () {
        // MySQL TIME returns H:i:s; the UI and the template grammar work in H:i.
        $rota = (new RotaFactory())->fromRow([
            'start_time' => '09:00:00',
            'end_time'   => '17:30:00',
        ]);

        expect($rota->startTime())->toBe('09:00')
            ->and($rota->endTime())->toBe('17:30');
    });

    it('defaults every missing column', function () {
        $rota = (new RotaFactory())->fromRow([]);

        expect($rota->id())->toBeNull('No id means an unsaved row.')
            ->and($rota->slotDate())->toBe('')
            ->and($rota->startTime())->toBe('')
            ->and($rota->label())->toBe('')
            ->and($rota->templateId())->toBeNull();
    });

    it('treats a null template id as absent', function () {
        // template_id is nullable in the schema, so the column is present and
        // null for a rota that came from no template. isset() is false for
        // null, which is what makes the single check sufficient here.
        $rota = (new RotaFactory())->fromRow(['template_id' => null]);

        expect($rota->templateId())->toBeNull();
    });

    it('leaves the id unset on create and normalises times', function () {
        $rota = (new RotaFactory())->create('2026-07-20', '09:00:00', '17:00:00', 'Day shift', 4);

        expect($rota->id())->toBeNull('A created rota is not yet persisted.')
            ->and($rota->startTime())->toBe('09:00')
            ->and($rota->templateId())->toBe(4);
    });

    // 24:00 is how people write "to the end of the day"; 23:59 is what is
    // kept, so the shift stays on its own date.
    it('stores an end of 24:00 as 23:59', function () {
        expect((new RotaFactory())->create('2026-07-20', '18:00', '24:00')->endTime())->toBe('23:59');
    });

    it('reads a legacy 24:00:00 end column back as 23:59', function () {
        // Slots saved before the rule still hold 24:00:00 in MySQL's TIME
        // column; they must compare equal to anything saved since.
        expect((new RotaFactory())->fromRow(['end_time' => '24:00:00'])->endTime())->toBe('23:59');
    });
});

describe('AssignmentFactory', function () {
    it('maps a full row', function () {
        $assignment = (new AssignmentFactory())->fromRow([
            'id'          => '5',
            'rota_id'     => '12',
            'member_id'   => '99',
            'notes'       => 'Swapped with Jane',
            'assigned_at' => '2026-07-19 10:30:00',
        ]);

        expect($assignment->id())->toBe(5)
            ->and($assignment->rotaId())->toBe(12)
            ->and($assignment->memberId())->toBe('99', 'Member ids stay strings.')
            ->and($assignment->notes())->toBe('Swapped with Jane')
            ->and($assignment->assignedAt())->toBe('2026-07-19 10:30:00');
    });

    it('defaults every missing column', function () {
        $assignment = (new AssignmentFactory())->fromRow([]);

        expect($assignment->id())->toBeNull()
            ->and($assignment->rotaId())->toBe(0)
            ->and($assignment->memberId())->toBe('')
            ->and($assignment->notes())->toBe('')
            ->and($assignment->assignedAt())->toBeNull();
    });

    it('leaves the id and timestamp to the database on create', function () {
        $assignment = (new AssignmentFactory())->create(12, '99', 'Cover');

        expect($assignment->id())->toBeNull()
            ->and($assignment->assignedAt())->toBeNull('assigned_at is set by the insert, not the factory.')
            ->and($assignment->rotaId())->toBe(12)
            ->and($assignment->notes())->toBe('Cover');
    });
});
