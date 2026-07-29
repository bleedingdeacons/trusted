<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Domain;

use Trusted\Domain\Assignment;
use Trusted\Domain\Rota;
use Trusted\Tests\TestCase;

/**
 * Covers Rota's assignment accessors and array/JSON serialisation.
 *
 * @covers \Trusted\Domain\Rota
 */
final class RotaArrayTest extends TestCase
{
    private function rota(): Rota
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

    public function testWithAssignmentsAndAccessors(): void
    {
        $assignment = new Assignment(id: 1, rotaId: 5, memberId: '7', notes: 'n');
        $rota = $this->rota()->withAssignments([$assignment]);

        self::assertSame(3, $rota->templateId());
        self::assertCount(1, $rota->assignments());
        self::assertSame($assignment, $rota->assignments()[0]);
    }

    public function testToArrayIncludesAssignments(): void
    {
        $assignment = new Assignment(id: 1, rotaId: 5, memberId: '7', notes: 'n');
        $array = $this->rota()->withAssignments([$assignment])->toArray();

        self::assertSame(5, $array['id']);
        self::assertSame('2026-07-20', $array['date']);
        self::assertSame('09:00', $array['start']);
        self::assertSame('Morning', $array['label']);
        self::assertSame(3, $array['template_id']);
        self::assertCount(1, $array['assignments']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $rota = $this->rota();
        self::assertSame($rota->toArray(), $rota->jsonSerialize());
    }
}
