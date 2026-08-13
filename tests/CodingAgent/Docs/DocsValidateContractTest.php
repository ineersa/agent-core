<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Docs;

use Ineersa\CodingAgent\Docs\BuiltinDocsValidator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: BuiltinDocsValidator (production docs:validate path) rejects basename-only
 * broken links, bad anchors, unselected API-doc targets, and accepts clean siblings.
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

    private function scaffoldCleanTree(): string
    {
        $root = TestDirectoryIsolation::createProjectTempDir('docs-validate-contract');
        TestDirectoryIsolation::ensureDirectory($root.'/docs');
        TestDirectoryIsolation::ensureDirectory($root.'/.hatfield/extensions/extension-api/docs');
        file_put_contents($root.'/README.md', "# Temp\n");

        return $root;
    }
}
