<?php

declare(strict_types=1);

use function Castor\import;

// ── Load order reflects cross-file dependencies ─────────────────
// Each file defines global functions and/or #[AsTask] entries.
// Files imported earlier make their functions available to files
// imported later (PHP require semantics).
//
// Dependency order:
//   helpers.php   – CastorTasks namespace (no tasks, base layer)
//   shared.php    – widely-used global helpers (fail_quality, etc.)
//   process.php   – process management (has tasks)
//   phpunit.php   – PHPUnit tasks and shard config (has tasks)
//   tasks.php     – QA orchestration / check (has tasks)
//   e2e.php       – E2E tasks (has tasks)
//   phar.php      – PHAR tasks (has tasks)
//   tools.php     – static analysis + style tasks (has tasks)
//   run.php       – agent runtime launchers (has tasks)
//   cleanup.php   – artifact cleanup (has tasks)
//   env.php       – diagnostics + Datadog tasks (has tasks)
//   logs.php      – log management tasks (has tasks)

import(__DIR__.'/.castor/helpers.php');
import(__DIR__.'/.castor/shared.php');
import(__DIR__.'/.castor/process.php');
import(__DIR__.'/.castor/phpunit.php');
import(__DIR__.'/.castor/tasks.php');
import(__DIR__.'/.castor/e2e.php');
import(__DIR__.'/.castor/phar.php');
import(__DIR__.'/.castor/tools.php');
import(__DIR__.'/.castor/run.php');
import(__DIR__.'/.castor/cleanup.php');
import(__DIR__.'/.castor/env.php');
import(__DIR__.'/.castor/logs.php');
import(__DIR__.'/.castor/llm-replay.php');
import(__DIR__.'/.castor/pi-task-workflow.php');
