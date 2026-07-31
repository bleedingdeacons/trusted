<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use Brain\Monkey\Functions;
use Trusted\Factory\AssignmentFactory;
use Trusted\Factory\RotaFactory;
use Trusted\Support\ResponderDirectory;
use Trusted\Template\TemplateApplicator;
use Trusted\Template\TemplateFields;
use Trusted\Template\TemplateParser;
use Trusted\Tests\Fixtures\InMemoryAssignmentRepository;
use Trusted\Tests\Fixtures\InMemoryMemberRepository;
use Trusted\Tests\Fixtures\InMemoryRotaRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Trusted\Tests\TestCase;

/**
 * @covers \Trusted\Template\TemplateApplicator
 */
final class TemplateApplicatorTest extends TestCase
{
    private InMemoryRotaRepository $rota;
    private InMemoryAssignmentRepository $assignments;
    private RotaFactory $factory;

    private function build(array $members = []): TemplateApplicator
    {
        $this->factory     = new RotaFactory();
        $this->rota        = new InMemoryRotaRepository();
        $this->assignments = new InMemoryAssignmentRepository();

        return new TemplateApplicator(
            $this->rota,
            $this->factory,
            $this->assignments,
            new AssignmentFactory(),
            new ResponderDirectory(new InMemoryMemberRepository($members)),
            new TemplateParser(),
        );
    }

    /** Return template lines for the Monday field only, '' otherwise. */
    private function mondayShifts(string $lines): void
    {
        Functions\expect('get_post_meta')->andReturnUsing(
            static fn (int $id, string $key, bool $single): string => $key === 'trusted_shifts_mon' ? $lines : ''
        );
    }

    public function testOptionsMapsPostsToTitles(): void
    {
        Functions\expect('get_posts')->andReturn([(object) ['ID' => 3], (object) ['ID' => 4]]);
        Functions\expect('get_the_title')->andReturnUsing(static fn ($p): string => 'Template ' . $p->ID);

        $options = $this->build()->options();
        self::assertSame(['3' => 'Template 3', '4' => 'Template 4'], array_map('strval', $options));
    }

    public function testShiftsForTemplateParsesEachDay(): void
    {
        $this->mondayShifts("09:00-12:00 | Morning");
        $byDay = $this->build()->shiftsForTemplate(5);

        self::assertCount(1, $byDay[1]);          // Monday has one shift
        self::assertSame([], $byDay[2]);          // Tuesday empty
        self::assertSame('Morning', $byDay[1][0]->label());
    }

    public function testApplyCreatesSlotsForTemplateShifts(): void
    {
        $this->mondayShifts("09:00-12:00 | Morning");

        $created = $this->build()->apply(5, '2026-07-20');   // Monday week start
        self::assertCount(1, $created);
        self::assertSame('2026-07-20', $created[0]->slotDate());
        self::assertSame('Morning', $created[0]->label());
    }

    public function testApplySkipsAShiftThatAlreadyExists(): void
    {
        $applicator = $this->build();
        // Pre-seed the identical slot so apply() skips it.
        $this->rota->save($this->factory->create('2026-07-20', '09:00', '12:00', 'Existing'));
        $this->mondayShifts("09:00-12:00 | Morning");

        self::assertSame([], $applicator->apply(5, '2026-07-20'));
    }

    public function testApplyWithReplaceClearsTheWeekFirst(): void
    {
        $applicator = $this->build();
        $this->rota->save($this->factory->create('2026-07-20', '08:00', '09:00', 'Old'));
        $this->mondayShifts("09:00-12:00 | Morning");

        $created = $applicator->apply(5, '2026-07-20', true);
        self::assertCount(1, $created);
        // The old slot at 08:00 was cleared, so only the new one remains.
        self::assertCount(1, $this->rota->findForWeek('2026-07-20'));
    }

    public function testApplyPreAssignsANamedResponder(): void
    {
        $applicator = $this->build([new ResponderStub(id: 7, anonymousName: 'John D')]);
        $this->mondayShifts("09:00-12:00 | Morning | John D");

        $created = $applicator->apply(5, '2026-07-20');
        self::assertCount(1, $created);
        // The member was resolved and an assignment saved.
        self::assertNotEmpty($this->assignments->findByRota((int) $created[0]->id()));
    }

    public function testCreateFromWeekRejectsAnEmptyTitle(): void
    {
        self::assertSame(0, $this->build()->createFromWeek('2026-07-20', '   ', false));
    }

    public function testCreateFromWeekWritesTemplateFields(): void
    {
        $applicator = $this->build();
        $this->rota->save($this->factory->create('2026-07-20', '09:00', '12:00', 'AM'));

        Functions\expect('wp_insert_post')->andReturn(42);
        $written = [];
        Functions\expect('update_post_meta')->andReturnUsing(
            static function (int $id, string $key, string $value) use (&$written): bool {
                $written[$key] = $value;
                return true;
            }
        );

        $id = $applicator->createFromWeek('2026-07-20', 'My Template', false);
        self::assertSame(42, $id);
        self::assertStringContainsString('09:00-12:00 | AM', $written['trusted_shifts_mon']);
    }

    public function testCreateFromWeekIncludesMembers(): void
    {
        $applicator = $this->build();
        $slot = $this->rota->save($this->factory->create('2026-07-20', '09:00', '12:00', 'AM'));
        // Attach an assignment with a member so the "| member" segment is written.
        $assignment = $this->assignments->assignIfOpen((int) $slot->id(), '7', '');
        $member = new \Trusted\Domain\Member('7', 'John D', 'j@x.test', '0700');
        $this->rota->save($slot->withAssignments([$assignment->withMember($member)]));

        Functions\expect('wp_insert_post')->andReturn(9);
        $written = [];
        Functions\expect('update_post_meta')->andReturnUsing(
            static function (int $id, string $key, string $value) use (&$written): bool {
                $written[$key] = $value;
                return true;
            }
        );

        $applicator->createFromWeek('2026-07-20', 'With Members', true);
        self::assertStringContainsString('John D', $written['trusted_shifts_mon']);
    }

    public function testCreateFromWeekReturnsZeroOnInsertFailure(): void
    {
        Functions\expect('wp_insert_post')->andReturn(0);
        self::assertSame(0, $this->build()->createFromWeek('2026-07-20', 'X', false));
    }
}
