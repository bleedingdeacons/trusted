<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use Brain\Monkey\Functions;
use Trusted\Support\ResponderDirectory;
use Trusted\Template\TemplateFields;
use Trusted\Template\TemplateParser;
use Trusted\Template\TemplateValidator;
use Trusted\Tests\Fixtures\ResponderStub;
use Unity\Members\Interfaces\Member as UnityMember;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/*
 * Tests for template save-time validation.
 *
 * This is what stops a template carrying a member name that will not resolve
 * when the template is later applied: every shift must be named, and any
 * member named must be a Unity member who is a telephone responder.
 * Reporting an error against the day field is what blocks the ACF save.
 *
 * Translation is a pass-through here; the assertions are about which field
 * an error lands on and which branch produced it, not wording.
 */

const MON_KEY = 'field_trusted_shifts_mon';

/**
 * ResponderDirectory is final, so it is driven for real through a fake
 * repository rather than mocked. That exercises its actual name matching
 * — trimmed, case-insensitive, first match wins — instead of a stubbed
 * approximation of it.
 *
 * @param UnityMember[] $members
 */
function templateValidator(array $members = []): TemplateValidator
{
    return new TemplateValidator(
        new ResponderDirectory(new InMemoryMemberRepository($members)),
        new TemplateParser(),
    );
}

/**
 * Expects acf_add_validation_error() the given number of times and records
 * each call's field and message.
 *
 * @return \ArrayObject<int, array{field: string, message: string}>
 */
function captureValidationErrors(int $times): \ArrayObject
{
    $errors = new \ArrayObject();
    Functions\expect('acf_add_validation_error')
        ->times($times)
        ->andReturnUsing(static function (string $field, string $message) use ($errors): void {
            $errors[] = ['field' => $field, 'message' => $message];
        });

    return $errors;
}

beforeEach(function () {
    $_POST = [];
});

afterEach(function () {
    $_POST = [];
});

it('does nothing when ACF is not present', function () {
    // validate() returns early unless acf_add_validation_error exists, so a
    // non-ACF request cannot blow up. No expectation is registered for it,
    // so any call would fail this test.
    $_POST['acf'] = [MON_KEY => '09:00-17:00 | Morning | John D'];

    templateValidator([new ResponderStub(anonymousName: 'John D')])->validate();
})->throwsNoExceptions();

it('ignores forms that are not ours', function () {
    Functions\expect('acf_add_validation_error')->never();

    // No acf payload at all — some other form is saving.
    templateValidator([new ResponderStub(anonymousName: 'John D')])->validate();
});

it('accepts a template naming a telephone responder', function () {
    Functions\expect('acf_add_validation_error')->never();

    $_POST['acf'] = [MON_KEY => '09:00-17:00 | Morning | John D'];

    templateValidator([new ResponderStub(anonymousName: 'John D')])->validate();
});

it('blocks a save naming a member who is not a responder', function () {
    $errors = captureValidationErrors(1);

    // Jane is a real member but not a telephone responder.
    $_POST['acf'] = [MON_KEY => '09:00-17:00 | Morning | Jane S'];

    templateValidator([new ResponderStub(id: 2, telephoneResponder: false, anonymousName: 'Jane S')])->validate();

    expect($errors[0]['field'])->toBe(
        'acf[' . MON_KEY . ']',
        'The error ties to the offending day field, which is what blocks the save.'
    )->and($errors[0]['message'])->toContain('not a telephone responder');
});

it('distinguishes an unknown name from a non-responder', function () {
    // A typo and a real-but-ineligible member need different advice.
    $errors = captureValidationErrors(1);

    $_POST['acf'] = [MON_KEY => '09:00-17:00 | Morning | Jhon D'];

    templateValidator([new ResponderStub(anonymousName: 'John D')])->validate();

    expect($errors[0]['message'])->toContain('No member is named', 'Check the spelling');
});

it('reports a missing shift name once per day', function () {
    // Three nameless lines, one message: the save is blocked without
    // burying the operator in repeats.
    $errors = captureValidationErrors(1);

    $_POST['acf'] = [MON_KEY => "09:00-10:00\n10:00-11:00\n11:00-12:00"];

    templateValidator()->validate();

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['message'])->toContain('Every shift needs a name');
});

it('matches names case-insensitively', function () {
    // One responder satisfies the same name in three casings.
    Functions\expect('acf_add_validation_error')->never();

    $_POST['acf'] = [
        MON_KEY => "09:00-10:00 | A | John D\n10:00-11:00 | B | john d\n11:00-12:00 | C | JOHN D",
    ];

    templateValidator([new ResponderStub(anonymousName: 'John D')])->validate();
});

it('matches names with surrounding whitespace', function () {
    // Names are trimmed before matching.
    Functions\expect('acf_add_validation_error')->never();

    $_POST['acf'] = [MON_KEY => '09:00-17:00 | Morning |    John D   '];

    templateValidator([new ResponderStub(anonymousName: 'John D')])->validate();
});

it('validates every day field that was submitted', function () {
    $errors = captureValidationErrors(2);

    $_POST['acf'] = [
        TemplateFields::fieldKey('trusted_shifts_mon') => '09:00-17:00 | Morning | Ghost',
        TemplateFields::fieldKey('trusted_shifts_wed') => '09:00-17:00 | Midweek | Ghost',
    ];

    templateValidator()->validate();

    expect($errors)->toHaveCount(2, 'Each submitted day is validated independently.')
        ->and($errors[0]['field'])->not->toBe($errors[1]['field'], 'Errors land on their own day fields.');
});

it('leaves unsubmitted days alone', function () {
    // Only Monday was submitted; the other six day fields must not be
    // invented or reported on.
    $errors = captureValidationErrors(1);

    $_POST['acf'] = [TemplateFields::fieldKey('trusted_shifts_mon') => '09:00-17:00 | Morning | Ghost'];

    templateValidator()->validate();

    expect($errors)->toHaveCount(1);
});
