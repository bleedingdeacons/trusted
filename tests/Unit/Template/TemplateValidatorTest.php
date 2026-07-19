<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use Trusted\Support\ResponderDirectory;
use Trusted\Template\TemplateFields;
use Trusted\Template\TemplateParser;
use Trusted\Template\TemplateValidator;
use Trusted\Tests\Fixtures\InMemoryMemberRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Trusted\Tests\TestCase;
use Unity\Members\Interfaces\Member as UnityMember;
use WP_Mock;

/**
 * Tests for template save-time validation.
 *
 * This is what stops a template carrying a member name that will not resolve
 * when the template is later applied: every shift must be named, and any
 * member named must be a Unity member who is a telephone responder.
 * Reporting an error against the day field is what blocks the ACF save.
 */
final class TemplateValidatorTest extends TestCase
{
    private const MON_KEY = 'field_trusted_shifts_mon';

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];

        // Translation is a pass-through here; the assertions are about which
        // field an error lands on and which branch produced it, not wording.
        WP_Mock::userFunction('__')->andReturnUsing(static fn (string $text): string => $text);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /**
     * @test
     */
    public function it_does_nothing_when_acf_is_not_present(): void
    {
        // validate() returns early unless acf_add_validation_error exists, so a
        // non-ACF request cannot blow up. No expectation is registered for it,
        // so any call would fail this test.
        $_POST['acf'] = [self::MON_KEY => '09:00-17:00 | Morning | John D'];

        $this->makeValidator([new ResponderStub(anonymousName: 'John D')])->validate();

        self::assertTrue(true, 'validate() completed without reaching ACF.');
    }

    /**
     * @test
     */
    public function it_ignores_forms_that_are_not_ours(): void
    {
        WP_Mock::userFunction('acf_add_validation_error')->never();

        // No acf payload at all — some other form is saving.
        $this->makeValidator([new ResponderStub(anonymousName: 'John D')])->validate();

        self::assertTrue(true, 'A foreign form is left alone.');
    }

    /**
     * @test
     */
    public function it_accepts_a_template_naming_a_telephone_responder(): void
    {
        WP_Mock::userFunction('acf_add_validation_error')->never();

        $_POST['acf'] = [self::MON_KEY => '09:00-17:00 | Morning | John D'];

        $this->makeValidator([new ResponderStub(anonymousName: 'John D')])->validate();

        self::assertTrue(true, 'A valid responder raises no error.');
    }

    /**
     * @test
     */
    public function it_blocks_a_save_naming_a_member_who_is_not_a_responder(): void
    {
        $captured = [];
        WP_Mock::userFunction('acf_add_validation_error')
            ->once()
            ->andReturnUsing(function (string $field, string $message) use (&$captured): void {
                $captured = ['field' => $field, 'message' => $message];
            });

        // Jane is a real member but not a telephone responder.
        $members = [new ResponderStub(id: 2, telephoneResponder: false, anonymousName: 'Jane S')];

        $_POST['acf'] = [self::MON_KEY => '09:00-17:00 | Morning | Jane S'];

        $this->makeValidator($members)->validate();

        self::assertSame(
            'acf[' . self::MON_KEY . ']',
            $captured['field'],
            'The error ties to the offending day field, which is what blocks the save.'
        );
        self::assertStringContainsString('not a telephone responder', $captured['message']);
    }

    /**
     * @test
     */
    public function it_distinguishes_an_unknown_name_from_a_non_responder(): void
    {
        // A typo and a real-but-ineligible member need different advice.
        $message = '';
        WP_Mock::userFunction('acf_add_validation_error')
            ->once()
            ->andReturnUsing(function (string $field, string $text) use (&$message): void {
                $message = $text;
            });

        $_POST['acf'] = [self::MON_KEY => '09:00-17:00 | Morning | Jhon D'];

        $this->makeValidator([new ResponderStub(anonymousName: 'John D')])->validate();

        self::assertStringContainsString('No member is named', $message);
        self::assertStringContainsString('Check the spelling', $message);
    }

    /**
     * @test
     */
    public function it_reports_a_missing_shift_name_once_per_day(): void
    {
        // Three nameless lines, one message: the save is blocked without
        // burying the operator in repeats.
        $messages = [];
        WP_Mock::userFunction('acf_add_validation_error')
            ->once()
            ->andReturnUsing(function (string $field, string $text) use (&$messages): void {
                $messages[] = $text;
            });

        $_POST['acf'] = [self::MON_KEY => "09:00-10:00\n10:00-11:00\n11:00-12:00"];

        $this->makeValidator()->validate();

        self::assertCount(1, $messages);
        self::assertStringContainsString('Every shift needs a name', $messages[0]);
    }

    /**
     * @test
     */
    public function it_matches_names_case_insensitively(): void
    {
        WP_Mock::userFunction('acf_add_validation_error')->never();

        $_POST['acf'] = [
            self::MON_KEY => "09:00-10:00 | A | John D\n10:00-11:00 | B | john d\n11:00-12:00 | C | JOHN D",
        ];

        $this->makeValidator([new ResponderStub(anonymousName: 'John D')])->validate();

        self::assertTrue(true, 'One responder satisfies the same name in three casings.');
    }

    /**
     * @test
     */
    public function it_matches_names_with_surrounding_whitespace(): void
    {
        WP_Mock::userFunction('acf_add_validation_error')->never();

        $_POST['acf'] = [self::MON_KEY => '09:00-17:00 | Morning |    John D   '];

        $this->makeValidator([new ResponderStub(anonymousName: 'John D')])->validate();

        self::assertTrue(true, 'Names are trimmed before matching.');
    }

    /**
     * @test
     */
    public function it_validates_every_day_field_that_was_submitted(): void
    {
        $fields = [];
        WP_Mock::userFunction('acf_add_validation_error')
            ->twice()
            ->andReturnUsing(function (string $field, string $text) use (&$fields): void {
                $fields[] = $field;
            });

        $_POST['acf'] = [
            TemplateFields::fieldKey('trusted_shifts_mon') => '09:00-17:00 | Morning | Ghost',
            TemplateFields::fieldKey('trusted_shifts_wed') => '09:00-17:00 | Midweek | Ghost',
        ];

        $this->makeValidator()->validate();

        self::assertCount(2, $fields, 'Each submitted day is validated independently.');
        self::assertNotSame($fields[0], $fields[1], 'Errors land on their own day fields.');
    }

    /**
     * @test
     */
    public function it_leaves_unsubmitted_days_alone(): void
    {
        // Only Monday was submitted; the other six day fields must not be
        // invented or reported on.
        $fields = [];
        WP_Mock::userFunction('acf_add_validation_error')
            ->once()
            ->andReturnUsing(function (string $field, string $text) use (&$fields): void {
                $fields[] = $field;
            });

        $_POST['acf'] = [TemplateFields::fieldKey('trusted_shifts_mon') => '09:00-17:00 | Morning | Ghost'];

        $this->makeValidator()->validate();

        self::assertCount(1, $fields);
    }

    /**
     * ResponderDirectory is final, so it is driven for real through a fake
     * repository rather than mocked. That exercises its actual name matching
     * — trimmed, case-insensitive, first match wins — instead of a stubbed
     * approximation of it.
     *
     * @param UnityMember[] $members
     */
    private function makeValidator(array $members = []): TemplateValidator
    {
        return new TemplateValidator(
            new ResponderDirectory(new InMemoryMemberRepository($members)),
            new TemplateParser(),
        );
    }
}
