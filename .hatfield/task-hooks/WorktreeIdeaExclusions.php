<?php

declare(strict_types=1);

namespace HatfieldProject\TaskHooks;

final class WorktreeIdeaExclusions
{
    /** @var list<string> */
    private const WORKTREE_EXCLUDE_PATHS = [
        '.hatfield',
        '.vera',
        'var',
        'vendor',
        'apps/coding-agent/var',
        'apps/coding-agent/vendor',
        'packages/agent-core/var',
        'packages/agent-core/vendor',
        'packages/ai-index/vendor',
    ];

    /** @return array{updated: bool, note?: string} */
    public function addWorktreeExclusions(string $slug, string $worktreeBase): array
    {
        $imlPath = $this->findParentIdeaModule($worktreeBase);
        if (null === $imlPath) {
            return ['updated' => false, 'note' => 'Parent IDEA module not found or ambiguous; skipping exclusion update.'];
        }

        $content = file_get_contents($imlPath);
        if (false === $content) {
            return ['updated' => false, 'note' => 'Failed to read parent IDEA module: '.$imlPath];
        }

        $startTag = self::startMarker($slug);
        $endTag = self::endMarker($slug);
        $startIdx = strpos($content, $startTag);
        $endIdx = strpos($content, $endTag);
        $hasStart = false !== $startIdx;
        $hasEnd = false !== $endIdx;
        if ($hasStart !== $hasEnd) {
            return ['updated' => false, 'note' => 'Parent IDEA module has mismatched exclusion markers for '.$slug.' ('.($hasStart ? 'start-only' : 'end-only').'); skipping update to avoid corruption.'];
        }
        if ($hasStart && false !== $endIdx && false !== $startIdx && $endIdx < $startIdx) {
            return ['updated' => false, 'note' => 'Parent IDEA module has reversed exclusion markers for '.$slug.' (end before start); skipping update to avoid corruption.'];
        }

        $newBlock = $this->buildExclusionBlock($slug);
        if ($hasStart && false !== $startIdx && false !== $endIdx) {
            $content = substr($content, 0, $startIdx).$newBlock.substr($content, $endIdx + \strlen($endTag));
        } else {
            $contentCloseIdx = strpos($content, '</content>');
            if (false === $contentCloseIdx) {
                return ['updated' => false, 'note' => 'Parent IDEA module has no <content> element; cannot insert exclusions.'];
            }
            $content = substr($content, 0, $contentCloseIdx).$newBlock."\n".substr($content, $contentCloseIdx);
        }

        if (false === file_put_contents($imlPath, $content)) {
            return ['updated' => false, 'note' => 'Failed to write parent IDEA module: '.$imlPath];
        }

        return ['updated' => true];
    }

    /** @return array{updated: bool, note?: string} */
    public function removeWorktreeExclusions(string $slug, string $worktreeBase): array
    {
        $imlPath = $this->findParentIdeaModule($worktreeBase);
        if (null === $imlPath) {
            return ['updated' => false, 'note' => 'Parent IDEA module not found; skipping exclusion cleanup.'];
        }

        $content = file_get_contents($imlPath);
        if (false === $content) {
            return ['updated' => false, 'note' => 'Failed to read parent IDEA module for cleanup: '.$imlPath];
        }

        $startTag = self::startMarker($slug);
        $endTag = self::endMarker($slug);
        $startIdx = strpos($content, $startTag);
        $endIdx = strpos($content, $endTag);
        $hasStart = false !== $startIdx;
        $hasEnd = false !== $endIdx;
        if ($hasStart !== $hasEnd) {
            return ['updated' => false, 'note' => 'Parent IDEA module has mismatched exclusion markers for '.$slug.' ('.($hasStart ? 'start-only' : 'end-only').'); skipping cleanup to avoid corruption.'];
        }
        if ($hasStart && false !== $endIdx && false !== $startIdx && $endIdx < $startIdx) {
            return ['updated' => false, 'note' => 'Parent IDEA module has reversed exclusion markers for '.$slug.' (end before start); skipping cleanup to avoid corruption.'];
        }
        if (!$hasStart || false === $startIdx || false === $endIdx) {
            return ['updated' => false];
        }

        $beforeStart = strrpos(substr($content, 0, $startIdx), "\n");
        $removeStart = false !== $beforeStart && $beforeStart > 0 ? $beforeStart : $startIdx;
        $content = substr($content, 0, $removeStart).substr($content, $endIdx + \strlen($endTag));

        if (false === file_put_contents($imlPath, $content)) {
            return ['updated' => false, 'note' => 'Failed to write parent IDEA module for cleanup: '.$imlPath];
        }

        return ['updated' => true];
    }

    private function findParentIdeaModule(string $worktreeBase): ?string
    {
        $ideaDir = $worktreeBase.'/.idea';
        if (!is_dir($ideaDir)) {
            return null;
        }
        $primary = $ideaDir.'/'.basename($worktreeBase).'.iml';
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

    private function buildExclusionBlock(string $slug): string
    {
        $lines = ['', self::startMarker($slug)];
        foreach (self::WORKTREE_EXCLUDE_PATHS as $relPath) {
            $lines[] = '    <excludeFolder url="file://$MODULE_DIR$/'.$slug.'/'.$relPath.'" />';
        }
        $lines[] = self::endMarker($slug);

        return implode("\n", $lines);
    }

    private static function startMarker(string $slug): string
    {
        return '<!-- pi-task-workflow:start '.$slug.' -->';
    }

    private static function endMarker(string $slug): string
    {
        return '<!-- pi-task-workflow:end '.$slug.' -->';
    }
}
