<?php

declare(strict_types=1);

namespace HatfieldProject\TaskHooks;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/** Optional parent-project indexing exclusions; never creates an IDEA project. */
final class WorktreeIdeaExclusions
{
    public function update(string $worktree): string
    {
        $base = \dirname($worktree);
        $modules = glob($base.'/.idea/*.iml');
        if (false === $modules || 1 !== \count($modules)) {
            return 'Skipped IDEA exclusions: parent module is missing or ambiguous.';
        }
        $module = $modules[0];
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;
        if (!$document->load($module, \LIBXML_NONET)) {
            throw new \RuntimeException('Failed to parse parent IDEA module.');
        }
        $workspace = realpath($worktree);
        if (false === $workspace) {
            return 'Skipped IDEA exclusions: workspace directory is missing.';
        }
        $matches = [];
        foreach ($document->getElementsByTagName('content') as $content) {
            $url = $content->getAttribute('url');
            if (!str_starts_with($url, 'file://')) {
                continue;
            }
            $path = str_replace(['$MODULE_DIR$', '$PROJECT_DIR$'], [\dirname($module), $base], substr($url, 7));
            $resolved = realpath($path);
            if (false !== $resolved && Path::isBasePath($resolved, $workspace)) {
                $matches[] = [$content, $resolved];
            }
        }
        if (1 !== \count($matches)) {
            return 'Skipped IDEA exclusions: containing content element is missing or ambiguous.';
        }
        /** @var \DOMElement $content */
        [$content, $contentRoot] = $matches[0];
        $existing = [];
        foreach ($content->childNodes as $child) {
            if ($child instanceof \DOMElement && 'excludeFolder' === $child->tagName) {
                $existing[] = $child->getAttribute('url');
            }
        }
        $paths = ['vendor', 'var', '.vera', '.hatfield/cache', '.hatfield/locks', '.hatfield/extensions/vendor', '.hatfield/tmp'];
        $caches = glob($worktree.'/.hatfield/cache-*', \GLOB_ONLYDIR);
        foreach (false === $caches ? [] : $caches as $cache) {
            $paths[] = Path::makeRelative($cache, $worktree);
        }
        $changed = false;
        foreach ($paths as $path) {
            $directory = realpath($worktree.'/'.$path);
            if (false === $directory || !is_dir($directory) || !Path::isBasePath($workspace, $directory)) {
                continue;
            }
            // Preserve the selected content URL's anchor (including IDEA macros),
            // not an assumed relationship between MODULE_DIR and the worktree.
            $url = rtrim($content->getAttribute('url'), '/').'/'.Path::makeRelative($directory, $contentRoot);
            if (\in_array($url, $existing, true)) {
                continue;
            }
            $exclusion = $document->createElement('excludeFolder');
            $exclusion->setAttribute('url', $url);
            $content->appendChild($exclusion);
            $existing[] = $url;
            $changed = true;
        }
        if ($changed) {
            $xml = $document->saveXML();
            if (false === $xml) {
                throw new \RuntimeException('Failed to serialize parent IDEA module.');
            }
            (new Filesystem())->dumpFile($module, $xml);
        }

        return $changed ? 'Updated parent IDEA generated-directory exclusions.' : 'Parent IDEA exclusions already up to date.';
    }
}
