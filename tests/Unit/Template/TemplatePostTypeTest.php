<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use Trusted\Template\TemplatePostType;
use Trusted\Tests\TestCase;

/**
 * @covers \Trusted\Template\TemplatePostType
 */
final class TemplatePostTypeTest extends TestCase
{
    public function testRegisterRegistersTheTemplateCpt(): void
    {
        $GLOBALS['trusted_post_types'] = [];

        (new TemplatePostType())->register();

        self::assertArrayHasKey(TRUSTED_TEMPLATE_POST_TYPE, $GLOBALS['trusted_post_types']);
        $args = $GLOBALS['trusted_post_types'][TRUSTED_TEMPLATE_POST_TYPE];
        self::assertFalse($args['public']);
        self::assertTrue($args['show_ui']);
    }
}
