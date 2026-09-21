<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Template;

use Trusted\Template\TemplatePostType;

covers(TemplatePostType::class);

it('registers the template post type as private with an admin UI', function () {
    $GLOBALS['trusted_post_types'] = [];

    (new TemplatePostType())->register();

    expect($GLOBALS['trusted_post_types'])->toHaveKey(TRUSTED_TEMPLATE_POST_TYPE)
        ->and($GLOBALS['trusted_post_types'][TRUSTED_TEMPLATE_POST_TYPE])
        ->public->toBeFalse()
        ->show_ui->toBeTrue();
});
