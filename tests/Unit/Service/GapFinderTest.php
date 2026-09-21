<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Service;

use Trusted\Domain\Rota;
use Trusted\Factory\RotaFactory;
use Trusted\Service\GapFinder;

/*
 * The uncovered stretches of a telephone day.
 *
 * A day runs 00:00 to 24:00. Each shift covers [start, end). A gap is time no
 * shift covers — the calendar shows it so an organiser can see where the
 * phone is unmanned and double-click to fill it.
 *
 * A shift whose end is at or before its start runs overnight: it covers the
 * rest of its own day and carries on into the next one. The following day's
 * first gap therefore starts when that shift ends, not at midnight — the
 * phone is still covered until then.
 *
 * An end of 23:59 is the end of the day (it is how 24:00 is stored), so it
 * never leaves a one-minute gap and never runs overnight.
 */

covers(GapFinder::class);

/** A slot built the way the plugin builds them, so storage rules apply. */
function shift(string $start, string $end, string $date = '2026-07-21'): Rota
{
    return (new RotaFactory())->create($date, $start, $end, 'Shift');
}

/**
 * @param list<Rota> $slots
 * @param list<Rota> $previousDay
 * @return list<string> gaps as "start-end", for readable expectations
 */
function gapsOf(array $slots, array $previousDay = []): array
{
    return array_map(
        static fn (array $gap): string => $gap['start'] . '-' . $gap['end'],
        (new GapFinder())->forDay($slots, $previousDay)
    );
}

describe('within a single day', function () {
    it('reports the whole day as a gap when there are no shifts', function () {
        expect(gapsOf([]))->toBe(['00:00-24:00']);
    });

    it('reports the time before, between and after shifts', function () {
        expect(gapsOf([shift('09:00', '12:00'), shift('13:00', '17:00')]))
            ->toBe(['00:00-09:00', '12:00-13:00', '17:00-24:00']);
    });

    it('reports nothing for a day that is fully covered', function () {
        expect(gapsOf([shift('00:00', '12:00'), shift('12:00', '24:00')]))->toBe([]);
    });

    it('absorbs overlapping shifts rather than reporting them', function () {
        expect(gapsOf([shift('09:00', '14:00'), shift('12:00', '17:00')]))
            ->toBe(['00:00-09:00', '17:00-24:00']);
    });

    it('does not depend on the order the shifts are given in', function () {
        expect(gapsOf([shift('13:00', '17:00'), shift('09:00', '12:00')]))
            ->toBe(['00:00-09:00', '12:00-13:00', '17:00-24:00']);
    });

    it('returns each gap as a start and end pair', function () {
        expect((new GapFinder())->forDay([shift('09:00', '24:00')]))
            ->toBe([['start' => '00:00', 'end' => '09:00']]);
    });
});

describe('the end of the day', function () {
    it('treats an end of 24:00 as reaching the end of the day', function () {
        expect(gapsOf([shift('18:00', '24:00')]))->toBe(['00:00-18:00']);
    });

    it('treats an end of 23:59 exactly as 24:00, leaving no one-minute gap', function () {
        expect(gapsOf([shift('18:00', '23:59')]))->toBe(['00:00-18:00']);
    });

    it('shows the end of the day as 24:00 in a trailing gap', function () {
        expect(gapsOf([shift('09:00', '17:00')]))->toContain('17:00-24:00');
    });

    it('never treats a shift ending at the end of the day as overnight', function () {
        $previousDay = [shift('18:00', '23:59', '2026-07-20')];

        expect(gapsOf([], $previousDay))->toBe(['00:00-24:00']);
    });
});

describe('a shift that carries over midnight', function () {
    it('covers the rest of its own day', function () {
        expect(gapsOf([shift('09:00', '17:00'), shift('22:00', '06:00')]))
            ->toBe(['00:00-09:00', '17:00-22:00']);
    });

    it("starts the following day's first gap when it ends", function () {
        $previousDay = [shift('22:00', '06:00', '2026-07-20')];

        expect(gapsOf([shift('09:00', '17:00')], $previousDay))
            ->toBe(['06:00-09:00', '17:00-24:00']);
    });

    it('leaves the rest of an otherwise empty following day as one gap', function () {
        $previousDay = [shift('22:00', '06:00', '2026-07-20')];

        expect(gapsOf([], $previousDay))->toBe(['06:00-24:00']);
    });

    it('leaves no gap when the following day picks up before it ends', function () {
        $previousDay = [shift('22:00', '06:00', '2026-07-20')];

        expect(gapsOf([shift('05:00', '24:00')], $previousDay))->toBe([]);
    });

    it('leaves no gap when the following day picks up exactly as it ends', function () {
        $previousDay = [shift('22:00', '06:00', '2026-07-20')];

        expect(gapsOf([shift('06:00', '24:00')], $previousDay))->toBe([]);
    });

    it('lets the latest of several overnight shifts decide', function () {
        // The later-ending shift is listed first, so "latest" cannot be
        // confused with "last in the list".
        $previousDay = [shift('23:00', '07:00', '2026-07-20'), shift('20:00', '02:00', '2026-07-20')];

        expect(gapsOf([], $previousDay))->toBe(['07:00-24:00']);
    });

    it('carries nothing over when it ends at 00:00', function () {
        $previousDay = [shift('18:00', '00:00', '2026-07-20')];

        expect(gapsOf([], $previousDay))->toBe(['00:00-24:00']);
    });

    it('carries a 24-hour shift over to the time it started', function () {
        $previousDay = [shift('08:00', '08:00', '2026-07-20')];

        expect(gapsOf([], $previousDay))->toBe(['08:00-24:00']);
    });

    it('ignores daytime shifts on the previous day', function () {
        $previousDay = [shift('09:00', '17:00', '2026-07-20')];

        expect(gapsOf([], $previousDay))->toBe(['00:00-24:00']);
    });

    it('carries over only one day, not onward', function () {
        // A shift carries into the following day and stops there: it has no
        // bearing on the day after that, which is judged on its own shifts
        // and the overnight shifts of the day immediately before it.
        $monday  = [shift('22:00', '06:00', '2026-07-20')];
        $tuesday = [shift('09:00', '17:00', '2026-07-21')];

        expect(gapsOf($tuesday, $monday))->toBe(['06:00-09:00', '17:00-24:00'])
            ->and(gapsOf([], $tuesday))->toBe(['00:00-24:00']);
    });
});
