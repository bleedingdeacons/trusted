<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Domain;

use Trusted\Domain\ShiftTime;

/*
 * 24:00 is a display convention, not a time.
 *
 * People think of a shift that runs to midnight as ending at 24:00, so that
 * is what the calendar and the templates show and accept. What is stored is
 * 23:59 — the last minute of the day the shift belongs to — so a shift never
 * appears to end on the following date. Either spelling means the same thing:
 * the end of the day.
 */

covers(ShiftTime::class);

describe('toStored', function () {
    it('stores 24:00 as 23:59', function () {
        expect(ShiftTime::toStored('24:00'))->toBe('23:59');
    });

    it('leaves every other time alone', function (string $time) {
        expect(ShiftTime::toStored($time))->toBe($time);
    })->with(['00:00', '09:30', '23:59', '23:00']);
});

describe('toShown', function () {
    it('shows 23:59 as 24:00', function () {
        expect(ShiftTime::toShown('23:59'))->toBe('24:00');
    });

    it('leaves every other time alone', function (string $time) {
        expect(ShiftTime::toShown($time))->toBe($time);
    })->with(['00:00', '09:30', '24:00', '23:58']);

    it('round-trips an entered 24:00 back to 24:00', function () {
        expect(ShiftTime::toShown(ShiftTime::toStored('24:00')))->toBe('24:00');
    });
});

describe('endMinutes', function () {
    it('counts the end of the day as minute 1440, however it is spelt', function (string $end) {
        expect(ShiftTime::endMinutes($end))->toBe(1440);
    })->with(['23:59', '24:00']);

    it('counts any other end as minutes past midnight', function (string $end, int $minutes) {
        expect(ShiftTime::endMinutes($end))->toBe($minutes);
    })->with([
        'midnight' => ['00:00', 0],
        'morning'  => ['06:00', 360],
        'evening'  => ['23:58', 1438],
    ]);
});

describe('format', function () {
    it('writes minutes past midnight as a zero-padded time', function (int $minutes, string $time) {
        expect(ShiftTime::format($minutes))->toBe($time);
    })->with([
        'midnight'      => [0, '00:00'],
        'single digits' => [65, '01:05'],
        'last minute'   => [1439, '23:59'],
    ]);

    it('writes a full day as 24:00', function () {
        expect(ShiftTime::format(1440))->toBe('24:00');
    });
});
