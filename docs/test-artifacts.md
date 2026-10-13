# Test artifacts and QA reports

## Ownership and cleanup

`TestDirectoryIsolation` registers each project or OS temporary directory before creation. `.castor/TestArtifactLifecycle.php` records the exact paths, project root, process ID, available Linux process start identity, QA run ID and a random ownership token in `var/tmp/artifact-owners/<token>.json`. Child processes inherit `HATFIELD_TEST_ARTIFACT_OWNER`.

Keep ordinary fixture cleanup in `tearDown` or `finally`. The PHPUnit `TestArtifactCleanupExtension` supplies a run-end cleanup after class teardown, so shared class kernels retain their fixtures until consumers finish. PHP shutdown supplies the same fallback for exceptions, failed runs and exits that reach shutdown. The Castor parent also recovers stopped workers at its own completion. SIGKILL cannot run callbacks; the next PHPUnit bootstrap, QA initialization or `castor clean:cleanup` recovers eligible leftovers.

Recovery requires a valid ownership record, a stopped original producer, a released owner lease and no surviving consumer ownership token or process reference to the paths. A process ID alone is insufficient because the kernel can reuse it. Zombies are stopped producers, not active consumers. Symfony flock lease descriptors are inherited by ordinary subprocesses, so an opaque exec transition does not erase their ownership. Consumers that deliberately discard inherited descriptors must retain the ownership token or scoped path references until they finish. Cleanup never signals processes.

Detached tmux panes cannot inherit the producer's lease and may live outside its `/proc` namespace. `TmuxHarness` records detached ownership before spawning. It installs the token and QA run ID in the session's creation-time environment, then tags session options through the existing QA tmux facility. Recovery inventories both sources through tmux itself. The environment protects the interval before option tagging. A surviving detached worker also protects its QA parent's staging, caches and reports. Inspection failures retain resources and log intentional degradation.

Normal fixture allocation and completion do not require Linux process identities. If an abandoned producer has no recorded Linux identity, recovery retains its resources because its termination is uncertain. The owner lease still protects inherited consumers during normal completion. A completed report remains eligible for bounded retention on these platforms.

One permanent Symfony flock inode serializes registration, recovery and report pruning within a checkout. Do not unlink that coordinator file under `var/tmp/artifact-owners/`. Each producer also has a lease directory named by its ownership token. Recovery removes that directory only under the coordinator after acquiring its lease; inherited descriptors still holding the old inode prevent acquisition. Normal completion closes the producer's lease descriptor without explicitly unlocking descriptors inherited by children. Separate worktrees have separate ownership records and coordination locks. Unmarked paths and records naming a different project root remain untouched.

Explicit fixture teardown forgets conclusively removed paths in the producer record. Pending registrations remain recorded until creation or finalization. Successful finalization clears the producer's reclaimed path list before its next allocation, so repeated lifetimes do not grow ownership records with already removed fixtures.

After finalization, allocation fails immediately if an inherited consumer still holds the previous lease. It does not wait under the checkout coordinator and block other producers. Finish the owned consumer before starting another fixture lifetime.

Removal uses Symfony Filesystem. It normalizes directory permissions before descending, skips symlink targets, and rejects parent paths that escape through symlinks. Deletion errors propagate during explicit cleanup. PHP shutdown logs sanitized cleanup failures and retains the record for recovery.

## Producers and lifetimes

| Family | Producer and purpose | Accumulation cause and current lifetime |
|---|---|---|
| `var/tmp/mcp-config-*` | `TestMcpConfigLoaderFactory::loaderForServers()` writes MCP configuration for a real test loader. | The helper returned only the loader and never transferred directory cleanup to callers. Automatic fixture ownership now removes these roots at run completion. |
| `var/tmp/tw-code-*` | `TaskBoardStoreTest` creates an isolated code root beside its external board fixture. | The code root was a local variable, while teardown removed only the board. Teardown now owns both; run-end ownership also covers aborted setup. |
| `var/tmp/tw-git-*`, `tw-copy-git-*`, `tw-worktrees-*` | `MoveTaskHandlerTest` and `WorktreeManagerCopyTest` exercise Git and worktree operations. | Ordinary teardown removes them. The worktree base previously sat outside the registered repository tree; it now uses `TestDirectoryIsolation` too. Registration covers failed setup and interrupted teardown. |
| `var/tmp/hatfield-*` | `IsolatedKernelTestCase`, `PerMethodIsolatedKernelTestCase` and provider or command fixtures allocate isolated runtime projects and HOME trees. | Ordinary teardown owns these trees, but aborts could bypass it. Automatic ownership supplies run-end cleanup and crash recovery. Real session archives are not candidates. |
| `var/tmp/tui-*` | TUI render fixtures and tmux tests use isolated projects. `TmuxHarness::saveAnsiSnapshot()` records ANSI proof. | Historical test guidance retained snapshots without a bound. Current fixture teardown remains primary; snapshots are archived in bounded QA reports before temporary trees disappear. Unmarked historical roots cannot establish an individual owner's lifecycle. |
| `var/tmp/sg-*` | SafeGuard controller approval tests write outside the isolated CWD. | A sibling file is not inside the fixture tree. `ControllerReplaySafeGuardApprovalTest` explicitly registers the outside-CWD file, in addition to its ordinary unlink. Historical files have no ownership records. |
| `.hatfield/cache-*` | `initialize_qa_check_run()`, `qa_standalone_test_cache_dir()` and `tests/paratest-bootstrap.php` allocate Symfony caches. | Exact QA-run cache cleanup already ran after leak checks, but standalone runs and early aborts lacked completion ownership. Those allocations now register before creation. Worker and standalone names include random identity to avoid PID reuse collisions. Explicit caller-supplied cache directories remain caller-owned. Persistent `.hatfield/cache` is not reclaimed. |
| `var/tmp/qa-*`, `var/tmp/qa-home/*` | Castor isolates QA staging and HOME for migrations and test workers. | These allocations lacked a completion cleanup. They now share the producer's registered lifetime, with surviving consumers protected. An empty `qa-home` parent can remain. |
| `var/reports/qa-*` | Full Castor QA runs keep lane logs and JUnit evidence. Requested fixture archives use `qa-test-*` report roots. | Reports previously had no pruning. The policy below applies to recorded stopped runs, including requested diagnostic archives. |

`HATFIELD_KEEP_TMP` preserves controller replay diagnostics by copying the stopped fixture into its QA report. It no longer keeps the working temporary tree indefinitely. Standalone requested diagnostics receive a recorded `qa-test-*` report. Passing ANSI snapshots use the same bounded report lifetime.

Build outputs and caches under `var/tmp/phar`, `phar-build`, `dist`, `static-build` and `static-php-cli` have different owners. PHAR session copies can serve active sessions. `clean:cleanup` no longer recursively removes these unmarked resources or unlinks build locks. Ad-hoc investigation directories, raw LLM captures and unrelated user files also remain untouched. Runtime background artifacts, controller locks, batch snapshots and launch-input recovery have separate lifecycles.

## Report retention

Keep the latest **10 completed QA runs**, regardless of result. There are no success/failure tiers or additional retention settings. Report ownership is recorded in `.artifact-owner.json` inside each generated report directory.

A completion callback marks the report after consumers finish. A dead recorded producer with no surviving consumers also establishes an abandoned run as stopped, even when a crash prevented that callback. Normal completions use the recorded completion time for ordering. Stopped crash leftovers without that timestamp use the last ownership-marker update time for ordering, never for deletion eligibility. Active reports, unmarked historical reports and uncertain ownership remain protected and do not count against the completed-run bound. Current-run finalization and explicit recovery enforce the same policy under the project lock.

The exact-run cache finalizer still follows the QA process-leak assertion. Automatic cleanup does not kill leaked workers or delete their diagnostics. Explicit custom report locations remain caller-owned; standalone fixed-name report files outside `qa-*` do not accumulate one directory per run.

## Recover stopped resources

Do not delete controller or artifact coordination lock files. Do not use a prefix or file age as proof that a resource is unused.

1. Run `castor clean:cleanup` in the owning checkout.
2. Read the reclaimed count. The command retains active, unknown and unmarked resources.
3. Investigate retained resources through their producer and consumer lifecycle. Use `castor clean:cleanup:workers:list` for QA worker diagnosis. Never signal root-owned workers or `HATFIELD_SESSION_ID` processes.
4. Repeat the inventory below to record the change.

The integration inventory on 2026-10-13 found 31 `cache-*` directories, 10,843 MCP fixture directories, 2,895 TUI entries using about 17.1 GiB, 1,868 code-root fixtures, 348 Git fixtures, 276 SafeGuard files and 364 QA reports using about 585 MiB. These historical artifacts predate ownership recording. This cleanup command deliberately does not adopt them from their names or ages. Backlog reclamation requires separate, source-verified lifecycle evidence; unknown ad-hoc investigations and required diagnostics must remain intact.

Run this metadata-only inventory from the checkout. It follows no directory symlinks and reads no artifact contents:

```bash
python3 - <<'PY'
from collections import defaultdict
from pathlib import Path
import os, re, time
for relative in ['.hatfield', 'var/tmp', 'var/reports', '.hatfield/tmp/controller-locks', '.hatfield/tmp/bg']:
	root = Path(relative)
	groups = defaultdict(lambda: [0, 0, None, None])
	if not root.is_dir():
		continue
	for entry in os.scandir(root):
		if relative == '.hatfield' and not entry.name.startswith('cache'):
			continue
		if relative == 'var/reports' and not entry.name.startswith('qa-'):
			continue
		family = next((p for p in ['mcp-config-', 'hatfield-', 'tui-', 'tw-code-', 'tw-git-', 'sg-', 'qa-', 'cache-'] if entry.name.startswith(p)), re.sub(r'[-_][0-9a-f]{6,}.*$', '-*', entry.name))
		if relative.endswith('controller-locks'):
			family = 'controller-locks'
		elif relative.endswith('/bg'):
			family = Path(entry.name).suffix or 'no suffix'
		stat = entry.stat(follow_symlinks=False)
		bytes_used = stat.st_size
		if entry.is_dir(follow_symlinks=False):
			bytes_used = 0
			for base, directories, files in os.walk(entry.path, followlinks=False):
				for name in files:
					try:
						bytes_used += os.stat(os.path.join(base, name), follow_symlinks=False).st_size
					except FileNotFoundError:
						print('Concurrent removal during inventory; size totals are approximate.')
		group = groups[family]
		group[0] += 1
		group[1] += bytes_used
		group[2] = min(group[2] or stat.st_mtime, stat.st_mtime)
		group[3] = max(group[3] or stat.st_mtime, stat.st_mtime)
	print(relative)
	for family, group in sorted(groups.items()):
		print(family, 'count=', group[0], 'MiB=', round(group[1] / 1048576, 2), 'oldest=', time.strftime('%Y-%m-%d', time.localtime(group[2])), 'newest=', time.strftime('%Y-%m-%d', time.localtime(group[3])))
PY
```
