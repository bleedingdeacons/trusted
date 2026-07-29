<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use Trusted\Template\TemplateFields;
use Trusted\Tests\TestCase;
use WP_Mock;

/**
 * @covers \Trusted\Template\TemplateFields
 */
final class TemplateFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::userFunction('__')->andReturnUsing(static fn (string $t): string => $t);
        $_POST = [];
        $GLOBALS['trusted_acf_groups'] = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public function testFieldKeyPrefixes(): void
    {
        self::assertSame('field_trusted_shifts_mon', TemplateFields::fieldKey('trusted_shifts_mon'));
    }

    public function testDayFieldsMapToIsoWeekdays(): void
    {
        self::assertSame(1, TemplateFields::DAY_FIELDS['trusted_shifts_mon']);
        self::assertSame(7, TemplateFields::DAY_FIELDS['trusted_shifts_sun']);
    }

    public function testRegisterBuildsTheFieldGroup(): void
    {
        // acf_add_local_field_group is defined (test stub), so register() runs
        // its full body rather than the ACF-absent early return.
        (new TemplateFields())->register();

        self::assertNotEmpty($GLOBALS['trusted_acf_groups']);
        $group = $GLOBALS['trusted_acf_groups'][0];
        self::assertSame('group_trusted_template', $group['key']);
        // Help message field + 7 day textareas.
        self::assertCount(8, $group['fields']);
    }

    public function testValidateTemplateNameIgnoresOtherPostTypes(): void
    {
        $_POST = ['post_type' => 'post', 'post_title' => ''];
        WP_Mock::userFunction('acf_add_validation_error')->never();

        (new TemplateFields())->validateTemplateName();
        self::assertTrue(true);
    }

    public function testValidateTemplateNameRejectsAnEmptyTitle(): void
    {
        $_POST = ['post_type' => TRUSTED_TEMPLATE_POST_TYPE, 'post_title' => '   '];
        WP_Mock::userFunction('acf_add_validation_error')->once();

        (new TemplateFields())->validateTemplateName();
        self::assertTrue(true);
    }

    public function testValidateTemplateNameAcceptsANonEmptyTitle(): void
    {
        $_POST = ['post_type' => TRUSTED_TEMPLATE_POST_TYPE, 'post_title' => 'My Template'];
        WP_Mock::userFunction('acf_add_validation_error')->never();

        (new TemplateFields())->validateTemplateName();
        self::assertTrue(true);
    }
}
