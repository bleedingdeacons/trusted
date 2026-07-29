<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use Trusted\Template\TemplatePostType;
use Trusted\Tests\TestCase;
use WP_Mock;

/**
 * @covers \Trusted\Template\TemplatePostType
 */
final class TemplatePostTypeTest extends TestCase
{
    public function testRegisterRegistersTheTemplateCpt(): void
    {
        WP_Mock::userFunction('__')->andReturnUsing(static fn (string $t): string => $t);
        $GLOBALS['trusted_post_types'] = [];

        (new TemplatePostType())->register();

        self::assertArrayHasKey(TRUSTED_TEMPLATE_POST_TYPE, $GLOBALS['trusted_post_types']);
        $args = $GLOBALS['trusted_post_types'][TRUSTED_TEMPLATE_POST_TYPE];
        self::assertFalse($args['public']);
        self::assertTrue($args['show_ui']);
    }
}
