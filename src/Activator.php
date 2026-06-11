<?php

declare(strict_types=1);

namespace Trusted;

use Trusted\Support\Database;
use Trusted\Template\TemplatePostType;

final class Activator
{
    public static function activate(): void
    {
        Database::install();

        // The template CPT is registered on `init`; register it now (it has no
        // Unity dependency) so its admin-only rewrite state is clean on flush.
        (new TemplatePostType())->register();
        flush_rewrite_rules();
    }
}
