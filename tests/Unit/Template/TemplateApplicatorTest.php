<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use Brain\Monkey\Functions;
use Trusted\Domain\Member;
use Trusted\Factory\AssignmentFactory;
use Trusted\Factory\RotaFactory;
use Trusted\Support\ResponderDirectory;
use Trusted\Template\TemplateApplicator;
use Trusted\Template\TemplateParser;
use Trusted\Testing\Doubles\InMemoryAssignmentRepository;
use Trusted\Testing\Doubles\InMemoryRotaRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Unity\Members\Interfaces\Member as UnityMember;
use Unity\Testing\Doubles\InMemoryMemberRepository;

covers(TemplateApplicator::class);

/** Return template lines for the Monday field only, '' otherwise. */
function mondayShifts(string $lines): void
{
    Functions\expect('get_post_meta')->andReturnUsing(
        static fn (int $id, string $key, bool $single): string => $key === 'trusted_shifts_mon' ? $lines : ''
    );
}

/**
 * Captures every update_post_meta() write, keyed by meta key.
 *
 * @return \ArrayObject<string, string>
 */
function capturePostMeta(): \ArrayObject
{
    $written = new \ArrayObject();
    Functions\expect('update_post_meta')->andReturnUsing(
        static function (int $id, string $key, string $value) use ($written): bool {
            $written[$key] = $value;
            return true;
        }
    );

    return $written;
}

/**
 * @param list<UnityMember> $members
 */
function templateApplicator(
    InMemoryRotaRepository $rota,
    InMemoryAssignmentRepository $assignments,
    array $members = [],
): TemplateApplicator {
    return new TemplateApplicator(
        $rota,
        new RotaFactory(),
        $assignments,
        new AssignmentFactory(),
        new ResponderDirectory(new InMemoryMemberRepository($members)),
        new TemplateParser(),
    );
}

beforeEach(function () {
    $this->factory     = new RotaFactory();
    $this->rota        = new InMemoryRotaRepository();
    $this->assignments = new InMemoryAssignmentRepository();
    $this->applicator  = templateApplicator($this->rota, $this->assignments);
});

it('maps template posts to their titles for the options list', function () {
    Functions\expect('get_posts')->andReturn([(object) ['ID' => 3], (object) ['ID' => 4]]);
    Functions\expect('get_the_title')->andReturnUsing(static fn ($p): string => 'Template ' . $p->ID);

    expect(array_map('strval', $this->applicator->options()))->toBe(['3' => 'Template 3', '4' => 'Template 4']);
});

it('parses each day of a template', function () {
    mondayShifts('09:00-12:00 | Morning');

    $byDay = $this->applicator->shiftsForTemplate(5);

    expect($byDay[1])->toHaveCount(1)           // Monday has one shift
        ->and($byDay[2])->toBe([])              // Tuesday empty
        ->and($byDay[1][0]->label())->toBe('Morning');
});

describe('apply', function () {
    it("creates slots for the template's shifts", function () {
        mondayShifts('09:00-12:00 | Morning');

        $created = $this->applicator->apply(5, '2026-07-20');   // Monday week start

        expect($created)->toHaveCount(1)
            ->and($created[0]->slotDate())->toBe('2026-07-20')
            ->and($created[0]->label())->toBe('Morning');
    });

    it('skips a shift that already exists', function () {
        // Pre-seed the identical slot so apply() skips it.
        $this->rota->save($this->factory->create('2026-07-20', '09:00', '12:00', 'Existing'));
        mondayShifts('09:00-12:00 | Morning');

        expect($this->applicator->apply(5, '2026-07-20'))->toBe([]);
    });

    it('recognises a 24:00 template shift as the slot it already created', function () {
        // The slot is stored ending 23:59; the template still says 24:00.
        // Re-applying must see them as the same shift, not add a duplicate.
        mondayShifts('18:00-24:00 | Late');

        expect($this->applicator->apply(5, '2026-07-20'))->toHaveCount(1)
            ->and($this->applicator->apply(5, '2026-07-20'))->toBe([])
            ->and($this->rota->findForWeek('2026-07-20'))->toHaveCount(1);
    });

    it('clears the week first when replacing', function () {
        $this->rota->save($this->factory->create('2026-07-20', '08:00', '09:00', 'Old'));
        mondayShifts('09:00-12:00 | Morning');

        expect($this->applicator->apply(5, '2026-07-20', true))->toHaveCount(1)
            // The old slot at 08:00 was cleared, so only the new one remains.
            ->and($this->rota->findForWeek('2026-07-20'))->toHaveCount(1);
    });

    it('pre-assigns a named responder', function () {
        $applicator = templateApplicator($this->rota, $this->assignments, [new ResponderStub(id: 7, anonymousName: 'John D')]);
        mondayShifts('09:00-12:00 | Morning | John D');

        $created = $applicator->apply(5, '2026-07-20');

        expect($created)->toHaveCount(1)
            // The member was resolved and an assignment saved.
            ->and($this->assignments->findByRota((int) $created[0]->id()))->not->toBeEmpty();
    });
});

describe('createFromWeek', function () {
    it('rejects an empty title', function () {
        expect($this->applicator->createFromWeek('2026-07-20', '   ', false))->toBe(0);
    });

    it('writes the template fields', function () {
        $this->rota->save($this->factory->create('2026-07-20', '09:00', '12:00', 'AM'));
        Functions\expect('wp_insert_post')->andReturn(42);
        $written = capturePostMeta();

        expect($this->applicator->createFromWeek('2026-07-20', 'My Template', false))->toBe(42)
            ->and($written['trusted_shifts_mon'])->toContain('09:00-12:00 | AM');
    });

    it('writes a shift running to the end of the day as 24:00', function () {
        $this->rota->save($this->factory->create('2026-07-20', '18:00', '24:00', 'Late'));
        Functions\expect('wp_insert_post')->andReturn(42);
        $written = capturePostMeta();

        $this->applicator->createFromWeek('2026-07-20', 'Evenings', false);

        expect($written['trusted_shifts_mon'])->toContain('18:00-24:00 | Late')
            ->not->toContain('23:59');
    });

    it('includes members when asked', function () {
        $slot = $this->rota->save($this->factory->create('2026-07-20', '09:00', '12:00', 'AM'));
        // Attach an assignment with a member so the "| member" segment is written.
        $assignment = $this->assignments->assignIfOpen((int) $slot->id(), '7', '');
        $member = new Member('7', 'John D', 'j@x.test', '0700');
        $this->rota->save($slot->withAssignments([$assignment->withMember($member)]));

        Functions\expect('wp_insert_post')->andReturn(9);
        $written = capturePostMeta();

        $this->applicator->createFromWeek('2026-07-20', 'With Members', true);

        expect($written['trusted_shifts_mon'])->toContain('John D');
    });

    it('returns zero when the insert fails', function () {
        Functions\expect('wp_insert_post')->andReturn(0);

        expect($this->applicator->createFromWeek('2026-07-20', 'X', false))->toBe(0);
    });
});
