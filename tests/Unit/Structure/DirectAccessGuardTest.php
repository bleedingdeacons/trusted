<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Structure;

use PHPUnit\Framework\TestCase;

/**
 * Every source file must refuse to run outside WordPress.
 *
 * Trusted's src/ had one guarded file out of thirty-three, where the rest of
 * the suite guards all of them. As things stand these are class-definition
 * files with no include-time side effects, so a direct request produces a
 * blank page rather than an error trace — it is the safety net that was
 * missing, not a live hole. The guard is what stops a future file with side
 * effects, or a fatal that discloses paths, from being reachable.
 *
 * Which is exactly why this is a test rather than a one-off sweep. Adding
 * thirty-two guards is mechanical and would rot the moment somebody adds a
 * thirty-fourth file; asserting the invariant is what actually holds.
 *
 * A pure-PHP test: it reads files, and needs no WordPress at all.
 */
final class DirectAccessGuardTest extends TestCase
{
    /**
     * @test
     * @dataProvider sourceFiles
     */
    public function every_source_file_refuses_direct_access(string $relative, string $absolute): void
    {
        $source = (string) file_get_contents($absolute);

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*defined\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)\s*\{\s*exit;/',
            $source,
            $relative . ' is missing the ABSPATH guard. Add it directly below the namespace declaration:'
                . PHP_EOL . PHP_EOL
                . "// Prevent direct access" . PHP_EOL
                . "if (! defined('ABSPATH')) {" . PHP_EOL
                . '    exit;' . PHP_EOL
                . '}'
        );
    }

    /**
     * @test
     */
    public function the_sweep_actually_found_files(): void
    {
        // Guards the guard: a provider that silently returned nothing would
        // make every assertion above vacuous.
        $this->assertGreaterThan(25, count(self::sourceFiles()));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/src';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $cases = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $absolute = (string) $file->getRealPath();
            $relative = 'src/' . str_replace('\\', '/', substr($absolute, strlen($root) + 1));

            $cases[$relative] = [$relative, $absolute];
        }

        ksort($cases);

        return $cases;
    }
}
