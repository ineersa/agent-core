<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Ide;

/**
 * Minimal per-worktree .idea metadata derived from the integration primary module.
 */
final class WorktreeIdeaSetup
{
    /**
     * @return array{created: bool, note?: string}
     */
    public static function ensure(string $codeRoot, string $worktree): array
    {
        $worktreeIdea = rtrim($worktree, '/').'/.idea';
        $moduleName = basename($worktree);
        $targetIml = $worktreeIdea.'/'.$moduleName.'.iml';
        $modulesXml = $worktreeIdea.'/modules.xml';

        if (is_file($targetIml) && is_file($modulesXml)) {
            return ['created' => false, 'note' => 'Worktree .idea already present at '.$worktreeIdea.'.'];
        }

        $sourceIml = self::findPrimaryIml($codeRoot);
        if (null === $sourceIml) {
            return ['created' => false, 'note' => 'Integration .idea primary module not found; skipped worktree .idea setup.'];
        }

        $content = file_get_contents($sourceIml);
        if (false === $content) {
            return ['created' => false, 'note' => 'Failed to read integration IDEA module: '.$sourceIml];
        }

        $sanitized = self::sanitizeIml($content);
        $modules = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<project version="4">
  <component name="ProjectModuleManager">
    <modules>
      <module fileurl="file://\$PROJECT_DIR\$/.idea/{$moduleName}.iml" filepath="\$PROJECT_DIR\$/.idea/{$moduleName}.iml" />
    </modules>
  </component>
</project>

XML;
        $gitignore = <<<'GITIGNORE'
# Default ignored files
/shelf/
/workspace.xml
/httpRequests/
/dataSources/
/dataSources.local.xml

GITIGNORE;

        try {
            if (!is_dir($worktreeIdea) && !mkdir($worktreeIdea, 0o755, true) && !is_dir($worktreeIdea)) {
                return ['created' => false, 'note' => 'Failed to create worktree .idea directory: '.$worktreeIdea];
            }
            if (false === file_put_contents($targetIml, $sanitized)) {
                return ['created' => false, 'note' => 'Failed to write worktree module: '.$targetIml];
            }
            if (false === file_put_contents($modulesXml, $modules)) {
                return ['created' => false, 'note' => 'Failed to write modules.xml: '.$modulesXml];
            }
            file_put_contents($worktreeIdea.'/.gitignore', $gitignore);

            return ['created' => true, 'note' => 'Created worktree .idea module at '.$worktreeIdea.'.'];
        } catch (\Throwable $e) {
            return ['created' => false, 'note' => 'Failed to write worktree .idea: '.$e->getMessage()];
        }
    }

    private static function findPrimaryIml(string $codeRoot): ?string
    {
        $ideaDir = rtrim($codeRoot, '/').'/.idea';
        if (!is_dir($ideaDir)) {
            return null;
        }
        $primary = $ideaDir.'/'.basename($codeRoot).'.iml';
        if (is_file($primary)) {
            return $primary;
        }
        $entries = scandir($ideaDir);
        if (false === $entries) {
            return null;
        }
        $imlFiles = array_values(array_filter($entries, static fn (string $e): bool => str_ends_with($e, '.iml')));
        if (1 === \count($imlFiles)) {
            return $ideaDir.'/'.$imlFiles[0];
        }

        return null;
    }

    private static function sanitizeIml(string $content): string
    {
        $out = preg_replace('/\n?\s*<orderEntry\s+type="module"[^\/]*\/>/', '', $content) ?? $content;
        $out = preg_replace(
            '/\n?\s*<(?:sourceFolder|excludeFolder)\s+[^>]*url="file:\/\/(?!\$MODULE_DIR\$)[^"]+"[^\/]*\/>/',
            '',
            $out
        ) ?? $out;

        return $out;
    }
}
