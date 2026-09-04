<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Input;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Test thesis: semantic key identity in src/Tui must not regress to raw
 * terminal-byte comparisons outside the explicit paste exclusion.
 */
final class RawSemanticKeyComparisonGuardTest extends TestCase
{
    private const array ALLOWED_PATHS = [
        'src/Tui/Listener/ImagePasteInputListener.php',
        // Text-shape prediction, not key routing.
        'src/Tui/Listener/CompletionListener.php',
        // Synthetic editor input helpers, not InputEvent routing.
        'src/Tui/Editor/PromptEditor.php',
    ];

    #[Test]
    public function noRawSemanticKeyComparisonsOutsidePasteExclusion(): void
    {
        $root = \dirname(__DIR__, 3);
        $finder = (new Finder())
            ->files()
            ->in($root.'/src/Tui')
            ->name('*.php');

        $pattern = '/(?:===|!==)\s*(?:"\\\\x[0-9a-fA-F]{2}"|\'\\\\x[0-9a-fA-F]{2}\'|"\\\\t"|\'\\\\t\'|"\\\\n"|\'\\\\n\'|"\\\\r"|\'\\\\r\'|"\\\\x1b"|\'\\\\x1b\')/';
        $violations = [];

        foreach ($finder as $file) {
            $relative = 'src/Tui/'.$file->getRelativePathname();
            if (\in_array($relative, self::ALLOWED_PATHS, true)) {
                continue;
            }

            $contents = $file->getContents();
            if (!preg_match_all($pattern, $contents, $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$match, $offset]) {
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $violations[] = \sprintf('%s:%d %s', $relative, $line, $match);
            }
        }

        $this->assertSame([], $violations, "Raw semantic key comparisons found:\n".implode("\n", $violations));
    }
}
