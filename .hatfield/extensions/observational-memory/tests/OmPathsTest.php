<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use PHPUnit\Framework\TestCase;

final class OmPathsTest extends TestCase
{
    public function testLibrarianProjectFollowsOmStorageInsteadOfGitRoot(): void
    {
        $settings = OmSettings::fromArray(['storage' => ['database' => '/shared/project/.hatfield/extensions-data/observational-memory/om.sqlite']]);
        $this->assertSame('/shared/project', OmPaths::fromSettings($settings, '/checkout/worktree/src')->projectRoot('/checkout/worktree/src'));
        $custom = OmSettings::fromArray(['storage' => ['database' => '/custom/storage/om.sqlite']]);
        $this->assertSame('/checkout/project', OmPaths::fromSettings($custom, '/checkout/project')->projectRoot('/checkout/project'));
        $local = OmSettings::fromArray([]);
        $this->assertSame('/checkout/project', OmPaths::fromSettings($local, '/checkout/project/subdir/..')->projectRoot('/checkout/project/subdir/..'));
    }
}
