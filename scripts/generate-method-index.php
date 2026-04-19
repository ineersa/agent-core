#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate per-file method indexes using PHP-Parser + LLM with structured output.
 *
 * Produces one docs/<File>.toon per PHP class containing class summary + method summaries.
 * Then regenerates namespace-level ai-index.toon files with embedded class summaries.
 *
 * Usage:
 *   php scripts/generate-method-index.php [options] [file_or_dir ...]
 *
 * Options:
 *   --all           Process all PHP files in src/
 *   --changed       Process only git-changed PHP files (default if no targets given)
 *   --dry-run       Show what would be done without writing
 *   --endpoint=URL  LLM endpoint (default: LLM_INDEX_ENDPOINT env or http://localhost:8052/v1/chat/completions)
 *   --model=NAME    Model name (default: flash)
 *   --force         Regenerate even if index is newer than source file
 *   --concurrency=N Parallel requests (default: 2)
 *   --skip-namespace  Skip namespace index regeneration
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use HelgeSverre\Toon\Toon;
use PhpParser\Node;
use PhpParser\ParserFactory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// ── Config ────────────────────────────────────────────────────────────────

$args = array_slice($argv, 1);
$all = false;
$changed = false;
$dryRun = false;
$force = false;
$skipNamespace = false;
$endpoint = getenv('LLM_INDEX_ENDPOINT') ?: 'http://localhost:8052/v1/chat/completions';
$model = 'flash';
$concurrency = 2;
$targets = [];

foreach ($args as $arg) {
    if ($arg === '--all') { $all = true; continue; }
    if ($arg === '--changed') { $changed = true; continue; }
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if ($arg === '--force') { $force = true; continue; }
    if ($arg === '--skip-namespace') { $skipNamespace = true; continue; }
    if (str_starts_with($arg, '--endpoint=')) { $endpoint = substr($arg, 11); continue; }
    if (str_starts_with($arg, '--model=')) { $model = substr($arg, 8); continue; }
    if (str_starts_with($arg, '--concurrency=')) { $concurrency = (int) substr($arg, 14); continue; }
    $targets[] = $arg;
}

if (!$all && !$changed && empty($targets)) {
    $changed = true;
}

$projectRoot = dirname(__DIR__);
$indexedAt = gmdate('Y-m-d\TH:i:s\Z');
$indexedCommit = resolveIndexedCommit($projectRoot);

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
echo "Processing " . count($phpFiles) . " file(s), concurrency={$concurrency}...\n\n";

// ── AST extraction ────────────────────────────────────────────────────────

$parser = new ParserFactory()->createForHostVersion();

function getMethodVisibility(Node\Stmt\ClassMethod $method): string
{
    if ($method->isPublic()) {
        return 'public';
    }
    if ($method->isProtected()) {
        return 'protected';
    }

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
    if (null === $type) {
        return '';
    }
    if ($type instanceof Node\NullableType) {
        return '?' . typeToString($type->type);
    }
    if ($type instanceof Node\UnionType) {
        return implode('|', array_map(typeToString(...), $type->types));
    }
    if ($type instanceof Node\IntersectionType) {
        return implode('&', array_map(typeToString(...), $type->types));
    }

    return $type->toString();
}

function offsetToColumn(string $code, int $offset): int
{
    if ($offset < 0) {
        return 1;
    }

    $before = substr($code, 0, $offset);
    $lineStartOffset = strrpos($before, "\n");
    if (false === $lineStartOffset) {
        return $offset + 1;
    }

    return $offset - $lineStartOffset;
}

function extractThrowsFromDoc(?PhpParser\Comment\Doc $doc): array
{
    if (null === $doc) {
        return [];
    }

    $throws = [];
    preg_match_all('/@throws\s+([^\s*]+)/', $doc->getText(), $matches);
    foreach ($matches[1] ?? [] as $throwType) {
        $throws[] = trim($throwType);
    }

    return array_values(array_unique($throws));
}

function extractThrowsFromBody(Node\Stmt\ClassMethod $method): array
{
    if (null === $method->stmts) {
        return [];
    }

    $finder = new PhpParser\NodeFinder();
    $throws = [];
    /** @var list<Node\Expr\Throw_> $throwNodes */
    $throwNodes = $finder->findInstanceOf($method->stmts, Node\Expr\Throw_::class);
    foreach ($throwNodes as $throwNode) {
        if ($throwNode->expr instanceof Node\Expr\Throw_) {
            continue;
        }

        if ($throwNode->expr instanceof Node\Expr\New_ && $throwNode->expr->class instanceof Node\Name) {
            $throws[] = $throwNode->expr->class->toString();
            continue;
        }

        $throws[] = 'Throwable';
    }

    return array_values(array_unique($throws));
}

function buildMethodParams(Node\Stmt\ClassMethod $method, PhpParser\PrettyPrinter\Standard $printer): string
{
    $parts = [];
    foreach ($method->getParams() as $param) {
        $chunks = [];
        $paramType = typeToString($param->type);
        if ('' !== $paramType) {
            $chunks[] = $paramType;
        }
        if ($param->byRef) {
            $chunks[] = '&';
        }
        if ($param->variadic) {
            $chunks[] = '...';
        }
        $chunks[] = '$' . $param->var->name;
        if (null !== $param->default) {
            $chunks[] = '= ' . $printer->prettyPrintExpr($param->default);
        }
        $parts[] = implode(' ', $chunks);
    }

    return implode(', ', $parts);
}

function extractClasses(string $code, PhpParser\Parser $parser): array
{
    $ast = $parser->parse($code);
    if (!$ast) return [];

    $printer = new PhpParser\PrettyPrinter\Standard();
    $results = [];
    foreach ($ast as $node) {
        $results = [...$results, ...processAstNode($node, $code, $printer)];
    }

    return $results;
}

function processAstNode(Node $node, string $code, PhpParser\PrettyPrinter\Standard $printer, string $namespace = ''): array
{
    $results = [];
    $classLike = [Node\Stmt\Class_::class, Node\Stmt\Trait_::class, Node\Stmt\Enum_::class, Node\Stmt\Interface_::class];

    foreach ($classLike as $type) {
        if ($node instanceof $type) {
            $results[] = buildClassEntry($node, $namespace, $code, $printer);
            return $results;
        }
    }

    if ($node instanceof Node\Stmt\Namespace_) {
        $ns = $node->name->toString();
        foreach ($node->stmts as $sub) {
            $results = [...$results, ...processAstNode($sub, $code, $printer, $ns)];
        }
    }

    return $results;
}

function buildClassEntry(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Interface_ $class, string $namespace, string $code, PhpParser\PrettyPrinter\Standard $printer): array
{
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

    $methods = [];
    foreach ($class->stmts as $stmt) {
        if (!$stmt instanceof Node\Stmt\ClassMethod) {
            continue;
        }

        $doc = $stmt->getDocComment();
        $docStart = $doc?->getStartLine();
        $signatureLine = $stmt->getStartLine();
        $symbolLine = $stmt->name->getStartLine();
        $symbolColumn = offsetToColumn($code, $stmt->name->getStartFilePos());
        $throws = array_values(array_unique([...extractThrowsFromDoc($doc), ...extractThrowsFromBody($stmt)]));

        $methods[] = [
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
        'namespace' => $namespace,
        'className' => $class->name->name,
        'classType' => $classType,
        'classModifiers' => implode(' ', $classModifiers),
        'methods' => $methods,
    ];
}

function extractMethodSignatures(string $code, array $methods): string
{
    $lines = explode("\n", $code);
    $sigs = [];
    foreach ($methods as $m) {
        $start = $m['signatureLine'] - 1;
        $end = $m['endLine'] - 1;
        $sigLines = [];

        for ($i = $start; $i <= min($end, $start + 5); $i++) {
            $line = $lines[$i];
            $sigLines[] = $line;
            if (str_contains($line, '{') || str_contains($line, ';')) break;
        }
        $sigs[] = trim(implode("\n", $sigLines));
    }

    return implode("\n", $sigs);
}

function resolveIndexedCommit(string $projectRoot): string
{
    $commit = trim((string) shell_exec(sprintf('git -C %s rev-parse HEAD 2>/dev/null', escapeshellarg($projectRoot))));

    return '' === $commit ? 'unknown' : $commit;
}

function hashContent(string $content): string
{
    return hash('sha256', $content);
}

/**
 * @param list<array<string, mixed>> $files
 * @param list<array<string, mixed>> $subNamespaces
 */
function hashNamespaceIndexSource(array $files, array $subNamespaces = []): string
{
    $payload = [
        'files' => $files,
        'subNamespaces' => $subNamespaces,
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (false === $encoded) {
        return hash('sha256', serialize($payload));
    }

    return hash('sha256', $encoded);
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
    if ('' === $fqcn) {
        return false;
    }

    if (!str_starts_with($fqcn, 'Ineersa\\AgentCore')) {
        return false;
    }

    if ($fqcn === 'Ineersa\\AgentCore\\src' || str_contains($fqcn, '\\src\\')) {
        return false;
    }

    return true;
}

// ── LLM ───────────────────────────────────────────────────────────────────

$responseSchema = [
    'type' => 'object',
    'properties' => [
        'classSummary' => [
            'type' => 'string',
            'description' => '2-3 sentence summary of what this class does',
        ],
        'classEffects' => [
            'type' => 'string',
            'description' => 'One line of concrete side effects for the class; use none when no side effects',
        ],
        'classDependencies' => [
            'type' => 'string',
            'description' => 'One line of concrete dependencies (stores/buses/services); use none when not applicable',
        ],
        'methods' => [
            'type' => 'object',
            'description' => 'Map of method name to summary/effects/dependencies',
            'additionalProperties' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'effects' => ['type' => 'string'],
                    'dependencies' => ['type' => 'string'],
                ],
                'required' => ['summary', 'effects', 'dependencies'],
            ],
        ],
    ],
    'required' => ['classSummary', 'classEffects', 'classDependencies', 'methods'],
];

function buildPrompt(array $item): string
{
    $methodListStr = implode(', ', $item['methodNames']);
    $filePath = $item['relPath'] ?? 'unknown';

    return <<<PROMPT
Generate documentation for this PHP class.

Rules:
- classSummary: 2-3 sentences describing the class purpose and design. Do NOT mention stage numbers, milestones, implementation history, or test-only details.
- classEffects: one concise line of concrete side-effects (state writes, dispatch, I/O); use "none" if none.
- classDependencies: one concise line of concrete dependencies (stores, bus, services); use "none" if none.
- methods: map each method name to an object with:
  - summary: ONE-line summary (max 120 chars), present tense, no period
  - effects: ONE-line side-effect hint (writes, dispatches, external call, or none)
  - dependencies: ONE-line dependency hint (which collaborators it touches, or none)
- Prefer concrete nouns from signatures and class name/path.
- Avoid vague wording like "handles the request" or "manages logic".
- If class belongs to transport/integration boundaries (Controller/Http/Mercure/Serializer/Publisher/Reader/Writer/Store), mention the boundary explicitly (HTTP route/payload, topic/event envelope, filesystem/db write, bus dispatch).
- If signatures indicate auth/replay/cursor/sequence semantics (tenant/user, Last-Event-ID, cursor, seq, resync), reflect those semantics explicitly in summaries.

File: {$filePath}
Class: {$item['fqcn']}
Type: {$item['classType']}
Methods: {$methodListStr}

Signatures:
{$item['signatures']}
PROMPT;
}

function sendLlmRequest(HttpClientInterface $client, string $endpoint, string $model, string $prompt, array $schema): ResponseInterface
{
    return $client->request('POST', $endpoint, [
        'json' => [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.1,
            'max_tokens' => 65536,
            'response_format' => ['type' => 'json_object', 'schema' => $schema],
        ],
    ]);
}

function fetchLlmResult(HttpClientInterface $client, string $endpoint, string $model, array $item, array $schema, int $maxRetries = 2): array
{
    $prompt = buildPrompt($item);
    $lastError = '';

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        try {
            $response = sendLlmRequest($client, $endpoint, $model, $prompt, $schema);
            $data = $response->toArray();
            $content = $data['choices'][0]['message']['content'] ?? '';

            if ($content === '') {
                $lastError = 'Empty LLM response (reasoning used all tokens)';
                if ($attempt < $maxRetries) {
                    sleep(2);
                    continue;
                }
                break;
            }

            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                $lastError = "Invalid JSON: " . substr($content, 0, 200);
                break;
            }

            return $parsed;
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
            if ($attempt < $maxRetries) {
                sleep(2 << $attempt); // exponential backoff
            }
        }
    }

    throw new \RuntimeException($lastError);
}

// ── Build work items ──────────────────────────────────────────────────────

$workItems = [];
$stats = ['generated' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($phpFiles as $phpFile) {
    $relPath = substr($phpFile, strlen($projectRoot) + 1);
    $code = file_get_contents($phpFile);
    $sourceHash = hashContent($code);
    $classInfos = extractClasses($code, $parser);

    if (empty($classInfos)) {
        echo "  skip: {$relPath} (no classes)\n";
        $stats['skipped']++;
        continue;
    }

    $dir = dirname($phpFile);
    $filename = basename($phpFile, '.php');
    $outputPath = $dir . '/docs/' . $filename . '.toon';

    if (!$force && file_exists($outputPath) && filemtime($outputPath) > filemtime($phpFile)) {
        echo "  skip: {$relPath} (up to date)\n";
        $stats['skipped']++;
        continue;
    }

    foreach ($classInfos as $classInfo) {
        $fqcn = ($classInfo['namespace'] ? $classInfo['namespace'] . '\\' : '') . $classInfo['className'];
        $methodNames = array_map(fn($m) => $m['name'], $classInfo['methods']);
        $signatures = extractMethodSignatures($code, $classInfo['methods']);

        $workItems[] = [
            'phpFile' => $phpFile,
            'relPath' => $relPath,
            'outputPath' => $outputPath,
            'classInfo' => $classInfo,
            'fqcn' => $fqcn,
            'methodNames' => $methodNames,
            'signatures' => $signatures,
            'classType' => trim($classInfo['classModifiers'] . ' ' . $classInfo['classType']),
            'sourceHash' => $sourceHash,
        ];
    }
}

if (empty($workItems)) {
    echo "\nNothing to generate.\n";
} else {
    echo "Calling LLM for " . count($workItems) . " class(es)...\n\n";

    $client = HttpClient::create(['timeout' => 180]);

    // Process in batches
    $batches = array_chunk($workItems, $concurrency);
    foreach ($batches as $batch) {
        $responses = [];
        foreach ($batch as $i => $item) {
            $prompt = buildPrompt($item);
            $responses[$i] = sendLlmRequest($client, $endpoint, $model, $prompt, $responseSchema);
        }

        foreach ($responses as $i => $response) {
            $item = $batch[$i];
            try {
                $data = $response->toArray();
                $content = $data['choices'][0]['message']['content'] ?? '';
                if ($content === '') {
                    throw new \RuntimeException('Empty response');
                }
                $parsed = json_decode($content, true);
                if (!is_array($parsed)) {
                    throw new \RuntimeException("Invalid JSON: " . substr($content, 0, 200));
                }
                $classSummary = $parsed['classSummary'] ?? '';
                $classEffects = $parsed['classEffects'] ?? 'none';
                $classDependencies = $parsed['classDependencies'] ?? 'none';
                $methodDocs = $parsed['methods'] ?? [];
            } catch (\Throwable $e) {
                // Retry individually with backoff
                try {
                    $parsed = fetchLlmResult($client, $endpoint, $model, $item, $responseSchema);
                    $classSummary = $parsed['classSummary'] ?? '';
                    $classEffects = $parsed['classEffects'] ?? 'none';
                    $classDependencies = $parsed['classDependencies'] ?? 'none';
                    $methodDocs = $parsed['methods'] ?? [];
                } catch (\Throwable $e2) {
                    echo "  FAIL: {$item['relPath']} :: {$item['fqcn']} — " . $e2->getMessage() . "\n";
                    $stats['failed']++;
                    continue;
                }
            }

            $methodEntries = [];
            foreach ($item['classInfo']['methods'] as $m) {
                $doc = $methodDocs[$m['name']] ?? [];
                $methodSummary = is_array($doc) ? ($doc['summary'] ?? '—') : (is_string($doc) ? $doc : '—');
                $methodEffects = is_array($doc) ? ($doc['effects'] ?? 'none') : 'none';
                $methodDependencies = is_array($doc) ? ($doc['dependencies'] ?? 'none') : 'none';

                $methodEntries[] = [
                    'method' => $m['name'],
                    'commentStart' => $m['docStartLine'],
                    'signatureLine' => $m['signatureLine'],
                    'symbolLine' => $m['symbolLine'],
                    'symbolColumn' => $m['symbolColumn'],
                    'end' => $m['endLine'],
                    'modifiers' => $m['modifiers'],
                    'visibility' => $m['visibility'],
                    'static' => $m['static'] ? 'yes' : 'no',
                    'final' => $m['final'] ? 'yes' : 'no',
                    'abstract' => $m['abstract'] ? 'yes' : 'no',
                    'params' => $m['params'],
                    'returns' => $m['returnType'],
                    'throws' => $m['throws'],
                    'summary' => $methodSummary,
                    'effects' => $methodEffects,
                    'dependencies' => $methodDependencies,
                ];
            }

            $indexData = [
                'spec' => 'agent-core.file-index/v1',
                'indexedAt' => $indexedAt,
                'indexedCommit' => $indexedCommit,
                'sourceHash' => $item['sourceHash'],
                'file' => basename($item['phpFile']),
                'class' => $item['fqcn'],
                'type' => $item['classType'],
                'summary' => $classSummary,
                'effects' => $classEffects,
                'dependencies' => $classDependencies,
            ];
            if (!empty($methodEntries)) {
                $indexData['methods'] = $methodEntries;
            }

            $toonContent = Toon::encode($indexData);

            if ($dryRun) {
                $outRelPath = substr($item['outputPath'], strlen($projectRoot) + 1);
                echo "  [DRY-RUN] would write: {$outRelPath}\n";
            } else {
                $docsDir = dirname($item['outputPath']);
                if (!is_dir($docsDir)) mkdir($docsDir, 0777, true);
                file_put_contents($item['outputPath'], $toonContent);
                $outRelPath = substr($item['outputPath'], strlen($projectRoot) + 1);
                echo "  wrote: {$outRelPath}\n";
            }
            $stats['generated']++;
        }
    }
}

// ── Phase 2: Regenerate namespace indexes ─────────────────────────────────

if (!$skipNamespace && $stats['generated'] > 0) {
    echo "\n--- Regenerating namespace indexes ---\n";
    regenerateNamespaceIndexes($projectRoot, $dryRun, $indexedAt, $indexedCommit);
}

echo "\n--- Stats ---\n";
echo "Generated: {$stats['generated']}\n";
echo "Skipped:   {$stats['skipped']}\n";
echo "Failed:    {$stats['failed']}\n";
if ($dryRun) echo "\n(DRY-RUN — no files were written)\n";

// ── Namespace index regeneration ──────────────────────────────────────────

function regenerateNamespaceIndexes(string $projectRoot, bool $dryRun, string $indexedAt, string $indexedCommit): void
{
    $srcDir = $projectRoot . '/src';
    $namespaces = [];

    // Scan all docs/*.toon files and group by namespace directory
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iter as $f) {
        if ($f->getExtension() !== 'toon') continue;
        $path = $f->getPathname();
        if (!str_contains($path, '/docs/')) continue;

        // Parse the file index to get class info
        $data = Toon::decode(file_get_contents($path));
        if (($data['spec'] ?? '') !== 'agent-core.file-index/v1') continue;

        $dir = dirname(dirname($path)); // go up from docs/ to the namespace dir
        $dir = realpath($dir);
        $namespaces[$dir][] = [
            'file' => basename($path, '.toon') . '.php',
            'class' => $data['class'] ?? '',
            'type' => $data['type'] ?? '',
            'summary' => $data['summary'] ?? '',
        ];
    }

    // For each namespace that has per-file indexes, regenerate ai-index.toon
    foreach ($namespaces as $dir => $entries) {
        $indexPath = $dir . '/ai-index.toon';
        $existingIndex = null;
        if (file_exists($indexPath)) {
            $existingIndex = Toon::decode(file_get_contents($indexPath));
        }

        // Determine namespace info from existing index or path
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

        // Build file entries with summaries
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
            'indexedAt' => $indexedAt,
            'indexedCommit' => $indexedCommit,
        ];
        if ($description) {
            $newIndex['description'] = $description;
        }
        $newIndex['files'] = $fileEntries;
        if ([] !== $subNamespaces) {
            $newIndex['subNamespaces'] = $subNamespaces;
        }
        $newIndex['sourceHash'] = hashNamespaceIndexSource($fileEntries, $subNamespaces);

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
    // Walk up from leaf namespaces and update parent ai-index.toon files
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

        // Refresh timestamps + index metadata
        $data = Toon::decode(file_get_contents($indexPath));
        $data['updatedAt'] = date('Y-m-d');
        $data['indexedAt'] = $indexedAt;
        $data['indexedCommit'] = $indexedCommit;

        /** @var list<array<string, mixed>> $files */
        $files = is_array($data['files'] ?? null) ? $data['files'] : [];
        /** @var list<array<string, mixed>> $subNamespaces */
        $subNamespaces = is_array($data['subNamespaces'] ?? null) ? $data['subNamespaces'] : [];
        $data['sourceHash'] = hashNamespaceIndexSource($files, $subNamespaces);

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
