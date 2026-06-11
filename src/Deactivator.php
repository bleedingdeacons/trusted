<?php

declare(strict_types=1);

namespace Trusted;

final class Deactivator
{
    public static function deactivate(): void
    {
        // Data is preserved on deactivation. Tables are only dropped on
        // uninstall (see uninstall.php).
        flush_rewrite_rules();
    }
}
