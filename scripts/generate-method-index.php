#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate per-file method indexes from PHP docblock summaries (no LLM required).
 *
 * Reads the first sentence of class and method docblocks as summaries,
 * and produces docs/<File>.toon per PHP class plus namespace-level ai-index.toon files.
 *
 * Usage:
 *   php scripts/generate-method-index.php [options] [file_or_dir ...]
 *
 * Options:
 *   --all             Process all PHP files in src/
 *   --changed         Process only git-changed PHP files (default if no targets given)
 *   --dry-run         Show what would be done without writing
 *   --force           Regenerate even if index is newer than source file
 *   --strict          Error if any class is missing a docblock summary
 *   --migrate         Write existing .toon summaries back into source file docblocks
 *   --skip-namespace  Skip namespace index regeneration
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use HelgeSverre\Toon\Toon;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

// ── Config ────────────────────────────────────────────────────────────────

$args = array_slice($argv, 1);
$all = false;
$changed = false;
$dryRun = false;
$force = false;
$strict = false;
$migrate = false;
$skipNamespace = false;
$targets = [];

foreach ($args as $arg) {
    if ($arg === '--all') { $all = true; continue; }
    if ($arg === '--changed') { $changed = true; continue; }
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if ($arg === '--force') { $force = true; continue; }
    if ($arg === '--strict') { $strict = true; continue; }
    if ($arg === '--migrate') { $migrate = true; continue; }
    if ($arg === '--skip-namespace') { $skipNamespace = true; continue; }
    $targets[] = $arg;
}

if (!$all && !$changed && empty($targets)) {
    $changed = true;
}

$projectRoot = dirname(__DIR__);

// ── Collect files ─────────────────────────────────────────────────────────

$phpFiles = [];

if ($all) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot . '/src', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iter as $f) {
        if ($f->getExtension() === 'php') {
            $phpFiles[] = $f->getPathname();
        }
    }
} elseif ($changed) {
    $changedFiles = [];
    foreach ([
        'git -C %s diff --name-only --diff-filter=ACMR HEAD -- src/',
        'git -C %s diff --name-only --diff-filter=ACMR --cached -- src/',
        'git -C %s ls-files --others --exclude-standard -- src/',
    ] as $gitCmd) {
        $output = shell_exec(sprintf($gitCmd, escapeshellarg($projectRoot)));
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                if ($line !== '') $changedFiles[$line] = true;
            }
        }
    }
    foreach (array_keys($changedFiles) as $line) {
        $full = $projectRoot . '/' . $line;
        if (str_ends_with($line, '.php') && file_exists($full)) {
            $phpFiles[] = $full;
        }
    }
} else {
    foreach ($targets as $target) {
        $full = realpath($target);
        if (!$full) { echo "Not found: $target\n"; continue; }
        if (is_dir($full)) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $f) {
                if ($f->getExtension() === 'php') $phpFiles[] = $f->getPathname();
            }
        } else {
            $phpFiles[] = $full;
        }
    }
}

if (empty($phpFiles)) {
    echo "No PHP files to process.\n";
    exit(0);
}

sort($phpFiles);

if ($migrate) {
    echo "Migrating .toon summaries into docblocks for " . count($phpFiles) . " file(s)...\n\n";
} else {
    echo "Processing " . count($phpFiles) . " file(s)...\n\n";
}

// ── AST helpers ───────────────────────────────────────────────────────────

$parser = new ParserFactory()->createForHostVersion();
$printer = new PrettyPrinter();

function getMethodVisibility(Node\Stmt\ClassMethod $method): string
{
    if ($method->isPublic()) return 'public';
    if ($method->isProtected()) return 'protected';
    return 'private';
}

function methodModifiers(Node\Stmt\ClassMethod $method): string
{
    $mods = [];
    if ($method->isFinal()) $mods[] = 'final';
    if ($method->isAbstract()) $mods[] = 'abstract';
    if ($method->isStatic()) $mods[] = 'static';
    $mods[] = getMethodVisibility($method);
    return implode(' ', $mods);
}

function typeToString(Node\ComplexType|Node\Identifier|Node\Name|null $type): string
{
    if (null === $type) return '';
    if ($type instanceof Node\NullableType) return '?' . typeToString($type->type);
    if ($type instanceof Node\UnionType) return implode('|', array_map(typeToString(...), $type->types));
    if ($type instanceof Node\IntersectionType) return implode('&', array_map(typeToString(...), $type->types));
    return $type->toString();
}

function offsetToColumn(string $code, int $offset): int
{
    if ($offset < 0) return 1;
    $before = substr($code, 0, $offset);
    $lineStartOffset = strrpos($before, "\n");
    if (false === $lineStartOffset) return $offset + 1;
    return $offset - $lineStartOffset;
}

function extractThrowsFromDoc(?Doc $doc): array
{
    if (null === $doc) return [];
    $throws = [];
    preg_match_all('/@throws\s+([^\s*]+)/', $doc->getText(), $matches);
    foreach ($matches[1] ?? [] as $throwType) {
        $throws[] = trim($throwType);
    }
    return array_values(array_unique($throws));
}

function extractThrowsFromBody(Node\Stmt\ClassMethod $method): array
{
    if (null === $method->stmts) return [];
    $finder = new NodeFinder();
    $throws = [];
    /** @var list<Node\Expr\Throw_> $throwNodes */
    $throwNodes = $finder->findInstanceOf($method->stmts, Node\Expr\Throw_::class);
    foreach ($throwNodes as $throwNode) {
        if ($throwNode->expr instanceof Node\Expr\Throw_) continue;
        if ($throwNode->expr instanceof Node\Expr\New_ && $throwNode->expr->class instanceof Node\Name) {
            $throws[] = $throwNode->expr->class->toString();
            continue;
        }
        $throws[] = 'Throwable';
    }
    return array_values(array_unique($throws));
}

function buildMethodParams(Node\Stmt\ClassMethod $method, PrettyPrinter $printer): string
{
    $parts = [];
    foreach ($method->getParams() as $param) {
        $chunks = [];
        $paramType = typeToString($param->type);
        if ('' !== $paramType) $chunks[] = $paramType;
        if ($param->byRef) $chunks[] = '&';
        if ($param->variadic) $chunks[] = '...';
        $chunks[] = '$' . $param->var->name;
        if (null !== $param->default) {
            $chunks[] = '= ' . $printer->prettyPrintExpr($param->default);
        }
        $parts[] = implode(' ', $chunks);
    }
    return implode(', ', $parts);
}

/**
 * Extract the summary from a docblock: description text before any blank line or @tag.
 */
function extractDocblockSummary(?Doc $doc): string
{
    if (null === $doc) return '';
    $text = $doc->getText();

    // Strip /* and */ wrappers
    $text = preg_replace('#^\s*/\*+|\*+/\s*$#', '', $text);

    // Remove leading * from each line
    $text = preg_replace('#^\s*\* ?#m', '', $text);

    // Extract lines until a blank line or @tag
    $lines = explode("\n", $text);
    $descriptionLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '@')) {
            break;
        }
        $descriptionLines[] = $trimmed;
    }

    $description = implode(' ', $descriptionLines);
    $description = preg_replace('/\s+/', ' ', trim($description));

    return $description;
}

/**
 * @return list<Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Interface_>
 */
function findClassLikeNodes(array $ast): array
{
    $finder = new NodeFinder();
    $types = [
        Node\Stmt\Class_::class,
        Node\Stmt\Trait_::class,
        Node\Stmt\Enum_::class,
        Node\Stmt\Interface_::class,
    ];
    $result = [];
    foreach ($types as $type) {
        $result = [...$result, ...$finder->findInstanceOf($ast, $type)];
    }
    return $result;
}

/**
 * Build structured info from a class-like AST node.
 */
function buildClassEntry(
    Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Interface_ $class,
    string $namespace,
    string $code,
    PrettyPrinter $printer,
): array {
    $classType = match (true) {
        $class instanceof Node\Stmt\Trait_ => 'trait',
        $class instanceof Node\Stmt\Enum_ => 'enum',
        $class instanceof Node\Stmt\Interface_ => 'interface',
        default => 'class',
    };
    $classModifiers = [];
    if ($class instanceof Node\Stmt\Class_) {
        if ($class->isReadonly()) $classModifiers[] = 'readonly';
        if ($class->isFinal()) $classModifiers[] = 'final';
        if ($class->isAbstract()) $classModifiers[] = 'abstract';
    }

    $classSummary = extractDocblockSummary($class->getDocComment());

    $methods = [];
    foreach ($class->stmts as $stmt) {
        if (!$stmt instanceof Node\Stmt\ClassMethod) continue;

        $doc = $stmt->getDocComment();
        $docStart = $doc?->getStartLine();
        $signatureLine = $stmt->getStartLine();
        $symbolLine = $stmt->name->getStartLine();
        $symbolColumn = offsetToColumn($code, $stmt->name->getStartFilePos());
        $throws = array_values(array_unique([...extractThrowsFromDoc($doc), ...extractThrowsFromBody($stmt)]));

        $methods[] = [
            'node' => $stmt,
            'name' => $stmt->name->toString(),
            'modifiers' => methodModifiers($stmt),
            'visibility' => getMethodVisibility($stmt),
            'static' => $stmt->isStatic(),
            'final' => $stmt->isFinal(),
            'abstract' => $stmt->isAbstract(),
            'docStartLine' => $docStart ?? $signatureLine,
            'signatureLine' => $signatureLine,
            'symbolLine' => $symbolLine,
            'symbolColumn' => $symbolColumn,
            'endLine' => $stmt->getEndLine(),
            'params' => buildMethodParams($stmt, $printer),
            'returnType' => typeToString($stmt->returnType),
            'throws' => implode('|', $throws),
            'summary' => extractDocblockSummary($doc),
        ];
    }

    return [
        'node' => $class,
        'namespace' => $namespace,
        'className' => $class->name->name,
        'classType' => $classType,
        'classModifiers' => implode(' ', $classModifiers),
        'classSummary' => $classSummary,
        'methods' => $methods,
    ];
}

function extractNamespace(array $ast): string
{
    foreach ($ast as $node) {
        if ($node instanceof Node\Stmt\Namespace_) {
            return $node->name->toString();
        }
    }
    return '';
}

// ── Docblock migration helpers ────────────────────────────────────────────

/**
 * Extract existing @tag lines from a docblock.
 */
/**
 * Extract all @tag lines AND their continuation lines from a docblock.
 * Handles multi-line type annotations like @return array{ ... }.
 *
 * @return list<string>
 */
function extractTagBlock(?Doc $doc): array
{
    if (null === $doc) return [];
    $text = $doc->getText();
    $inner = preg_replace('#^\s*/\*+|\*+/\s*$#', '', $text);
    $inner = preg_replace('#^\s*\* ?#m', '', $inner);
    $lines = explode("\n", $inner);

    $tagLines = [];
    $inTag = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '@')) {
            $inTag = true;
            $tagLines[] = $line;
        } elseif ($inTag && '' !== $trimmed) {
            // Continuation line of a multi-line tag
            $tagLines[] = $line;
        } elseif ('' === $trimmed) {
            // Blank line ends the current tag block
            $inTag = false;
        }
    }
    return $tagLines;
}

/**
 * Build a docblock for line-level insertion (includes indent on every line).
 */
function buildDocblockForInsertion(string $summary, array $tagLines, string $indent): string
{
    $doc = "{$indent}/**\n{$indent} * {$summary}";
    if ([] !== $tagLines) {
        $doc .= "\n{$indent} *";
        foreach ($tagLines as $tagLine) {
            $doc .= "\n{$indent} * " . trim($tagLine);
        }
    }
    $doc .= "\n{$indent} */";
    return $doc;
}

/**
 * Build a docblock for byte-offset replacement (no indent on first line, indent on continuation).
 * The code before the start position already contains the leading whitespace.
 */
function buildDocblockForReplacement(string $summary, array $tagLines, string $indent): string
{
    $doc = "/**\n{$indent} * {$summary}";
    if ([] !== $tagLines) {
        $doc .= "\n{$indent} *";
        foreach ($tagLines as $tagLine) {
            $doc .= "\n{$indent} * " . trim($tagLine);
        }
    }
    $doc .= "\n{$indent} */";
    return $doc;
}

/**
 * Replace a docblock in source code by byte offset, or insert a new one before a line.
 */
function replaceDocblockInCode(string $code, ?Doc $existingDoc, string $newDocblock, int $fallbackLine): string
{
    if (null !== $existingDoc) {
        // Replace existing docblock by byte offset
        $startPos = $existingDoc->getStartFilePos();
        $endPos = $startPos + strlen($existingDoc->getText());
        return substr($code, 0, $startPos) . $newDocblock . substr($code, $endPos);
    }

    // Insert new docblock before the given line (1-based)
    $lines = explode("\n", $code);
    array_splice($lines, $fallbackLine - 1, 0, $newDocblock);
    return implode("\n", $lines);
}

// ── Build work items ──────────────────────────────────────────────────────

$stats = ['generated' => 0, 'migrated' => 0, 'skipped' => 0, 'failed' => 0, 'missing_summaries' => 0];
$strictErrors = [];

foreach ($phpFiles as $phpFile) {
    $relPath = substr($phpFile, strlen($projectRoot) + 1);
    $code = file_get_contents($phpFile);
    $ast = $parser->parse($code);
    if (!$ast) {
        echo "  skip: {$relPath} (parse error)\n";
        $stats['skipped']++;
        continue;
    }

    $namespace = extractNamespace($ast);
    $classNodes = findClassLikeNodes($ast);

    if (empty($classNodes)) {
        echo "  skip: {$relPath} (no classes)\n";
        $stats['skipped']++;
        continue;
    }

    $dir = dirname($phpFile);
    $filename = basename($phpFile, '.php');
    $outputPath = $dir . '/docs/' . $filename . '.toon';

    // ── Migration mode ────────────────────────────────────────────────
    if ($migrate) {
        $toonData = loadToonSummary($outputPath);
        if (null === $toonData) {
            echo "  skip: {$relPath} (no existing .toon)\n";
            $stats['skipped']++;
            continue;
        }

        $modified = false;
        foreach ($classNodes as $classNode) {
            $className = $classNode->name->name;
            $fqcn = ($namespace ? $namespace . '\\' : '') . $className;
            $classSummary = $toonData['summary'] ?? '';

            // Inject class summary into class docblock
            if ('' !== $classSummary) {
                $existingSummary = extractDocblockSummary($classNode->getDocComment());
                if ('' === $existingSummary) {
                    $tagLines = extractTagBlock($classNode->getDocComment());
                    $sourceLines = explode("\n", $code);
                    $classLine = $classNode->getStartLine();
                    $indent = preg_match('/^(\s*)/', $sourceLines[$classLine - 1] ?? '', $m) ? $m[1] : '    ';
                    $newDoc = null !== $classNode->getDocComment()
                        ? buildDocblockForReplacement($classSummary, $tagLines, $indent)
                        : buildDocblockForInsertion($classSummary, $tagLines, $indent);
                    $code = replaceDocblockInCode($code, $classNode->getDocComment(), $newDoc, $classLine);
                    $modified = true;
                }
            }

            // Inject method summaries
            $methodDocs = $toonData['methods'] ?? [];
            $methodMap = [];
            foreach ($methodDocs as $me) {
                $methodMap[$me['method'] ?? ''] = $me['summary'] ?? '';
            }

            foreach ($classNode->stmts as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassMethod) continue;
                $methodName = $stmt->name->toString();
                if (!isset($methodMap[$methodName])) continue;
                $methodSummary = $methodMap[$methodName];
                if ('' === $methodSummary) continue;

                $existingMethodSummary = extractDocblockSummary($stmt->getDocComment());
                if ('' === $existingMethodSummary) {
                    // Re-parse to get fresh offsets after previous modifications
                    $freshAst = $parser->parse($code);
                    $freshClasses = findClassLikeNodes($freshAst);
                    foreach ($freshClasses as $fc) {
                        if ($fc->name->name !== $className) continue;
                        foreach ($fc->stmts as $fs) {
                            if (!$fs instanceof Node\Stmt\ClassMethod) continue;
                            if ($fs->name->toString() !== $methodName) continue;
                            $tagLines = extractTagBlock($fs->getDocComment());
                            $sourceLines = explode("\n", $code);
                            $methodLine = $fs->getStartLine();
                            $indent = preg_match('/^(\s*)/', $sourceLines[$methodLine - 1] ?? '', $m) ? $m[1] : '    ';
                            $newDoc = null !== $fs->getDocComment()
                                ? buildDocblockForReplacement($methodSummary, $tagLines, $indent)
                                : buildDocblockForInsertion($methodSummary, $tagLines, $indent);
                            $code = replaceDocblockInCode($code, $fs->getDocComment(), $newDoc, $methodLine);
                            $modified = true;
                            break 2;
                        }
                    }
                }
            }
        }

        if ($modified) {
            if ($dryRun) {
                echo "  [DRY-RUN] would migrate: {$relPath}\n";
            } else {
                file_put_contents($phpFile, $code);
                echo "  migrated: {$relPath}\n";
            }
            $stats['migrated']++;
        } else {
            echo "  skip: {$relPath} (nothing to migrate)\n";
            $stats['skipped']++;
        }
        continue;
    }

    // ── Strict mode: read-only validation, no writes ─────────────────

    if ($strict) {
        foreach ($classNodes as $classNode) {
            $classInfo = buildClassEntry($classNode, $namespace, $code, $printer);
            $fqcn = ($namespace ? $namespace . '\\' : '') . $classInfo['className'];

            if ('' === trim($classInfo['classSummary'])) {
                $strictErrors[] = "{$relPath}:{$classInfo['className']} — missing class docblock summary";
                $stats['missing_summaries']++;
            }


        }
        continue;
    }

    // ── Normal generation mode ────────────────────────────────────────

    if (!$force && file_exists($outputPath) && filemtime($outputPath) > filemtime($phpFile)) {
        echo "  skip: {$relPath} (up to date)\n";
        $stats['skipped']++;
        continue;
    }

    foreach ($classNodes as $classNode) {
        $classInfo = buildClassEntry($classNode, $namespace, $code, $printer);
        $fqcn = ($namespace ? $namespace . '\\' : '') . $classInfo['className'];

        $methodEntries = [];
        foreach ($classInfo['methods'] as $m) {
            $signature = trim($m['modifiers'] . ' function ' . $m['name'] . '(' . $m['params'] . ')');
            if ('' !== $m['returnType']) {
                $signature .= ': ' . $m['returnType'];
            }

            $methodEntries[] = [
                'method' => $m['name'],
                'start' => $m['docStartLine'],
                'end' => $m['endLine'],
                'limit' => $m['endLine'] - $m['docStartLine'] + 1,
                'symbolLine' => $m['symbolLine'],
                'symbolColumn' => $m['symbolColumn'],
                'signature' => $signature,
            ];
        }

        $classType = trim($classInfo['classModifiers'] . ' ' . $classInfo['classType']);
        $classSummary = '' !== trim($classInfo['classSummary']) ? $classInfo['classSummary'] : '—';

        $indexData = [
            'spec' => 'agent-core.file-index/v1',
            'file' => basename($phpFile),
            'class' => $fqcn,
            'type' => $classType,
            'summary' => $classSummary,
        ];
        if (!empty($methodEntries)) {
            $indexData['methods'] = $methodEntries;
        }

        $toonContent = Toon::encode($indexData);

        if ($dryRun) {
            $outRelPath = substr($outputPath, strlen($projectRoot) + 1);
            echo "  [DRY-RUN] would write: {$outRelPath}\n";
        } else {
            $docsDir = dirname($outputPath);
            if (!is_dir($docsDir)) mkdir($docsDir, 0777, true);
            file_put_contents($outputPath, $toonContent);
            $outRelPath = substr($outputPath, strlen($projectRoot) + 1);
            echo "  wrote: {$outRelPath}\n";
        }
        $stats['generated']++;
    }
}

// ── Strict mode: report errors ────────────────────────────────────────────

// Print stats before exit in strict mode
if (!$strict && !$migrate && !$skipNamespace && $stats['generated'] > 0) {
    echo "\n--- Regenerating namespace indexes ---\n";
    regenerateNamespaceIndexes($projectRoot, $dryRun);
}

// ── Stats ─────────────────────────────────────────────────────────────────

echo "\n--- Stats ---\n";
if ($strict) {
    if ([] !== $strictErrors) {
        foreach ($strictErrors as $error) {
            echo "MISSING: {$error}\n";
        }
        echo "\n";
    }
    echo "Checked: " . count($phpFiles) . "\n";
    echo "Missing: {$stats['missing_summaries']}\n";
} elseif ($migrate) {
    echo "Migrated:  {$stats['migrated']}\n";
    echo "Skipped:   {$stats['skipped']}\n";
} else {
    echo "Generated: {$stats['generated']}\n";
    echo "Skipped:   {$stats['skipped']}\n";
}
if ($dryRun) echo "\n(DRY-RUN — no files were written)\n";

// Exit with error in strict mode if summaries are missing
if ($strict && [] !== $strictErrors) {
    exit(1);
}

// ── Phase 2: Regenerate namespace indexes ─────────────────────────────────

if (!$strict && !$migrate && !$skipNamespace && $stats['generated'] > 0) {
    $srcDir = $projectRoot . '/src';
    $namespaces = [];

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iter as $f) {
        if ($f->getExtension() !== 'toon') continue;
        $path = $f->getPathname();
        if (!str_contains($path, '/docs/')) continue;

        $data = Toon::decode(file_get_contents($path));
        if (($data['spec'] ?? '') !== 'agent-core.file-index/v1') continue;

        $dir = dirname(dirname($path));
        $dir = realpath($dir);
        $namespaces[$dir][] = [
            'file' => basename($path, '.toon') . '.php',
            'type' => $data['type'] ?? '',
            'summary' => $data['summary'] ?? '',
        ];
    }

    foreach ($namespaces as $dir => $entries) {
        $indexPath = $dir . '/ai-index.toon';
        $existingIndex = null;
        if (file_exists($indexPath)) {
            $existingIndex = Toon::decode(file_get_contents($indexPath));
        }

        $relDir = substr($dir, strlen($srcDir) + 1);
        $namespace = basename($dir);
        $existingFqcn = '';
        $description = '';

        if ($existingIndex) {
            $namespace = $existingIndex['namespace'] ?? $namespace;
            $existingFqcn = $existingIndex['fqcn'] ?? '';
            $description = $existingIndex['description'] ?? '';
        }

        $derivedFqcn = deriveFqcnFromSrcRelativeDir($relDir);
        $fqcn = isUsableExistingFqcn($existingFqcn)
            ? $existingFqcn
            : $derivedFqcn;

        $fileEntries = [];
        foreach ($entries as $e) {
            $fileEntries[] = [
                'file' => $e['file'],
                'type' => $e['type'],
                'summary' => $e['summary'],
            ];
        }
        usort($fileEntries, static fn (array $left, array $right): int => $left['file'] <=> $right['file']);

        /** @var list<array<string, mixed>> $subNamespaces */
        $subNamespaces = ($existingIndex && isset($existingIndex['subNamespaces']) && is_array($existingIndex['subNamespaces']))
            ? $existingIndex['subNamespaces']
            : [];

        $newIndex = [
            'spec' => 'agent-core.ai-docs/v1',
            'namespace' => $namespace,
            'fqcn' => $fqcn,
            'updatedAt' => date('Y-m-d'),
        ];
        if ($description) {
            $newIndex['description'] = $description;
        }
        $newIndex['files'] = $fileEntries;
        if ([] !== $subNamespaces) {
            $newIndex['subNamespaces'] = $subNamespaces;
        }

        $toonContent = Toon::encode($newIndex);

        if ($dryRun) {
            $outRel = substr($indexPath, strlen($projectRoot) + 1);
            echo "  [DRY-RUN] would update: {$outRel}\n";
        } else {
            file_put_contents($indexPath, $toonContent);
            $outRel = substr($indexPath, strlen($projectRoot) + 1);
            echo "  updated: {$outRel}\n";
        }
    }

    // Also regenerate parent namespaces (ones with only subNamespaces)
    $parentDirs = [];
    foreach (array_keys($namespaces) as $dir) {
        $parent = dirname($dir);
        while (str_starts_with($parent, $srcDir)) {
            $parentDirs[$parent] = true;
            $parent = dirname($parent);
        }
    }

    foreach (array_keys($parentDirs) as $dir) {
        $indexPath = $dir . '/ai-index.toon';
        if (!file_exists($indexPath)) continue;

        $data = Toon::decode(file_get_contents($indexPath));
        $data['updatedAt'] = date('Y-m-d');

        if ($dryRun) {
            $outRel = substr($indexPath, strlen($projectRoot) + 1);
            echo "  [DRY-RUN] would touch: {$outRel}\n";
        } else {
            file_put_contents($indexPath, Toon::encode($data));
            $outRel = substr($indexPath, strlen($projectRoot) + 1);
            echo "  touched: {$outRel}\n";
        }
    }
}

// ── Helper: load existing .toon file ──────────────────────────────────────

function loadToonSummary(string $outputPath): ?array
{
    if (!file_exists($outputPath)) return null;
    $data = Toon::decode(file_get_contents($outputPath));
    if (($data['spec'] ?? '') !== 'agent-core.file-index/v1') return null;
    return $data;
}

// ── Namespace index regeneration ──────────────────────────────────────────

function regenerateNamespaceIndexes(string $projectRoot, bool $dryRun): void
{
    $srcDir = $projectRoot . '/src';
    $namespaces = [];

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iter as $f) {
        if ($f->getExtension() !== 'toon') continue;
        $path = $f->getPathname();
        if (!str_contains($path, '/docs/')) continue;

        $data = Toon::decode(file_get_contents($path));
        if (($data['spec'] ?? '') !== 'agent-core.file-index/v1') continue;

        $dir = dirname($path, 2);
        $dir = realpath($dir);
        $namespaces[$dir][] = [
            'file' => basename($path, '.toon') . '.php',
            'type' => $data['type'] ?? '',
            'summary' => $data['summary'] ?? '',
        ];
    }

    foreach ($namespaces as $dir => $entries) {
        $indexPath = $dir . '/ai-index.toon';
        $existingIndex = null;
        if (file_exists($indexPath)) {
            $existingIndex = Toon::decode(file_get_contents($indexPath));
        }

        $relDir = substr($dir, strlen($srcDir) + 1);
        $namespace = basename($dir);
        $existingFqcn = '';
        $description = '';

        if ($existingIndex) {
            $namespace = $existingIndex['namespace'] ?? $namespace;
            $existingFqcn = $existingIndex['fqcn'] ?? '';
            $description = $existingIndex['description'] ?? '';
        }

        $derivedFqcn = deriveFqcnFromSrcRelativeDir($relDir);
        $fqcn = isUsableExistingFqcn($existingFqcn)
            ? $existingFqcn
            : $derivedFqcn;

        $fileEntries = [];
        foreach ($entries as $e) {
            $fileEntries[] = [
                'file' => $e['file'],
                'type' => $e['type'],
                'summary' => $e['summary'],
            ];
        }
        usort($fileEntries, static fn (array $left, array $right): int => $left['file'] <=> $right['file']);

        /** @var list<array<string, mixed>> $subNamespaces */
        $subNamespaces = ($existingIndex && isset($existingIndex['subNamespaces']) && is_array($existingIndex['subNamespaces']))
            ? $existingIndex['subNamespaces']
            : [];

        $newIndex = [
            'spec' => 'agent-core.ai-docs/v1',
            'namespace' => $namespace,
            'fqcn' => $fqcn,
            'updatedAt' => date('Y-m-d'),
        ];
        if ($description) {
            $newIndex['description'] = $description;
        }
        $newIndex['files'] = $fileEntries;
        if ([] !== $subNamespaces) {
            $newIndex['subNamespaces'] = $subNamespaces;
        }

        $toonContent = Toon::encode($newIndex);

        if ($dryRun) {
            $outRel = substr($indexPath, strlen($projectRoot) + 1);
            echo "  [DRY-RUN] would update: {$outRel}\n";
        } else {
            file_put_contents($indexPath, $toonContent);
            $outRel = substr($indexPath, strlen($projectRoot) + 1);
            echo "  updated: {$outRel}\n";
        }
    }

    // Also regenerate parent namespaces (ones with only subNamespaces)
    $parentDirs = [];
    foreach (array_keys($namespaces) as $dir) {
        $parent = dirname($dir);
        while (str_starts_with($parent, $srcDir)) {
            $parentDirs[$parent] = true;
            $parent = dirname($parent);
        }
    }

    foreach (array_keys($parentDirs) as $dir) {
        $indexPath = $dir . '/ai-index.toon';
        if (!file_exists($indexPath)) continue;

        $data = Toon::decode(file_get_contents($indexPath));
        $data['updatedAt'] = date('Y-m-d');

        if ($dryRun) {
            $outRel = substr($indexPath, strlen($projectRoot) + 1);
            echo "  [DRY-RUN] would touch: {$outRel}\n";
        } else {
            file_put_contents($indexPath, Toon::encode($data));
            $outRel = substr($indexPath, strlen($projectRoot) + 1);
            echo "  touched: {$outRel}\n";
        }
    }
}

function deriveFqcnFromSrcRelativeDir(string $relDir): string
{
    $normalized = trim($relDir, '/');
    if ('' === $normalized || '.' === $normalized) {
        return 'Ineersa\\AgentCore';
    }

    $parts = array_values(array_filter(explode('/', $normalized), static fn (string $part): bool => '' !== $part));

    return 'Ineersa\\AgentCore\\' . implode('\\', $parts);
}

function isUsableExistingFqcn(string $fqcn): bool
{
    if ('' === $fqcn) return false;
    if (!str_starts_with($fqcn, 'Ineersa\\AgentCore')) return false;
    if ($fqcn === 'Ineersa\\AgentCore\\src' || str_contains($fqcn, '\\src\\')) return false;
    return true;
}
