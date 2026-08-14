<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Docs;

use Ineersa\CodingAgent\Docs\BuiltinDocsValidator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: BuiltinDocsValidator (production docs:validate path) rejects basename-only
 * broken links, bad anchors, unselected API-doc targets, accepts clean siblings and
 * CommonMark link shapes, and size-gates only maintained README/docs targets.
 */
final class DocsValidateContractTest extends TestCase
{
    public function testBrokenSameBasenameLinkIsRejected(): void
    {
        $root = $this->scaffoldCleanTree();
        try {
            file_put_contents(
                $root.'/docs/alpha.md',
                "---\nbuiltin: true\ndescription: Alpha\n---\n\n# Alpha\n\nSee [missing](missing/settings.md).\n",
            );
            file_put_contents(
                $root.'/docs/settings.md',
                "---\nbuiltin: true\ndescription: Settings\n---\n\n# Settings\n\nbody\n",
            );

            $errors = (new BuiltinDocsValidator())->validate($root);
            $this->assertNotSame([], $errors);
            $this->assertTrue(
                (bool) array_filter($errors, static fn (string $e): bool => str_contains($e, 'missing/settings.md')),
                implode("\n", $errors),
            );
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }

    public function testBadAnchorIsRejected(): void
    {
        $root = $this->scaffoldCleanTree();
        try {
            file_put_contents(
                $root.'/docs/alpha.md',
                "---\nbuiltin: true\ndescription: Alpha\n---\n\n# Alpha\n\nSee [settings](settings.md#no-such-heading).\n",
            );
            file_put_contents(
                $root.'/docs/settings.md',
                "---\nbuiltin: true\ndescription: Settings\n---\n\n# Settings\n\n## Real Section\n\nbody\n",
            );

            $errors = (new BuiltinDocsValidator())->validate($root);
            $this->assertTrue(
                (bool) array_filter($errors, static fn (string $e): bool => str_contains($e, 'no-such-heading')),
                implode("\n", $errors),
            );
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }

    public function testCanonicalSiblingLinkWithValidAnchorPasses(): void
    {
        $root = $this->scaffoldCleanTree();
        try {
            file_put_contents(
                $root.'/docs/alpha.md',
                "---\nbuiltin: true\ndescription: Alpha\n---\n\n# Alpha\n\nSee [settings](settings.md#real-section).\n",
            );
            file_put_contents(
                $root.'/docs/settings.md',
                "---\nbuiltin: true\ndescription: Settings\n---\n\n# Settings\n\n## Real Section\n\nbody\n",
            );

            $errors = (new BuiltinDocsValidator())->validate($root);
            $this->assertSame([], $errors, implode("\n", $errors));
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }

    public function testUnselectedApiDocTargetIsRejected(): void
    {
        $root = $this->scaffoldCleanTree();
        try {
            file_put_contents(
                $root.'/.hatfield/extensions/extension-api/docs/extension-api.md',
                "---\nbuiltin: true\ndescription: API\n---\n\n# Extension API\n\nSee [private](private-unmarked.md).\n",
            );
            file_put_contents(
                $root.'/.hatfield/extensions/extension-api/docs/private-unmarked.md',
                "# Private\n\nnot selected\n",
            );

            $errors = (new BuiltinDocsValidator())->validate($root);
            $this->assertTrue(
                (bool) array_filter($errors, static fn (string $e): bool => str_contains($e, 'private-unmarked.md')),
                implode("\n", $errors),
            );
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }

    /**
     * CommonMark link contract table: percent-encoded fragment, reference link,
     * angle destination, and fenced-code decoy must all be handled correctly.
     */
    public function testCommonMarkLinkContractTable(): void
    {
        $root = $this->scaffoldCleanTree();
        try {
            file_put_contents(
                $root.'/docs/settings.md',
                "---\nbuiltin: true\ndescription: Settings\n---\n\n# Settings\n\n## Real Section\n\nbody\n",
            );
            // Contract shapes: percent-encoded path segment, reference link, angle
            // destination, and a fenced-code decoy that must not be treated as a link.
            file_put_contents(
                $root.'/docs/alpha.md',
                <<<'MD'
---
builtin: true
description: Alpha
---

# Alpha

Percent fragment: [settings](settings.md#real%2Dsection).
Reference link: [settings ref][settings-ref].
Angle destination: [settings angle](<settings.md#real-section>).

```md
[decoy](missing-not-real.md)
```

[settings-ref]: settings.md#real-section
MD
            );

            $errors = (new BuiltinDocsValidator())->validate($root);
            $this->assertSame([], $errors, implode("\n", $errors));

            // Fenced decoy ignored; live broken link still fails.
            file_put_contents(
                $root.'/docs/alpha.md',
                <<<'MD'
---
builtin: true
description: Alpha
---

# Alpha

```md
[decoy](missing-not-real.md)
```

Live broken: [missing](missing-not-real.md).
MD
            );
            $errorsBroken = (new BuiltinDocsValidator())->validate($root);
            $this->assertTrue(
                (bool) array_filter($errorsBroken, static fn (string $e): bool => str_contains($e, 'missing-not-real.md')),
                implode("\n", $errorsBroken),
            );
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }

    public function testSizeTargetPathsIgnoresUntrackedGeneratedReadmeOutsideGit(): void
    {
        $root = $this->scaffoldCleanTree();
        try {
            TestDirectoryIsolation::ensureDirectory($root.'/vendor/pkg');
            file_put_contents($root.'/vendor/pkg/README.md', str_repeat('x', 30000));
            file_put_contents($root.'/README.md', "# Temp\n");
            file_put_contents(
                $root.'/docs/alpha.md',
                "---\nbuiltin: true\ndescription: Alpha\n---\n\n# Alpha\n\nbody\n",
            );

            $paths = (new BuiltinDocsValidator())->sizeTargetPaths($root);
            $basenames = array_map(static fn (string $p): string => str_replace($root.'/', '', $p), $paths);
            $this->assertContains('README.md', $basenames);
            $this->assertContains('docs/alpha.md', $basenames);
            $this->assertNotContains('vendor/pkg/README.md', $basenames);

            $errors = (new BuiltinDocsValidator())->validate($root);
            $this->assertSame([], $errors, implode("\n", $errors));
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }

    private function scaffoldCleanTree(): string
    {
        $root = TestDirectoryIsolation::createProjectTempDir('docs-validate-contract');
        TestDirectoryIsolation::ensureDirectory($root.'/docs');
        TestDirectoryIsolation::ensureDirectory($root.'/.hatfield/extensions/extension-api/docs');
        file_put_contents($root.'/README.md', "# Temp\n");

        return $root;
    }
}
