<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Domain;

use Trusted\Domain\Assignment;
use Trusted\Domain\Rota;

/*
 * Covers Rota's assignment accessors and array/JSON serialisation.
 */

covers(Rota::class);

function morningRota(): Rota
{
    return new Rota(
        id: 5,
        slotDate: '2026-07-20',
        startTime: '09:00',
        endTime: '12:00',
        label: 'Morning',
        templateId: 3,
    );
}

it('exposes its template id and assignments', function () {
    $assignment = new Assignment(id: 1, rotaId: 5, memberId: '7', notes: 'n');
    $rota = morningRota()->withAssignments([$assignment]);

    expect($rota->templateId())->toBe(3)
        ->and($rota->assignments())->toHaveCount(1)
        ->and($rota->assignments()[0])->toBe($assignment);
});

it('includes its assignments in toArray', function () {
    $assignment = new Assignment(id: 1, rotaId: 5, memberId: '7', notes: 'n');
    $array = morningRota()->withAssignments([$assignment])->toArray();

    expect($array['id'])->toBe(5)
        ->and($array['date'])->toBe('2026-07-20')
        ->and($array['start'])->toBe('09:00')
        ->and($array['label'])->toBe('Morning')
        ->and($array['template_id'])->toBe(3)
        ->and($array['assignments'])->toHaveCount(1);
});

it('shows a stored 23:59 end as 24:00', function () {
    $rota = new Rota(id: 1, slotDate: '2026-07-20', startTime: '18:00', endTime: '23:59', label: 'Late');

    expect($rota->endTime())->toBe('23:59', 'What is stored is unchanged.')
        ->and($rota->toArray()['end'])->toBe('24:00', 'What the calendar shows is the end of the day.');
});

it('serialises to JSON exactly as toArray', function () {
    $rota = morningRota();

    expect($rota->jsonSerialize())->toBe($rota->toArray());
});
