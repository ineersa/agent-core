<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\WorkspaceFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\HookRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectTaskHooksTest extends TestCase
{
    public function testRepeatedSetupUpdatesOnlyParentExclusionsAndCleanupIsNoOp(): void
    {
        $fixture = new WorkspaceFixture();
        try {
            $hooks = $fixture->root.'/.hatfield/task-hooks';
            (new Filesystem())->mirror(\dirname(__DIR__, 3).'/task-hooks', $hooks);
            mkdir($fixture->root.'/vendor');
            file_put_contents($fixture->root.'/vendor/autoload.php', '<?php require '.var_export(\dirname(__DIR__, 4).'/vendor/autoload.php', true).';');
            mkdir($fixture->root.'/.vera');
            file_put_contents($fixture->root.'/.vera/index', 'index');
            $worktree = $fixture->directory.'/task';
            mkdir($worktree);
            foreach (['var', '.hatfield/cache', '.hatfield/cache-test', '.hatfield/locks', '.hatfield/extensions/vendor', '.hatfield/tmp', '.hatfield/skills'] as $directory) {
                mkdir($worktree.'/'.$directory, 0o755, true);
            }
            mkdir($fixture->directory.'/.idea');
            $parentIml = $fixture->directory.'/.idea/parent.iml';
            file_put_contents($parentIml, <<<'XML'
<?xml version="1.0"?>
<module>
  <!-- Keep the parent project's own configuration. -->
  <component name="NewModuleRootManager">
    <content url="file://$PROJECT_DIR$">
      <sourceFolder url="file://$PROJECT_DIR$/src" isTestSource="false" />
      <excludeFolder url="file://$PROJECT_DIR$/unrelated" />
    </content>
  </component>
</module>
XML);
            $context = ['HATFIELD_TASK_REPOSITORY' => 'owner/repo', 'HATFIELD_TASK_NUMBER' => '1', 'HATFIELD_TASK_URL' => 'url', 'HATFIELD_TASK_BRANCH' => 'task/1', 'HATFIELD_TASK_BASE_BRANCH' => 'main', 'HATFIELD_TASK_WORKTREE' => $worktree, 'HATFIELD_TASK_SETUP_ATTEMPTED' => '1', 'HATFIELD_TASK_REASON' => 'start', 'HATFIELD_TASK_RESOURCE_ID' => 'stable', 'HATFIELD_TASK_DATA_DIR' => $fixture->directory.'/data'];
            $runner = new HookRunner($fixture);
            $result = $runner->run($fixture->root, '.hatfield/task-hooks/setup.sh', 3, $context);
            $this->assertSame(0, $result->exitCode, $result->stderr);
            $updated = file_get_contents($parentIml);
            $result = $runner->run($fixture->root, '.hatfield/task-hooks/setup.sh', 3, $context);
            $this->assertSame(0, $result->exitCode, $result->stderr);
            $this->assertSame($updated, file_get_contents($parentIml));
            $this->assertFileEquals($fixture->root.'/vendor/autoload.php', $worktree.'/vendor/autoload.php');
            $this->assertFileEquals($fixture->root.'/.vera/index', $worktree.'/.vera/index');
            $this->assertDirectoryDoesNotExist($worktree.'/.idea');

            $document = new \DOMDocument();
            $document->load($parentIml);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query('//comment()')->length);
            $this->assertSame(1, $xpath->query('//sourceFolder')->length);
            $urls = [];
            foreach ($document->getElementsByTagName('excludeFolder') as $exclusion) {
                $urls[] = $exclusion->getAttribute('url');
            }
            $expected = ['file://$PROJECT_DIR$/unrelated'];
            foreach (['vendor', 'var', '.vera', '.hatfield/cache', '.hatfield/cache-test', '.hatfield/locks', '.hatfield/extensions/vendor', '.hatfield/tmp'] as $directory) {
                $expected[] = 'file://$PROJECT_DIR$/task/'.$directory;
            }
            $this->assertEqualsCanonicalizing($expected, $urls);

            $context['HATFIELD_TASK_REASON'] = 'cancel';
            $this->assertSame(0, $runner->run($fixture->root, '.hatfield/task-hooks/cleanup.sh', 3, $context)->exitCode);
            $this->assertDirectoryExists($worktree);
            $this->assertSame($updated, file_get_contents($parentIml));
        } finally {
            $fixture->close();
        }
    }
}
