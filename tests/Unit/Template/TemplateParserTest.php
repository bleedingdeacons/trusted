<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Trusted\Template\TemplateParser;

/**
 * Tests for the shift template grammar.
 *
 * The grammar is shared by TemplateApplicator (which turns shifts into rota
 * slots and pre-assigns the named member) and TemplateValidator (which checks
 * those names at save time), so a change here changes both.
 */
final class TemplateParserTest extends TestCase
{
    private TemplateParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TemplateParser();
    }

    /**
     * @test
     * @dataProvider lineProvider
     */
    public function it_parses_the_documented_line_shapes(
        string $line,
        string $start,
        string $end,
        string $label,
        string $member
    ): void {
        $shift = $this->parser->parseLine($line);

        self::assertNotNull($shift, "Expected to parse: {$line}");
        self::assertSame($start, $shift->startTime());
        self::assertSame($end, $shift->endTime());
        self::assertSame($label, $shift->label());
        self::assertSame($member, $shift->member());
    }

    /**
     * The four shapes the class docblock advertises, plus the variations the
     * regex deliberately tolerates.
     *
     * @return array<string, array{0:string,1:string,2:string,3:string,4:string}>
     */
    public static function lineProvider(): array
    {
        return [
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
        ];
    }

    /**
     * @test
     * @dataProvider rejectedProvider
     */
    public function it_rejects_lines_that_are_not_shifts(string $line): void
    {
        self::assertNull($this->parser->parseLine($line), "Expected to reject: {$line}");
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'empty'            => [''],
            'whitespace only'  => ['   '],
            'prose'            => ['Closed on Sundays'],
            'one time only'    => ['09:00'],
            'no separator'     => ['09:00 17:00'],
            'minutes missing'  => ['09-17'],
            'not a time'       => ['0900-1700'],
        ];
    }

    /**
     * @test
     */
    public function it_pads_hours_but_leaves_minutes_alone(): void
    {
        $shift = $this->parser->parseLine('7:05-9:30');

        self::assertNotNull($shift);
        self::assertSame('07:05', $shift->startTime());
        self::assertSame('09:30', $shift->endTime());
    }

    /**
     * @test
     */
    public function it_parses_a_multi_line_template_and_drops_the_junk(): void
    {
        $raw = <<<TXT
        09:00-13:00 | Morning | John D

        Closed for lunch
        13:00-17:00 | Afternoon
        TXT;

        $shifts = $this->parser->parse($raw);

        self::assertCount(2, $shifts, 'The blank line and the prose line should be dropped.');
        self::assertSame('Morning', $shifts[0]->label());
        self::assertSame('John D', $shifts[0]->member());
        self::assertSame('Afternoon', $shifts[1]->label());
        self::assertSame('', $shifts[1]->member(), 'A line with no member carries an empty member.');
    }

    /**
     * @test
     * @dataProvider lineEndingProvider
     */
    public function it_splits_on_any_line_ending(string $raw, string $description): void
    {
        self::assertCount(2, $this->parser->parse($raw), $description);
    }

    /**
     * @return array<string, array{0:string,1:string}>
     */
    public static function lineEndingProvider(): array
    {
        return [
            'unix'    => ["09:00-13:00\n13:00-17:00", 'LF'],
            'windows' => ["09:00-13:00\r\n13:00-17:00", 'CRLF — what a browser textarea submits on Windows'],
            'old mac' => ["09:00-13:00\r13:00-17:00", 'CR'],
        ];
    }

    /**
     * @test
     */
    public function it_leaves_a_missing_label_empty_rather_than_inventing_one(): void
    {
        // Deliberate: TemplateValidator rejects nameless lines at save time,
        // which it can only do if the parser reports the absence faithfully.
        $shift = $this->parser->parseLine('09:00-17:00');

        self::assertNotNull($shift);
        self::assertSame('', $shift->label());
    }

    /**
     * @test
     */
    public function it_returns_an_empty_array_for_an_empty_template(): void
    {
        self::assertSame([], $this->parser->parse(''));
    }
}
