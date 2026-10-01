<?php

declare(strict_types=1);

namespace Trusted\Support;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Which rota week it is now.
 */
final class Week
{
    /**
     * The Monday of the current week, Y-m-d.
     *
     * Anchored to the site's configured timezone (Settings → General) rather
     * than PHP's default (UTC under WordPress). On a site running ahead of
     * UTC, `new DateTimeImmutable('today')` can still read as the previous
     * day, landing on the wrong week.
     */
    public static function currentMonday(): string
    {
        $dt  = current_datetime();
        $dow = (int) $dt->format('N');

        return $dt->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
    }
}
