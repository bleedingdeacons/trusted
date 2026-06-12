<?php

declare(strict_types=1);

namespace Trusted\Template;

use Trusted\Domain\Shift;

/**
 * Parses a day's template textarea into Shift objects.
 *
 * One shift per line, whitespace-tolerant, with an optional label and an
 * optional member (the member's Unity anonymous name) as pipe-delimited fields:
 *
 *   09:00-17:00
 *   09:00 - 17:00 | Reception
 *   9:00-17:00 Reception
 *   09:00-17:00 | Reception | John D
 *
 * The grammar is shared by TemplateApplicator (which turns shifts into rota
 * slots and pre-assigns the named member) and TemplateValidator (which checks
 * the named members at save time), so both read templates the same way.
 */
final class TemplateParser
{
    /**
     * @return Shift[]
     */
    public function parse(string $raw): array
    {
        $shifts = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $shift = $this->parseLine($line);

            if ($shift !== null) {
                $shifts[] = $shift;
            }
        }

        return $shifts;
    }

    /**
     * Parse a single line, or return null when it isn't a valid shift line.
     */
    public function parseLine(string $line): ?Shift
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        // Capture two HH:MM times, then the remainder (label and/or member).
        if (! preg_match('/^(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})\s*(.*)$/u', $line, $m)) {
            return null;
        }

        $start = $this->padTime($m[1]);
        $end   = $this->padTime($m[2]);

        // Drop one leading separator (the divider between the times and the
        // label), then split the rest into "label | member" on the pipe.
        $rest  = preg_replace('/^\s*[|–-]\s*/u', '', trim($m[3] ?? '')) ?? '';
        $parts = array_map('trim', explode('|', $rest));

        $label  = $parts[0] ?? '';
        $member = $parts[1] ?? '';

        // A shift always has a name. If a template line omits one, fall back to
        // its time range rather than producing an unnamed slot.
        if ($label === '') {
            $label = $start . '–' . $end;
        }

        return new Shift($start, $end, $label, $member);
    }

    private function padTime(string $time): string
    {
        [$h, $min] = array_pad(explode(':', $time), 2, '00');

        return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($min, 2, '0', STR_PAD_LEFT);
    }
}
