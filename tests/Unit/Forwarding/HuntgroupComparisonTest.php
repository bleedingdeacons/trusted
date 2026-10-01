<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Forwarding;

use Beacon\Forwarding\Models\ForwardingRule;
use Trusted\Forwarding\HuntgroupComparison;

/*
 * Comparing the rules a week of the rota projects to with the rules a hunt
 * group holds. Only where a call goes is compared: day, window, destination,
 * and whether the row is switched on.
 */

covers(HuntgroupComparison::class);

/** @param list<string> $days */
function huntRule(int $priority, string $targetId, array $days, string $from, string $to, string $label = '', bool $enabled = true): ForwardingRule
{
    return new ForwardingRule([
        'id'        => (string) $priority,
        'priority'  => $priority,
        'label'     => $label,
        'match'     => ['type' => 'time_window', 'value' => ['days' => $days, 'from' => $from, 'to' => $to]],
        'target_id' => $targetId,
        'enabled'   => $enabled,
    ]);
}

/** @return list<ForwardingRule> */
function rotaRules(): array
{
    return [
        huntRule(1, 'num:07700900123', ['mon'], '10:00', '14:00', 'Anon A'),
        huntRule(2, 'vm:default', ['mon'], '14:00', '18:00', 'Voicemail'),
        huntRule(3, 'num:07700900456', ['tue'], '10:00', '14:00', 'Anon B'),
    ];
}

beforeEach(function () {
    $this->compare = fn (array $expected, array $actual): array
        => (new HuntgroupComparison())->compare($expected, $actual);
});

it('finds nothing to report when the hunt group forwards exactly as the rota does', function () {
    expect(($this->compare)(rotaRules(), rotaRules()))->toBe([]);
});

it('ignores labels, row order and number spacing', function () {
    $held = [
        huntRule(1, 'num:07700900456', ['tue'], '10:00', '14:00', 'Someone'),
        huntRule(2, 'vm:20042', ['mon'], '14:00', '18:00', ''),
        huntRule(3, 'num:07700 900 123', ['mon'], '10:00', '14:00', 'Steve'),
    ];

    expect(($this->compare)(rotaRules(), $held))->toBe([]);
});

it('reads an international number as its national form', function (string $held) {
    expect(($this->compare)(
        [huntRule(1, 'num:07700900123', ['mon'], '10:00', '14:00')],
        [huntRule(1, 'num:' . $held, ['mon'], '10:00', '14:00')],
    ))->toBe([]);
})->with(['+44 7700 900123', '0044 7700 900123', '447700900123']);

it('splits a row ticked for several days into one window a day', function () {
    $expected = [
        huntRule(1, 'num:07700900123', ['mon'], '10:00', '14:00'),
        huntRule(2, 'num:07700900123', ['tue'], '10:00', '14:00'),
    ];

    expect(($this->compare)($expected, [huntRule(1, 'num:07700900123', ['mon', 'tue'], '10:00', '14:00')]))->toBe([]);
});

it('treats 24:00, single-digit hours and seconds as the same minute', function () {
    expect(($this->compare)(
        [huntRule(1, 'num:07700900123', ['mon'], '09:00', '24:00')],
        [huntRule(1, 'num:07700900123', ['mon'], '9:00:00', '23:59')],
    ))->toBe([]);
});

it('reports a different destination for the same window', function () {
    $held = rotaRules();
    $held[0] = huntRule(1, 'vm:20042', ['mon'], '10:00', '14:00', '');

    expect(($this->compare)(rotaRules(), $held))->toBe([[
        'kind'     => HuntgroupComparison::DESTINATION,
        'day'      => 'mon',
        'from'     => '10:00',
        'to'       => '14:00',
        'expected' => ['destination' => '07700900123', 'label' => 'Anon A'],
        'actual'   => ['destination' => 'voicemail', 'label' => ''],
    ]]);
});

it('reports a rota window the hunt group has no row for', function () {
    $held = rotaRules();
    unset($held[2]);

    expect(($this->compare)(rotaRules(), $held))->toBe([[
        'kind'     => HuntgroupComparison::MISSING,
        'day'      => 'tue',
        'from'     => '10:00',
        'to'       => '14:00',
        'expected' => ['destination' => '07700900456', 'label' => 'Anon B'],
        'actual'   => null,
    ]]);
});

it('reports a hunt group row the rota has no shift for', function () {
    $held   = rotaRules();
    $held[] = huntRule(4, 'num:01454898476', ['wed'], '09:00', '12:00', 'Steve C');

    expect(($this->compare)(rotaRules(), $held))->toBe([[
        'kind'     => HuntgroupComparison::EXTRA,
        'day'      => 'wed',
        'from'     => '09:00',
        'to'       => '12:00',
        'expected' => null,
        'actual'   => ['destination' => '01454898476', 'label' => 'Steve C'],
    ]]);
});

it('reports a matching row that is switched off, and ignores a switched-off row the rota does not need', function () {
    $held    = rotaRules();
    $held[0] = huntRule(1, 'num:07700900123', ['mon'], '10:00', '14:00', 'Anon A', enabled: false);
    $held[]  = huntRule(4, 'num:01454898476', ['wed'], '09:00', '12:00', 'Steve C', enabled: false);

    expect(($this->compare)(rotaRules(), $held))->toHaveCount(1)
        ->sequence(fn ($d) => $d->kind->toBe(HuntgroupComparison::DISABLED)->day->toBe('mon'));
});

it('pairs duplicate windows by destination before reporting a mismatch', function () {
    $expected = [
        huntRule(1, 'num:07700900123', ['mon'], '10:00', '14:00'),
        huntRule(2, 'num:07700900456', ['mon'], '10:00', '14:00'),
    ];
    $held = [
        huntRule(1, 'num:07700900456', ['mon'], '10:00', '14:00'),
        huntRule(2, 'num:07700900123', ['mon'], '10:00', '14:00'),
    ];

    expect(($this->compare)($expected, $held))->toBe([]);
});

it('reports everything for a week the hunt group does not have at all', function () {
    expect(($this->compare)(rotaRules(), []))->toHaveCount(3)
        ->each(fn ($d) => $d->kind->toBe(HuntgroupComparison::MISSING));
});

it('lists differences in weekday then time order', function () {
    $held = [
        huntRule(1, 'num:1', ['sun'], '08:00', '09:00'),
        huntRule(2, 'num:1', ['mon'], '20:00', '21:00'),
        huntRule(3, 'num:1', ['mon'], '06:00', '07:00'),
    ];

    expect(array_map(fn (array $d): string => $d['day'] . ' ' . $d['from'], ($this->compare)([], $held)))
        ->toBe(['mon 06:00', 'mon 20:00', 'sun 08:00']);
});

it('reduces a target id to a comparable destination', function (string $targetId, string $destination) {
    expect(HuntgroupComparison::destination($targetId))->toBe($destination);
})->with([
    'any voicemail box' => ['vm:20042', 'voicemail'],
    'the default box'   => ['vm:default', 'voicemail'],
    'a spaced number'   => ['num:01454 898476', '01454898476'],
    'an empty number'   => ['num:', ''],
    'a queue'           => ['queue:default', 'queue:default'],
]);
