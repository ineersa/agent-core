#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate per-file method indexes (no LLM required).
 *
 * Produces docs/<File>.toon per PHP class plus namespace-level ai-index.toon files.
 *
 * Usage:
 *   php scripts/generate-method-index.php [options] [file_or_dir ...]
 *
 * Options:
 *   --all             Process all PHP files in src/
 *   --changed         Process only git-changed PHP files (default if no targets given)
 *   --dry-run         Show what would be done without writing
 *   --force           Regenerate even if index is newer than source file
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
$skipNamespace = false;
$targets = [];

foreach ($args as $arg) {
    if ($arg === '--all') { $all = true; continue; }
    if ($arg === '--changed') { $changed = true; continue; }
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if ($arg === '--force') { $force = true; continue; }
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

echo "Processing " . count($phpFiles) . " file(s)...\n\n";

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
 * Load callgraph.json and build a map of [class][method] => {callers, callees}.
 *
 * Filters out plain functions, vendor classes, and unresolved edges.
 * Only includes callers/callees within the project namespace.
 *
 * @return array<string, array<string, array{callers: list<string>, callees: list<string>}>>
 */
function loadCallGraph(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $json = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $edges = $json['edges'] ?? [];

    $map = [];
    foreach ($edges as $edge) {
        // Skip plain functions
        if (($edge['calleeKind'] ?? '') === 'function' || ($edge['callerKind'] ?? '') === 'function') {
            continue;
        }

        // Skip unresolved calls
        if ($edge['unresolved'] ?? false) {
            continue;
        }

        $callerClass = $edge['callerClass'] ?? '';
        $callerMethod = $edge['callerMember'] ?? '';
        $calleeClass = $edge['calleeClass'] ?? '';
        $calleeMethod = $edge['calleeMember'] ?? '';

        // Record callee from caller's perspective
        if ('' !== $callerClass && '' !== $callerMethod && '' !== $calleeClass && '' !== $calleeMethod) {
            $map[$callerClass][$callerMethod]['callees'][] = $calleeClass . '::' . $calleeMethod;
            $map[$calleeClass][$calleeMethod]['callers'][] = $callerClass . '::' . $callerMethod;
        }
    }

    // Deduplicate
    foreach ($map as &$methods) {
        foreach ($methods as &$entry) {
            $entry['callers'] = array_values(array_unique($entry['callers'] ?? []));
            $entry['callees'] = array_values(array_unique($entry['callees'] ?? []));
        }
    }

    return $map;
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
 * @param list<Node> $nodes
 *
 * @return array{kind: string, start: int, end: int, limit: int}|null
 */
function buildSectionFromNodes(string $kind, array $nodes): ?array
{
    if ([] === $nodes) {
        return null;
    }

    $start = min(array_map(static fn (Node $node): int => $node->getStartLine(), $nodes));
    $end = max(array_map(static fn (Node $node): int => $node->getEndLine(), $nodes));

    return [
        'kind' => $kind,
        'start' => $start,
        'end' => $end,
        'limit' => $end - $start + 1,
    ];
}

function findConstructorMethod(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Interface_ $class): ?Node\Stmt\ClassMethod
{
    foreach ($class->stmts as $stmt) {
        if (!$stmt instanceof Node\Stmt\ClassMethod) {
            continue;
        }
        if ('__construct' === strtolower($stmt->name->toString())) {
            return $stmt;
        }
    }

    return null;
}

/**
 * @return list<array<string, int|string>>
 */
function extractClassSections(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Interface_ $class): array
{
    $sections = [];

    $classDoc = $class->getDocComment();
    if (null !== $classDoc) {
        $classDocStart = $classDoc->getStartLine();
        $classDocEnd = $classDoc->getEndLine();
        $sections[] = [
            'kind' => 'classDoc',
            'start' => $classDocStart,
            'end' => $classDocEnd,
            'limit' => $classDocEnd - $classDocStart + 1,
        ];
    }

    $constantNodes = [];
    $propertyNodes = [];
    foreach ($class->stmts as $stmt) {
        if ($stmt instanceof Node\Stmt\ClassConst || $stmt instanceof Node\Stmt\EnumCase) {
            $constantNodes[] = $stmt;
        }
        if ($stmt instanceof Node\Stmt\Property) {
            $propertyNodes[] = $stmt;
        }
    }

    $constantsSection = buildSectionFromNodes('constants', $constantNodes);
    if (null !== $constantsSection) {
        $sections[] = $constantsSection;
    }

    $propertiesSection = buildSectionFromNodes('properties', $propertyNodes);
    if (null !== $propertiesSection) {
        $sections[] = $propertiesSection;
    }

    $constructor = findConstructorMethod($class);
    if (null !== $constructor) {
        $constructorDoc = $constructor->getDocComment();
        $constructorStart = $constructorDoc?->getStartLine() ?? $constructor->getStartLine();
        $constructorEnd = $constructor->getEndLine();

        $constructorSection = [
            'kind' => 'constructor',
            'start' => $constructorStart,
            'end' => $constructorEnd,
            'limit' => $constructorEnd - $constructorStart + 1,
            'signatureLine' => $constructor->name->getStartLine(),
        ];

        if (null !== $constructorDoc) {
            $constructorSection['commentStart'] = $constructorDoc->getStartLine();
        }

        $sections[] = $constructorSection;
    }

    return $sections;
}

/**
 * @return list<array{param: string, type: string, required: bool}>
 */
function extractConstructorInputs(?Node\Stmt\ClassMethod $constructor): array
{
    if (null === $constructor) {
        return [];
    }

    $inputs = [];
    foreach ($constructor->getParams() as $param) {
        $type = typeToString($param->type);

        $inputs[] = [
            'param' => '$' . $param->var->name,
            'type' => '' !== $type ? $type : 'mixed',
            'required' => null === $param->default && !$param->variadic,
        ];
    }

    return $inputs;
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

    $sections = extractClassSections($class);
    $constructorInputs = extractConstructorInputs(findConstructorMethod($class));

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
        ];
    }

    return [
        'node' => $class,
        'namespace' => $namespace,
        'className' => $class->name->name,
        'classType' => $classType,
        'classModifiers' => implode(' ', $classModifiers),
        'sections' => $sections,
        'constructorInputs' => $constructorInputs,
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

// ── Build work items ──────────────────────────────────────────────────────

$stats = ['generated' => 0, 'skipped' => 0];

// ── Load call graph ────────────────────────────────────────────────────

$callGraphPath = $projectRoot . '/callgraph.json';
$callgraphNeon = $projectRoot . '/vendor/ineersa/call-graph/callgraph.neon';
if (file_exists($callgraphNeon)) {
    $phpstanBin = $projectRoot . '/vendor/bin/phpstan';
    if (file_exists($phpstanBin)) {
        echo "Generating call graph...\n";
        passthru(escapeshellarg($phpstanBin) . ' analyse -c ' . escapeshellarg($callgraphNeon) . ' ./src --no-progress --no-ansi 2>/dev/null', $cgExit);
        if (0 !== $cgExit && !file_exists($callGraphPath)) {
            echo "  warning: call graph generation failed (exit={$cgExit}), proceeding without call data\n";
        }
    }
}

$callGraph = loadCallGraph($callGraphPath);

$diWiringByClass = [];
$diWiringPath = $projectRoot.'/var/reports/di-wiring.toon';
$diWiringByClass = loadDiWiringByClass($diWiringPath);
if ([] === $diWiringByClass) {
    echo "  warning: no DI wiring map found at var/reports/di-wiring.toon; skipping wiring metadata\n";
}

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

            $entry = [
                'method' => $m['name'],
                'start' => $m['docStartLine'],
                'end' => $m['endLine'],
                'limit' => $m['endLine'] - $m['docStartLine'] + 1,
                'symbolLine' => $m['symbolLine'],
                'symbolColumn' => $m['symbolColumn'],
                'signature' => $signature,
            ];

            $callees = $callGraph[$fqcn][$m['name']]['callees'] ?? [];
            $callers = $callGraph[$fqcn][$m['name']]['callers'] ?? [];
            if (!empty($callees)) {
                $entry['callees'] = $callees;
            }
            if (!empty($callers)) {
                $entry['callers'] = $callers;
            }

            $methodEntries[] = $entry;
        }

        $classType = trim($classInfo['classModifiers'] . ' ' . $classInfo['classType']);

        $indexData = [
            'spec' => 'agent-core.file-index/v1',
            'file' => basename($phpFile),
            'class' => $fqcn,
            'type' => $classType,
        ];
        if (!empty($classInfo['sections'])) {
            $indexData['sections'] = $classInfo['sections'];
        }
        if (!empty($classInfo['constructorInputs'])) {
            $indexData['constructorInputs'] = $classInfo['constructorInputs'];
        }

        $wiring = $diWiringByClass[$fqcn] ?? [];
        if ([] !== $wiring) {
            $indexData['wiring'] = $wiring;
        }

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

if (!$skipNamespace && $stats['generated'] > 0) {
    echo "\n--- Regenerating namespace indexes ---\n";
    regenerateNamespaceIndexes($projectRoot, $dryRun);
}

// ── Stats ─────────────────────────────────────────────────────────────────

echo "\n--- Stats ---\n";
echo "Generated: {$stats['generated']}\n";
echo "Skipped:   {$stats['skipped']}\n";
if ($dryRun) echo "\n(DRY-RUN — no files were written)\n";

// ── Helper: load existing .toon file ──────────────────────────────────────

/**
 * @return array<string, array<string, mixed>>
 */
function loadDiWiringByClass(string $wiringPath): array
{
    if (!file_exists($wiringPath)) {
        return [];
    }

    $payload = Toon::decode(file_get_contents($wiringPath));
    if (($payload['spec'] ?? '') !== 'agent-core.di-wiring/v1') {
        return [];
    }

    $entries = $payload['classes'] ?? [];
    if (!is_array($entries)) {
        return [];
    }

    $byClass = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $className = $entry['class'] ?? '';
        if (!is_string($className) || '' === trim($className)) {
            continue;
        }

        $wiring = [];

        $serviceDefinitions = $entry['serviceDefinitions'] ?? null;
        if (is_array($serviceDefinitions) && [] !== $serviceDefinitions) {
            $wiring['serviceDefinitions'] = $serviceDefinitions;
        }

        $aliases = $entry['aliases'] ?? null;
        if (is_array($aliases) && [] !== $aliases) {
            $wiring['aliases'] = $aliases;
        }

        $injectedInto = $entry['injectedInto'] ?? null;
        if (is_array($injectedInto) && [] !== $injectedInto) {
            $wiring['injectedInto'] = $injectedInto;
        }

        if ([] !== $wiring) {
            $byClass[$className] = $wiring;
        }
    }

    return $byClass;
}

/**
 * @param array<string, mixed> $index
 *
 * @return array<string, mixed>
 */
function stripLegacyNamespaceMetadata(array $index): array
{
    unset($index['indexedAt'], $index['indexedCommit'], $index['sourceHash']);

    return $index;
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
        ];
    }

    foreach ($namespaces as $dir => $entries) {
        $indexPath = $dir . '/ai-index.toon';
        $existingIndex = null;
        if (file_exists($indexPath)) {
            $existingIndex = stripLegacyNamespaceMetadata(Toon::decode(file_get_contents($indexPath)));
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

        $data = stripLegacyNamespaceMetadata(Toon::decode(file_get_contents($indexPath)));
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
