<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use Trusted\Template\TemplateParser;

/*
 * Tests for the shift template grammar.
 *
 * The grammar is shared by TemplateApplicator (which turns shifts into rota
 * slots and pre-assigns the named member) and TemplateValidator (which checks
 * those names at save time), so a change here changes both.
 */

beforeEach(function () {
    $this->parser = new TemplateParser();
});

describe('parseLine', function () {
    it('parses the documented line shapes', function (string $line, string $start, string $end, string $label, string $member) {
        $shift = $this->parser->parseLine($line);

        expect($shift)->not->toBeNull("Expected to parse: {$line}")
            ->and($shift->startTime())->toBe($start)
            ->and($shift->endTime())->toBe($end)
            ->and($shift->label())->toBe($label)
            ->and($shift->member())->toBe($member);
    })->with([
        // The four shapes the class docblock advertises, plus the variations
        // the regex deliberately tolerates.
        'times only'              => ['09:00-17:00', '09:00', '17:00', '', ''],
        'times with spaces'       => ['09:00 - 17:00', '09:00', '17:00', '', ''],
        'piped label'             => ['09:00 - 17:00 | Reception', '09:00', '17:00', 'Reception', ''],
        'bare label'              => ['9:00-17:00 Reception', '09:00', '17:00', 'Reception', ''],
        'label and member'        => ['09:00-17:00 | Reception | John D', '09:00', '17:00', 'Reception', 'John D'],
        'single digit hour'       => ['9:00-17:00', '09:00', '17:00', '', ''],
        'both hours single digit' => ['9:00-9:30', '09:00', '09:30', '', ''],
        'en dash separator'       => ['09:00–17:00', '09:00', '17:00', '', ''],
        'leading whitespace'      => ['   09:00-17:00   ', '09:00', '17:00', '', ''],
        'label with inner spaces' => ['09:00-17:00 | Late Evening Cover', '09:00', '17:00', 'Late Evening Cover', ''],
    ]);

    it('rejects lines that are not shifts', function (string $line) {
        expect($this->parser->parseLine($line))->toBeNull("Expected to reject: {$line}");
    })->with([
        'empty'           => [''],
        'whitespace only' => ['   '],
        'prose'           => ['Closed on Sundays'],
        'one time only'   => ['09:00'],
        'no separator'    => ['09:00 17:00'],
        'minutes missing' => ['09-17'],
        'not a time'      => ['0900-1700'],
    ]);

    it('pads hours but leaves minutes alone', function () {
        $shift = $this->parser->parseLine('7:05-9:30');

        expect($shift)->not->toBeNull()
            ->and($shift->startTime())->toBe('07:05')
            ->and($shift->endTime())->toBe('09:30');
    });

    it('leaves a missing label empty rather than inventing one', function () {
        // Deliberate: TemplateValidator rejects nameless lines at save time,
        // which it can only do if the parser reports the absence faithfully.
        $shift = $this->parser->parseLine('09:00-17:00');

        expect($shift)->not->toBeNull()
            ->and($shift->label())->toBe('');
    });
});

describe('parse', function () {
    it('parses a multi-line template and drops the junk', function () {
        $raw = <<<TXT
        09:00-13:00 | Morning | John D

        Closed for lunch
        13:00-17:00 | Afternoon
        TXT;

        $shifts = $this->parser->parse($raw);

        expect($shifts)->toHaveCount(2, 'The blank line and the prose line should be dropped.')
            ->and($shifts[0]->label())->toBe('Morning')
            ->and($shifts[0]->member())->toBe('John D')
            ->and($shifts[1]->label())->toBe('Afternoon')
            ->and($shifts[1]->member())->toBe('', 'A line with no member carries an empty member.');
    });

    it('splits on any line ending', function (string $raw, string $description) {
        expect($this->parser->parse($raw))->toHaveCount(2, $description);
    })->with([
        'unix'    => ["09:00-13:00\n13:00-17:00", 'LF'],
        'windows' => ["09:00-13:00\r\n13:00-17:00", 'CRLF — what a browser textarea submits on Windows'],
        'old mac' => ["09:00-13:00\r13:00-17:00", 'CR'],
    ]);

    it('returns an empty array for an empty template', function () {
        expect($this->parser->parse(''))->toBe([]);
    });
});
