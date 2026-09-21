<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use Brain\Monkey\Functions;
use Trusted\Template\TemplateFields;

covers(TemplateFields::class);

beforeEach(function () {
    $_POST = [];
    $GLOBALS['trusted_acf_groups'] = [];
});

afterEach(function () {
    $_POST = [];
});

it('prefixes field keys', function () {
    expect(TemplateFields::fieldKey('trusted_shifts_mon'))->toBe('field_trusted_shifts_mon');
});

it('maps the day fields to ISO weekdays', function () {
    expect(TemplateFields::DAY_FIELDS['trusted_shifts_mon'])->toBe(1)
        ->and(TemplateFields::DAY_FIELDS['trusted_shifts_sun'])->toBe(7);
});

it('builds the field group', function () {
    // acf_add_local_field_group is defined (test stub), so register() runs
    // its full body rather than the ACF-absent early return.
    (new TemplateFields())->register();

    expect($GLOBALS['trusted_acf_groups'])->not->toBeEmpty()
        ->and($GLOBALS['trusted_acf_groups'][0]['key'])->toBe('group_trusted_template')
        // Help message field + 7 day textareas.
        ->and($GLOBALS['trusted_acf_groups'][0]['fields'])->toHaveCount(8);
});

describe('validateTemplateName', function () {
    it('ignores other post types', function () {
        $_POST = ['post_type' => 'post', 'post_title' => ''];
        Functions\expect('acf_add_validation_error')->never();

        (new TemplateFields())->validateTemplateName();
    });

    it('rejects an empty title', function () {
        $_POST = ['post_type' => TRUSTED_TEMPLATE_POST_TYPE, 'post_title' => '   '];
        Functions\expect('acf_add_validation_error')->once();

        (new TemplateFields())->validateTemplateName();
    });

    it('accepts a non-empty title', function () {
        $_POST = ['post_type' => TRUSTED_TEMPLATE_POST_TYPE, 'post_title' => 'My Template'];
        Functions\expect('acf_add_validation_error')->never();

        (new TemplateFields())->validateTemplateName();
    });
});
