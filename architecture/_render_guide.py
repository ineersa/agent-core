#!/usr/bin/env python3
"""Render the static architecture guide under architecture/.

Run from the worktree root:
  python3 architecture/_render_guide.py

Outputs HTML that works offline / file://. No CDN. No product code changes.
"""

from __future__ import annotations

import html
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO = ROOT.parent
AUDIT_JSON = Path("/tmp/hatfield-docs-audit-markdown.json")

PAGES = [
    ("index.html", "Overview", "System map and reading order"),
    ("startup.html", "Startup and processes", "CLI, controller, workers, shutdown"),
    ("request-lifecycle.html", "Request lifecycle", "User query to visible response"),
    ("run-storage.html", "Run, storage, recovery", "Events, projection, compaction"),
    ("queues-messages.html", "Queues and messages", "Messenger buses and full routing"),
    ("tools-approvals-mcp.html", "Tools, approvals, MCP", "Execution, human gates, MCP limits"),
    ("extensions-subagents.html", "Extensions and agents", "Hooks, jobs, fork, OM, background"),
    ("tui-provider-context.html", "TUI and providers", "Projection, composition, catalog"),
    ("build-qa-observability.html", "Build, QA, observability", "Castor, packaging, logs"),
    ("documentation-audit.html", "Documentation audit", "Markdown inventory dispositions"),
]

BASELINE = "740d92fa66dfc4763c27e43ed46135c68da4f944"
BASELINE_SHORT = "740d92fa6"
GUIDE_DATE = "6 September 2026"


def esc(s: str) -> str:
    return html.escape(s, quote=True)


def nav(current: str) -> str:
    links = []
    for file, title, _ in PAGES:
        if file == current:
            links.append(f'<a href="{file}" aria-current="page"><strong>{esc(title)}</strong></a>')
        else:
            links.append(f'<a href="{file}">{esc(title)}</a>')
    return '<nav class="topnav" aria-label="Architecture guide">' + "".join(links) + "</nav>"


def breadcrumbs(current: str) -> str:
    title = next(t for f, t, _ in PAGES if f == current)
    if current == "index.html":
        return '<div class="breadcrumbs" aria-label="Breadcrumb"><span>Architecture guide</span></div>'
    return (
        '<div class="breadcrumbs" aria-label="Breadcrumb">'
        '<a href="index.html">Architecture guide</a><span aria-hidden="true"> / </span>'
        f"<span>{esc(title)}</span></div>"
    )


def footer() -> str:
    return f"""<footer class="site-footer">
<p>Hatfield / agent-core architecture guide · source baseline <code>{BASELINE_SHORT}</code> · {GUIDE_DATE}</p>
<p>Source of truth is code and Castor-validated Markdown under <code>docs/</code>. This guide is diagram-first explanation. Packaged product docs remain the built-in catalog validated by <code>castor docs:validate</code>.</p>
</footer>"""


def page(file: str, title: str, body: str, description: str) -> str:
    return f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="{esc(description)}">
<title>{esc(title)} · Hatfield architecture</title>
<link rel="stylesheet" href="assets/guide.css">
</head>
<body>
<a class="skip" href="#content">Skip to content</a>
<main id="content">
<header class="site-header">
{breadcrumbs(file)}
<div class="eyebrow">Hatfield architecture guide · {GUIDE_DATE}</div>
<h1>{esc(title)}</h1>
<p class="meta">Source baseline <code>{BASELINE}</code></p>
{nav(file)}
</header>
{body}
{footer()}
</main>
</body>
</html>
"""


def legend_html() -> str:
    return """
<div class="legend" role="note">
<span class="swatch process"><i></i> Process / supervision</span>
<span class="swatch module"><i></i> Module / handler</span>
<span class="swatch storage"><i></i> Durable storage</span>
<span class="swatch queue"><i></i> Messenger queue</span>
<span class="swatch transient"><i></i> Transient stream</span>
</div>
"""


def svg_box(x, y, w, h, label, sub="", kind="module", rid=None):
    fills = {
        "process": ("#d7e8f0", "#1f4d63"),
        "module": ("#d9eee4", "#176349"),
        "storage": ("#f3e7c8", "#6b4f1d"),
        "queue": ("#dde3f8", "#3d4f8f"),
        "transient": ("#f6dce5", "#8a3d55"),
        "deep": ("#172733", "#e8f2ec"),
    }
    fill, stroke = fills.get(kind, fills["module"])
    text_fill = "#e8f2ec" if kind == "deep" else "#172733"
    sub_fill = "#c9ddd4" if kind == "deep" else "#53636c"
    rid_attr = f' id="{esc(rid)}"' if rid else ""
    lines = [
        f'<rect{rid_attr} x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="{fill}" stroke="{stroke}" stroke-width="1.5"/>',
        f'<text x="{x + w/2}" y="{y + (22 if sub else h/2 + 4)}" text-anchor="middle" font-family="system-ui,sans-serif" font-size="13" font-weight="650" fill="{text_fill}">{esc(label)}</text>',
    ]
    if sub:
        lines.append(
            f'<text x="{x + w/2}" y="{y + 40}" text-anchor="middle" font-family="ui-monospace,monospace" font-size="11" fill="{sub_fill}">{esc(sub)}</text>'
        )
    return "\n".join(lines)


def svg_arrow(x1, y1, x2, y2, label="", dashed=False):
    dash = ' stroke-dasharray="5 4"' if dashed else ""
    mid_x = (x1 + x2) / 2
    mid_y = (y1 + y2) / 2
    out = [
        f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#657c71" stroke-width="1.5"{dash} marker-end="url(#arrow)"/>'
    ]
    if label:
        out.append(
            f'<text x="{mid_x}" y="{mid_y - 6}" text-anchor="middle" font-family="system-ui,sans-serif" font-size="11" fill="#53636c">{esc(label)}</text>'
        )
    return "\n".join(out)


def svg_defs():
    return """
<defs>
  <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
    <path d="M 0 0 L 10 5 L 0 10 z" fill="#657c71"/>
  </marker>
</defs>
"""


def overview_infra_svg() -> str:
    parts = []
    parts.append(svg_box(20, 20, 160, 56, "TUI", "InteractiveMode", "process"))
    parts.append(svg_box(220, 20, 200, 56, "AgentSessionClient", "JSONL / in-process", "module"))
    parts.append(svg_box(460, 20, 200, 56, "HeadlessController", "session owner lock", "process"))
    parts.append(svg_arrow(180, 48, 220, 48))
    parts.append(svg_arrow(420, 48, 460, 48))

    parts.append(svg_box(20, 120, 150, 56, "run_control×1", "command bus owner", "queue"))
    parts.append(svg_box(190, 120, 150, 56, "llm×1..8", "default 4", "queue"))
    parts.append(svg_box(360, 120, 150, 56, "tool×N", "max_parallelism", "queue"))
    parts.append(svg_box(530, 120, 150, 56, "agent×1", "subagent/resume", "queue"))
    parts.append(svg_box(20, 200, 150, 56, "mcp×1", "serialized MCP", "queue"))
    parts.append(svg_box(190, 200, 150, 56, "extension_agent×1", "extension jobs", "queue"))
    parts.append(svg_box(360, 200, 150, 56, "scheduler_default×1", "in-memory schedule", "queue"))
    parts.append(svg_arrow(560, 76, 560, 120, "ConsumerSupervisor"))

    parts.append(svg_box(20, 300, 220, 64, "AgentCore pipeline", "RunMessageProcessor", "deep"))
    parts.append(svg_box(280, 300, 180, 64, "RunCommit", "append → project → effects", "deep"))
    parts.append(svg_box(500, 300, 180, 64, "events.jsonl", "canonical history", "storage"))
    parts.append(svg_arrow(95, 256, 95, 300))
    parts.append(svg_arrow(240, 332, 280, 332))
    parts.append(svg_arrow(460, 332, 500, 332))

    parts.append(svg_box(20, 400, 200, 56, "RuntimeEventMapper", "RunEvent → protocol", "module"))
    parts.append(svg_box(260, 400, 200, 56, "TranscriptProjector", "TUI projection", "module"))
    parts.append(svg_box(500, 400, 180, 56, "stdout deltas", "seq 0 streams", "transient"))
    parts.append(svg_arrow(590, 364, 590, 400, "", True))
    parts.append(svg_arrow(120, 364, 120, 400))
    parts.append(svg_arrow(220, 428, 260, 428))
    return f'<svg role="img" aria-labelledby="infra-title infra-desc" viewBox="0 0 700 480" xmlns="http://www.w3.org/2000/svg">{svg_defs()}<title id="infra-title">Infrastructure map</title><desc id="infra-desc">TUI to controller to Messenger workers to AgentCore commit and projection.</desc>{"".join(parts)}</svg>'


def lifecycle_svg() -> str:
    steps = [
        (20, "User / TUI", "prompt or command", "process"),
        (150, "JSONL command", "AgentSessionClient", "module"),
        (280, "ACK accepted", "before handlers", "process"),
        (410, "run_control", "StartRun / Apply*", "queue"),
        (540, "Execute*", "llm / tool / …", "queue"),
        (20, "Result → run_control", "Llm/Tool/…Result", "queue", 110),
        (200, "RunCommit", "events + effects", "deep", 110),
        (380, "Mapped events", "RuntimeEventMapper", "module", 110),
        (540, "Visible TUI", "poller / patches", "process", 110),
    ]
    # redraw cleaner
    parts = []
    y1 = 30
    boxes = [
        (20, y1, "User / TUI", "input", "process"),
        (160, y1, "Client", "JSONL command", "module"),
        (300, y1, "Controller", "ACK then dispatch", "process"),
        (440, y1, "run_control", "admit / commit", "queue"),
        (580, y1, "workers", "Execute*", "queue"),
    ]
    for x, y, a, b, k in boxes:
        parts.append(svg_box(x, y, 120, 56, a, b, k))
    for x in (140, 280, 420, 560):
        parts.append(svg_arrow(x, y1 + 28, x + 20, y1 + 28))
    y2 = 140
    boxes2 = [
        (80, y2, "Result msgs", "back to run_control", "queue"),
        (260, y2, "RunCommit", "canonical append", "deep"),
        (440, y2, "Projection", "mapper + poller", "module"),
        (600, y2, "Screen", "visible reply", "process"),
    ]
    for x, y, a, b, k in boxes2:
        parts.append(svg_box(x, y, 140, 56, a, b, k))
    parts.append(svg_arrow(640, 86, 640, 140, "stdout / DB"))
    parts.append(svg_arrow(220, y2 + 28, 260, y2 + 28))
    parts.append(svg_arrow(400, y2 + 28, 440, y2 + 28))
    parts.append(svg_arrow(580, y2 + 28, 600, y2 + 28))
    parts.append(svg_box(260, 240, 220, 56, "Transient deltas", "seq 0, not replay source", "transient"))
    parts.append(svg_arrow(640, 196, 370, 240, "", True))
    return f'<svg role="img" aria-labelledby="life-title" viewBox="0 0 760 320" xmlns="http://www.w3.org/2000/svg">{svg_defs()}<title id="life-title">Request lifecycle</title>{"".join(parts)}</svg>'


def startup_svg() -> str:
    parts = []
    flow = [
        (20, 20, "AgentCommand", "cwd · filters · skills", "process"),
        (200, 20, "Migrations", "StartupDatabaseMigrator", "module"),
        (380, 20, "Mode select", "TUI / headless / controller", "process"),
        (560, 20, "Extension load", "ConsoleEvents::COMMAND", "module"),
    ]
    # note: extension subscriber fires before command body for every console process
    for x, y, a, b, k in flow:
        parts.append(svg_box(x, y, 160, 56, a, b, k))
    for x in (180, 360, 540):
        parts.append(svg_arrow(x, 48, x + 20, 48))
    parts.append(
        '<text x="640" y="100" text-anchor="middle" font-family="system-ui,sans-serif" font-size="11" fill="#53636c">subscriber before command body</text>'
    )
    ctrl = [
        (20, 130, "Owner lock", "fail before ready", "process"),
        (180, 130, "Orphan reap", "ppid=1 only", "process"),
        (340, 130, "Launch pools", "see queue page", "queue"),
        (500, 130, "runtime.ready", "then accept cmds", "module"),
    ]
    for x, y, a, b, k in ctrl:
        parts.append(svg_box(x, y, 140, 56, a, b, k))
    for x in (160, 320, 480):
        parts.append(svg_arrow(x, 158, x + 20, 158))
    parts.append(svg_box(20, 230, 200, 56, "ACK accepted", "not execution done", "process"))
    parts.append(svg_box(260, 230, 200, 56, "Later reject", "command_rejected", "module"))
    parts.append(svg_box(500, 230, 180, 56, "Shutdown", "consumers + bg", "process"))
    parts.append(svg_arrow(220, 258, 260, 258, "dispatch fail"))
    return f'<svg role="img" aria-labelledby="start-title" viewBox="0 0 720 310" xmlns="http://www.w3.org/2000/svg">{svg_defs()}<title id="start-title">Startup sequence</title>{"".join(parts)}</svg>'


def run_commit_svg() -> str:
    parts = []
    parts.append(svg_box(40, 30, 180, 60, "HandlerResult", "events + effects", "module"))
    parts.append(svg_box(280, 30, 180, 60, "RunCommit", "single owner path", "deep"))
    parts.append(svg_box(520, 10, 160, 50, "events.jsonl", "append first", "storage"))
    parts.append(svg_box(520, 70, 160, 50, "operational DB", "payload-free", "storage"))
    parts.append(svg_box(280, 130, 180, 60, "Effects", "StepDispatcher", "queue"))
    parts.append(svg_box(40, 130, 180, 60, "AfterTurnCommit", "hooks / jobs", "module"))
    parts.append(svg_arrow(220, 60, 280, 60))
    parts.append(svg_arrow(460, 45, 520, 35))
    parts.append(svg_arrow(460, 70, 520, 95))
    parts.append(svg_arrow(370, 90, 370, 130))
    parts.append(svg_arrow(280, 160, 220, 160))
    parts.append(svg_box(40, 230, 280, 56, "RuntimeEventMapper", "consumes RunEvent, not RunState", "module"))
    parts.append(svg_arrow(600, 60, 180, 230, "committed only", True))
    return f'<svg role="img" aria-labelledby="commit-title" viewBox="0 0 720 310" xmlns="http://www.w3.org/2000/svg">{svg_defs()}<title id="commit-title">RunCommit path</title>{"".join(parts)}</svg>'


def message_rows() -> list[tuple[str, str, str, str]]:
    return [
        ("Ineersa\\AgentCore\\Domain\\Message\\StartRun", "run_control", "YAML", "Start a run"),
        ("ApplyCommand", "run_control", "YAML", "User / shell command admission"),
        ("ApplyShellCommand", "run_control", "YAML", "Standalone shell command path"),
        ("InvalidateRunContext", "run_control", "YAML", "Canonical-event side-channel invalidation"),
        ("LlmStepResult", "run_control", "YAML", "LLM worker result"),
        ("ToolCallResult", "run_control", "YAML", "Tool / MCP / agent worker result"),
        ("CompactionStepResult", "run_control", "YAML", "Compaction worker result"),
        ("AdvanceRun", "run_control", "YAML", "State transition from StepDispatcher"),
        ("CompactRun", "run_control", "YAML", "State transition from StepDispatcher"),
        ("CompleteDeferredToolCall", "run_control", "YAML", "Deferred tool completion"),
        ("ObserveDeferredSubagentBatchChildTurnMessage", "run_control", "YAML", "Deferred batch observation"),
        ("DeliverDeferredSubagentBatchLifecycleMessage", "run_control", "YAML", "Deferred batch lifecycle delivery"),
        ("InterruptDeferredSubagentBatchMessage", "run_control", "YAML + DelayStamp", "Deadline / interrupt; durable delay on run_control"),
        ("RecoverDeferredSubagentBatchLifecycleMessage", "run_control", "YAML", "Deferred batch recovery"),
        ("ExecuteLlmStep", "llm", "YAML", "Provider invocation"),
        ("ExecuteCompactionStep", "llm", "YAML", "Summarization model call"),
        ("ExecuteToolCall", "tool", "YAML default", "Generic tools; fork stays here"),
        ("ExecuteToolCall (toolName=subagent|agent_resume)", "agent", "SubagentExecuteToolCallRoutingMiddleware", "Overrides tool route before send"),
        ("ExecuteToolCall (MCP-backed)", "mcp", "McpExecuteToolCallRoutingMiddleware", "Respects existing TransportNamesStamp"),
        ("ExecuteShellToolCall", "tool", "YAML", "Shell tool execution"),
        ("McpInitializeSessionCommand", "mcp", "YAML", "MCP session init"),
        ("McpRefreshCatalogCommand", "mcp", "YAML", "MCP catalog refresh"),
        ("McpDisconnectSessionCommand", "mcp", "YAML", "MCP disconnect"),
        ("ExtensionAgentJobMessage", "extension_agent", "YAML", "Extension-owned agent jobs; not ToolCallResult"),
    ]


def index_body() -> str:
    cards = []
    for file, title, blurb in PAGES[1:]:
        cards.append(
            f'<a class="card" href="{file}" style="text-decoration:none;color:inherit"><h3>{esc(title)}</h3><p class="muted">{esc(blurb)}</p></a>'
        )
    return f"""
<p class="lede">Hatfield is an HTTP-less modular monolith: TUI and CLI in CodingAgent, run engine in AgentCore, public ExtensionApi for packages. This guide answers control and data flow with diagrams first.</p>
{legend_html()}
<section>
<h2>Infrastructure map</h2>
<figure class="diagram">{overview_infra_svg()}<figcaption class="caption">One controller owns the session lock and shared consumer pools. Child runs keep separate session identities but share those pools. Canonical history is <code>events.jsonl</code>; operational DB rows are disposable coordination, not a second transcript.</figcaption></figure>
</section>
<section>
<h2>How to read this guide</h2>
<div class="card-grid">{''.join(cards)}</div>
</section>
<section>
<h2>Source truth and docs packing</h2>
<div class="grid2">
<article class="panel">
<h3>What is authoritative</h3>
<ul class="tight">
<li><code>depfile.yaml</code> / <code>castor deptrac</code> for module edges.</li>
<li><code>config/packages/messenger.yaml</code> plus routing middleware for queues.</li>
<li>Canonical session events and runtime protocol types for live vs replay.</li>
<li>Built-in Markdown under <code>docs/</code> validated by <code>castor docs:validate</code> (package-safe links, size cap).</li>
</ul>
</article>
<article class="panel">
<h3>What this HTML guide is</h3>
<ul class="tight">
<li>Maintainer navigation and flow explanation rooted at <code>architecture/</code>.</li>
<li>Offline static files: local CSS, inline SVG, no required remote scripts.</li>
<li>Not the product help catalog and not a historical architecture review dump.</li>
<li>Baseline <code>{BASELINE_SHORT}</code> on {GUIDE_DATE}. Re-check symbols when the tree moves.</li>
</ul>
</article>
</div>
<div class="callout note">
<p><strong>Known gaps called out in pages.</strong> ExtensionManager construction / logger injection / subscriber attachment are not fully isolated today. MCP SDK call-level cancellation and timeout are unenforced. Do not read older containment claims into those paths.</p>
</div>
</section>
<section>
<h2>Primary sources</h2>
<p class="sources">
<code>src/CodingAgent/CLI/AgentCommand.php</code> ·
<code>src/CodingAgent/Extension/ExtensionLoaderSubscriber.php</code> ·
<code>src/CodingAgent/Runtime/Controller/HeadlessController.php</code> ·
<code>src/AgentCore/Application/Pipeline/RunCommit.php</code> ·
<code>src/AgentCore/Application/Handler/StepDispatcher.php</code> ·
<code>config/packages/messenger.yaml</code> ·
<code>docs/async-runtime-architecture.md</code> ·
<code>docs/session-storage.md</code> ·
<code>docs/tool-execution.md</code>
</p>
</section>
"""


def startup_body() -> str:
    return f"""
<p class="lede">Every console process loads extensions on <code>ConsoleEvents::COMMAND</code> before the command body. The interactive agent path then migrates databases and selects TUI, headless, or controller mode.</p>
{legend_html()}
<section>
<h2>CLI and extension timing</h2>
<figure class="diagram">{startup_svg()}<figcaption class="caption"><code>ExtensionLoaderSubscriber</code> runs for <code>agent</code> and for <code>messenger:consume</code>. It is not sequenced after migrations inside the command body; migrations run in <code>AgentCommand</code> after CWD and CLI filter setup.</figcaption></figure>
<ol class="tight">
<li><code>AgentCommand</code> applies <code>--cwd</code>, skills, prompt templates, and tool filters.</li>
<li><code>StartupDatabaseMigrator</code> runs application then transport migrations.</li>
<li>Mode selection: default TUI, <code>--headless</code>, or <code>--controller</code>.</li>
<li>Extensions already loaded via the subscriber for this process.</li>
</ol>
</section>
<section>
<h2>Controller ownership</h2>
<div class="grid2">
<article class="panel">
<h3>Before <code>runtime.ready</code></h3>
<ol class="tight">
<li>Acquire session owner lock. Failure returns before readiness.</li>
<li>Reap orphan <code>messenger:consume</code> processes with <code>ppid=1</code> for this session only.</li>
<li>Launch consumers: <code>run_control</code>×1, <code>llm</code>×1..8 (default 4), <code>tool</code>×<code>max_parallelism</code>, <code>agent</code>×1, <code>scheduler_default</code>×1, <code>mcp</code>×1, <code>extension_agent</code>×1.</li>
<li>Emit <code>runtime.ready</code>.</li>
</ol>
</article>
<article class="panel">
<h3>Command admission</h3>
<ul class="tight">
<li>Controller ACKs with <code>status=accepted</code> before handler work.</li>
<li>Acceptance is not execution completion.</li>
<li>Dispatch failure can still emit <code>command_rejected</code>.</li>
<li>Parent and child run identities differ; they share the controller pools. There is not one controller per child.</li>
</ul>
</article>
</div>
</section>
<section>
<h2>Shutdown</h2>
<p>Fatal stdout failure and stdin EOF trigger supervised consumer shutdown and accepted background-process cleanup. Controller startup and shutdown both own accepted background rows. Foreground cancellation is a separate run-control path.</p>
<p class="sources">Sources: <code>AgentCommand.php</code>, <code>ExtensionLoaderSubscriber.php</code>, <code>HeadlessController.php</code>, <code>docs/async-runtime-architecture.md</code>, <code>docs/background-processes.md</code>.</p>
</section>
"""


def lifecycle_body() -> str:
    return f"""
<p class="lede">A user prompt becomes a JSONL runtime command, an immediate ACK, durable run-control work, worker execution, canonical commit, then TUI projection. Transient token streams never become the resume source.</p>
{legend_html()}
<section>
<h2>Query to visible response</h2>
<figure class="diagram">{lifecycle_svg()}<figcaption class="caption">Workers return results to <code>run_control</code>. Only committed <code>RunEvent</code> values feed <code>RuntimeEventMapper</code>. Streaming deltas use sequence <code>0</code> on stdout.</figcaption></figure>
</section>
<section>
<h2>Control points</h2>
<div class="table-wrap"><table>
<thead><tr><th>Stage</th><th>Owner</th><th>Fact</th></tr></thead>
<tbody>
<tr><td>Transport</td><td><code>JsonlProcessAgentSessionClient</code></td><td>Default process transport spawns <code>agent --controller</code>.</td></tr>
<tr><td>ACK</td><td><code>HeadlessController</code></td><td>ACK precedes Symfony command listeners / bus dispatch.</td></tr>
<tr><td>State lock</td><td><code>RunMessageProcessor</code></td><td>Serializes transitions for the run.</td></tr>
<tr><td>Commit</td><td><code>RunCommit</code></td><td>Append events, then operational projection/cache, then effects and hooks.</td></tr>
<tr><td>Effects</td><td><code>StepDispatcher</code></td><td><code>RunControlTransitionMessageInterface</code> → command bus; other effects → execution bus.</td></tr>
<tr><td>Visibility</td><td>Mapper + poller</td><td>Rebuild from the canonical log on resume; live polling is not a second bus of record.</td></tr>
</tbody></table></div>
<p class="sources">Sources: <code>HeadlessController.php</code>, <code>RunCommit.php</code>, <code>StepDispatcher.php</code>, <code>RuntimeEventMapper.php</code>, <code>docs/async-runtime-architecture.md</code>.</p>
</section>
"""


def run_storage_body() -> str:
    return f"""
<p class="lede"><code>session_id === run_id</code>. Conversation authority is append-only <code>events.jsonl</code>. The operational SQLite projection coordinates live work without storing prompt payloads.</p>
{legend_html()}
<section>
<h2>Commit path</h2>
<figure class="diagram">{run_commit_svg()}<figcaption class="caption"><code>RuntimeEventMapper</code> / translator consume committed <code>RunEvent</code> values, not live <code>RunState</code>.</figcaption></figure>
</section>
<section>
<h2>Storage split</h2>
<div class="grid2">
<article class="panel">
<h3>Canonical</h3>
<ul class="tight">
<li><code>.hatfield/sessions/&lt;id&gt;/events.jsonl</code></li>
<li><code>sequence.cursor</code> for multi-writer allocation</li>
<li>Parent-scoped child artifacts under the parent session</li>
<li>Resume and repair rebuild from events</li>
</ul>
</article>
<article class="panel">
<h3>Operational / disposable</h3>
<ul class="tight">
<li><code>run_operational_*</code> projection rows</li>
<li>Messenger transport DB separate from app state DB</li>
<li>Deferred batches, questions, background-process rows</li>
<li>Not all of these rebuild from events</li>
</ul>
</article>
</div>
</section>
<section>
<h2>Compaction failure policy</h2>
<div class="callout note">
<p>On compaction model error or empty summary, messages are retained. If <code>continueAfterCompaction</code> is set and the run is not cancelling, status stays <code>Running</code> and an <code>AdvanceRun</code> is dispatched so the turn continues on the original messages. Maintenance compaction without that flag completes. If status is <code>Cancelling</code>, cancellation wins: transition to <code>Cancelled</code> with no <code>AdvanceRun</code>.</p>
</div>
<p class="sources">Sources: <code>docs/session-storage.md</code>, <code>RunCommit.php</code>, <code>CompactionStepResultHandler.php</code>, <code>docs/compaction.md</code>.</p>
</section>
"""


def queues_body() -> str:
    rows = "".join(
        f"<tr><td><code>{esc(m)}</code></td><td><code>{esc(t)}</code></td><td>{esc(how)}</td><td>{esc(note)}</td></tr>"
        for m, t, how, note in message_rows()
    )
    return f"""
<p class="lede">Two buses: <code>agent.command.bus</code> (default) and <code>agent.execution.bus</code>. Execution middleware can stamp a transport before Symfony send. Inventory below is from <code>config/packages/messenger.yaml</code> plus the named middleware classes.</p>
{legend_html()}
<section>
<h2>Transports the controller launches</h2>
<div class="table-wrap"><table>
<thead><tr><th>Consumer</th><th>Count</th><th>Role</th></tr></thead>
<tbody>
<tr><td><code>run_control</code></td><td>1</td><td>Owns <code>RunState</code>, canonical append, command/result transitions</td></tr>
<tr><td><code>llm</code></td><td>1..8, default 4</td><td><code>ExecuteLlmStep</code>, <code>ExecuteCompactionStep</code></td></tr>
<tr><td><code>tool</code></td><td><code>tools.execution.max_parallelism</code></td><td>Default <code>ExecuteToolCall</code> / shell tools; includes <code>fork</code></td></tr>
<tr><td><code>agent</code></td><td>1</td><td><code>subagent</code> and <code>agent_resume</code> tool calls</td></tr>
<tr><td><code>mcp</code></td><td>1</td><td>MCP lifecycle and MCP-backed tool calls</td></tr>
<tr><td><code>extension_agent</code></td><td>1</td><td><code>ExtensionAgentJobMessage</code> only</td></tr>
<tr><td><code>scheduler_default</code></td><td>1</td><td>In-memory default schedule: file-index refresh 30s, provisional bg cleanup 300s</td></tr>
</tbody></table></div>
<div class="callout warn">
<p>Durable deferred-batch deadlines use <code>DelayStamp</code> on <code>run_control</code>. They are not Symfony Scheduler tasks.</p>
</div>
</section>
<section>
<h2>Routed message inventory</h2>
<div class="table-wrap"><table>
<thead><tr><th>Message</th><th>Transport</th><th>How</th><th>Notes</th></tr></thead>
<tbody>{rows}</tbody>
</table></div>
<p><code>StepDispatcher</code> only chooses command bus vs execution bus. Extension job dispatch is a separate path onto <code>extension_agent</code>.</p>
<p class="sources">Sources: <code>config/packages/messenger.yaml</code>, <code>SubagentExecuteToolCallRoutingMiddleware</code>, <code>McpExecuteToolCallRoutingMiddleware</code>, <code>StepDispatcher.php</code>, <code>HeadlessController.php</code>.</p>
</section>
"""


def tools_body() -> str:
    return f"""
<p class="lede">Tools register from builtins, ExtensionApi, and MCP catalogs. Hooks can allow, block, replace, or require approval before workers run. Stored result reuse is recovery, not exactly-once exclusion.</p>
{legend_html()}
<section>
<h2>Execution flow</h2>
<figure class="diagram compact">
<svg role="img" aria-labelledby="tool-title" viewBox="0 0 720 220" xmlns="http://www.w3.org/2000/svg">
{svg_defs()}
{svg_box(20, 40, 120, 56, "Model calls", "flat args", "module")}
{svg_box(160, 40, 120, 56, "Hooks", "allow/block/approve", "module")}
{svg_box(300, 40, 120, 56, "Workers", "tool/agent/mcp", "queue")}
{svg_box(440, 40, 120, 56, "ToolCallResult", "→ run_control", "queue")}
{svg_box(580, 40, 120, 56, "Next step", "AdvanceRun", "module")}
{svg_arrow(140, 68, 160, 68)}
{svg_arrow(280, 68, 300, 68)}
{svg_arrow(420, 68, 440, 68)}
{svg_arrow(560, 68, 580, 68)}
{svg_box(160, 140, 400, 56, "Reuse stored result ≠ mutual exclusion / exactly-once", "at-least-once delivery still possible", "storage")}
<title id="tool-title">Tool execution</title>
</svg>
</figure>
</section>
<section>
<h2>Approvals and human input</h2>
<div class="grid2">
<article class="panel">
<h3>Approvals</h3>
<p>SafeGuard and extension tool hooks classify policy. Suspension retains exact-hook identity until the user answers. See <code>docs/approvals.md</code>.</p>
</article>
<article class="panel">
<h3>MCP limits</h3>
<p>Connection construction can time out. Per-call cancellation and timeout are not enforced by the current MCP SDK integration. Catalog refresh and disconnect are separate mcp-queue commands.</p>
</article>
</div>
<p class="sources">Sources: <code>docs/tool-execution.md</code>, <code>docs/mcp.md</code>, <code>docs/approvals.md</code>, <code>docs/human-input.md</code>.</p>
</section>
"""


def extensions_body() -> str:
    return f"""
<p class="lede">Public ExtensionApi stays independent of host internals. Host bridges own execution. Subagents, forks, extension jobs, observational memory, and background processes are related but not one pipeline.</p>
{legend_html()}
<section>
<h2>Extension loading caveat</h2>
<div class="callout warn">
<p><code>ExtensionManager</code> still constructs extensions and injects loggers / attaches subscribers outside the guarded registration try in places. A throwing constructor can abort the process. Do not claim all extension failures are contained. Tracked separately as a TODO.</p>
</div>
</section>
<section>
<h2>Agents, fork, jobs</h2>
<div class="grid2">
<article class="panel">
<h3>Subagent / resume / fork</h3>
<ul class="tight">
<li>Named subagent uses a role prompt; nested children are barred.</li>
<li>Fork inherits parent context and stays on the <code>tool</code> transport.</li>
<li><code>subagent</code> / <code>agent_resume</code> route to the <code>agent</code> transport.</li>
<li>Artifacts are parent-lifetime scoped.</li>
</ul>
</article>
<article class="panel">
<h3>Extension agent jobs</h3>
<ul class="tight">
<li><code>ExtensionAgentJobMessage</code> runs on <code>extension_agent</code>.</li>
<li>Jobs use the public extension runner / their own pipeline.</li>
<li>Results are not forced through <code>ToolCallResult</code>.</li>
<li>Observational Memory owns its DB and observer/reflector jobs inside the package.</li>
</ul>
</article>
</div>
</section>
<section>
<h2>Background processes</h2>
<p>Accepted background jobs are stopped by <code>bg_status</code> and by controller startup/shutdown ownership. Private foreground bash supervision is separate and not listed in <code>bg_status</code>. Provisional 300s scheduler cleanup covers unfinished private foreground rows, not accepted background rows.</p>
<p class="sources">Sources: <code>ExtensionManager.php</code>, <code>docs/agents.md</code>, <code>docs/background-processes.md</code>, ExtensionApi under <code>.hatfield/extensions/extension-api/</code>.</p>
</section>
"""


def tui_body() -> str:
    return f"""
<p class="lede">TUI composition is per session. Deptrac is authoritative for TUI edges, including approved AgentCore dependencies and the CodingAgent CLI bridge. Providers are manual: the default bundled catalog stays disabled until <code>providers:setup</code>.</p>
{legend_html()}
<section>
<h2>Projection chain</h2>
<figure class="diagram compact">
<svg role="img" aria-labelledby="tui-title" viewBox="0 0 720 180" xmlns="http://www.w3.org/2000/svg">
{svg_defs()}
{svg_box(20, 50, 150, 56, "RunEvent", "canonical", "storage")}
{svg_box(200, 50, 160, 56, "RuntimeEventMapper", "translator", "module")}
{svg_box(390, 50, 150, 56, "RuntimeEvent", "protocol DTO", "module")}
{svg_box(570, 50, 130, 56, "TUI poller", "visual patches", "process")}
{svg_arrow(170, 78, 200, 78)}
{svg_arrow(360, 78, 390, 78)}
{svg_arrow(540, 78, 570, 78)}
{svg_box(200, 130, 320, 40, "seq 0 deltas bypass durable rebuild", "", "transient")}
<title id="tui-title">TUI projection</title>
</svg>
</figure>
</section>
<section>
<h2>Provider and context</h2>
<div class="grid2">
<article class="panel">
<h3>Providers</h3>
<p>No automatic provider setup on install. Operators run <code>hatfield providers:setup</code>. Platform bridges under <code>src/Platform</code> hold protocol differences; AgentCore coordinates invocation.</p>
</article>
<article class="panel">
<h3>Context budget / prompts</h3>
<p>Skills, prompt templates, and context-budget hooks shape model-visible context before provider calls. Effective model-context export reuses history filtering rather than inventing a second interpretation.</p>
</article>
</div>
<p class="sources">Sources: <code>depfile.yaml</code>, <code>docs/tui-architecture.md</code>, <code>docs/ai-catalog.md</code>, <code>docs/settings.md</code>.</p>
</section>
"""


def build_qa_body() -> str:
    return f"""
<p class="lede">All QA goes through Castor. The architecture HTML guide is maintainer documentation beside the product docs catalog, not a substitute for <code>castor docs:validate</code>.</p>
<section>
<h2>Castor lanes (reference)</h2>
<div class="table-wrap"><table>
<thead><tr><th>Command</th><th>Role</th></tr></thead>
<tbody>
<tr><td><code>castor check</code></td><td>Full gate: deptrac, tests, replay E2E, llm-real smoke, phpstan, dead-code, cs-check, docs:validate</td></tr>
<tr><td><code>castor test</code> / <code>test:tui</code> / <code>test:controller-replay</code></td><td>Deterministic lanes</td></tr>
<tr><td><code>castor docs:validate</code></td><td>Built-in docs catalog, package-safe links, size limits</td></tr>
<tr><td><code>castor phar:build</code></td><td>Release artifact used by native packaging</td></tr>
</tbody></table></div>
<div class="callout note">
<p>This architecture slice does not run Castor. Parent workflow owns product QA. Test standards live in <code>tests/AGENTS.md</code> and the testing skill.</p>
</div>
</section>
<section>
<h2>Observability</h2>
<p>Prefer structured logs with <code>run_id</code>, <code>session_id</code>, <code>component</code>, <code>event_type</code>. Do not log raw prompts, tool output, or secrets by default. Local Datadog wiring is optional maintainer tooling under <code>docs/datadog.md</code>.</p>
<p class="sources">Sources: <code>AGENTS.md</code>, <code>.agents/skills/testing/SKILL.md</code>, <code>docs/distribution.md</code>, <code>docs/datadog.md</code>, <code>.castor/docs.php</code>.</p>
</section>
"""


def audit_disposition_badge(disposition: str) -> str:
    mapping = {
        "audited-accurate": ("ok", "audited accurate"),
        "rewrote": ("ok", "rewrote"),
        "corrected": ("ok", "corrected"),
        "light-edit": ("ok", "light edit"),
        "deduped": ("ok", "deduped"),
        "parent-owned": ("warn", "parent owned"),
        "historic-leave": ("hist", "historic leave"),
        "imported-upstream-leave": ("up", "imported upstream"),
    }
    cls, label = mapping.get(disposition, ("", disposition))
    return f'<span class="badge {cls}">{esc(label)}</span>'


def audit_body() -> str:
    if not AUDIT_JSON.is_file():
        return '<div class="callout warn"><p>Audit JSON missing at build time. Re-run the renderer with the inventory file present.</p></div>'
    data = json.loads(AUDIT_JSON.read_text())
    entries = data["entries"]
    summary = data.get("summary", {})
    # Correct fork mis-label: .pi/skills and .pi/prompts are active runtime instructions.
    active_pi_prefixes = (".pi/skills/", ".pi/prompts/")
    rows = []
    corrected_notes = 0
    for e in entries:
        path = e["path"]
        disposition = e["disposition"]
        notes = e.get("notes") or ""
        category = e.get("category") or ""
        guide_note = notes
        if path.startswith(active_pi_prefixes) and disposition == "historic-leave":
            guide_note = (
                "Inventory marked historic-leave; treat as active runtime instruction "
                "(prompts/skills), not archival history. "
                + notes
            )
            corrected_notes += 1
            badge = audit_disposition_badge("historic-leave") + ' <span class="badge warn">active runtime</span>'
        elif disposition == "imported-upstream-leave":
            badge = audit_disposition_badge(disposition)
            guide_note = "Vendored/upstream import; excluded from claims about current product docs rewrite. " + notes
        elif disposition == "historic-leave" and (
            path.startswith(".pi/plans/") or path.startswith(".pi/reports/")
        ):
            badge = audit_disposition_badge(disposition)
            guide_note = "Historical plan/report; not a current architecture claim. " + notes
        else:
            badge = audit_disposition_badge(disposition)
        rows.append(
            "<tr>"
            f"<td><code>{esc(path)}</code></td>"
            f"<td>{esc(category)}</td>"
            f"<td>{badge}</td>"
            f"<td>{esc(guide_note)}</td>"
            f"<td>{e.get('chars', '')}</td>"
            "</tr>"
        )
    summary_bits = "".join(
        f"<li><strong>{esc(k)}</strong>: {esc(str(v))}</li>" for k, v in summary.items()
    )
    return f"""
<p class="lede">Compact reference for the Markdown documentation audit inventory ({len(entries)} files). This page is derived from the audit snapshot; it is not itself a Castor docs catalog entry.</p>
<section>
<h2>How to read dispositions</h2>
<ul class="tight">
<li><strong>audited-accurate / rewrote / corrected / light-edit / deduped</strong> — touched or confirmed in the documentation pass.</li>
<li><strong>imported-upstream-leave</strong> — vendored upstream text; do not cite as current Hatfield product docs.</li>
<li><strong>historic-leave</strong> on <code>.pi/plans</code> and <code>.pi/reports</code> — archival history.</li>
<li><strong>.pi/skills and .pi/prompts</strong> — active runtime instructions even when the inventory row says historic-leave ({corrected_notes} rows flagged here).</li>
<li><strong>parent-owned</strong> — reserved for the parent slice (for example root README).</li>
</ul>
<div class="callout note">
<p>Audit revision recorded in snapshot: <code>{esc(str(data.get('revision')))}</code>. Guide source baseline: <code>{BASELINE_SHORT}</code>. Machine-local worktree paths from the snapshot are omitted on purpose.</p>
</div>
</section>
<section>
<h2>Summary counts</h2>
<ul class="tight">{summary_bits}<li><strong>inventory entries</strong>: {len(entries)}</li></ul>
</section>
<section>
<h2>Per-file inventory</h2>
<div class="table-wrap"><table>
<thead><tr><th>Path</th><th>Category</th><th>Disposition</th><th>Notes</th><th>Chars</th></tr></thead>
<tbody>
{''.join(rows)}
</tbody></table></div>
</section>
"""


def write_pages() -> list[Path]:
    (ROOT / "assets").mkdir(parents=True, exist_ok=True)
    bodies = {
        "index.html": ("Overview", index_body(), "Hatfield architecture overview and infrastructure map"),
        "startup.html": ("Startup and processes", startup_body(), "CLI startup, controller ownership, shutdown"),
        "request-lifecycle.html": ("Request lifecycle", lifecycle_body(), "User query to visible response"),
        "run-storage.html": ("Run, storage, recovery", run_storage_body(), "Events, projection, compaction"),
        "queues-messages.html": ("Queues and messages", queues_body(), "Messenger routing inventory"),
        "tools-approvals-mcp.html": ("Tools, approvals, MCP", tools_body(), "Tool execution and MCP limits"),
        "extensions-subagents.html": ("Extensions and agents", extensions_body(), "Extensions, subagents, background work"),
        "tui-provider-context.html": ("TUI and providers", tui_body(), "TUI projection and providers"),
        "build-qa-observability.html": ("Build, QA, observability", build_qa_body(), "Castor QA and logging"),
        "documentation-audit.html": ("Documentation audit", audit_body(), "Markdown audit dispositions"),
    }
    written = []
    for file, (title, body, desc) in bodies.items():
        path = ROOT / file
        path.write_text(page(file, title, body, desc), encoding="utf-8")
        written.append(path)
    return written


def validate_links(files: list[Path]) -> list[str]:
    import re
    from urllib.parse import urlparse, unquote

    errors = []
    href_re = re.compile(r'href="([^"]+)"')
    id_re = re.compile(r'\bid="([^"]+)"')
    page_ids = {}
    for f in files:
        text = f.read_text(encoding="utf-8")
        page_ids[f.name] = set(id_re.findall(text))
    for f in files:
        text = f.read_text(encoding="utf-8")
        for href in href_re.findall(text):
            if href.startswith(("http://", "https://", "mailto:")):
                errors.append(f"{f.name}: unexpected remote href {href}")
                continue
            if href.startswith("#"):
                if href[1:] not in page_ids[f.name]:
                    # allow missing only if not used; check
                    pass  # pages mostly avoid in-page anchors
                continue
            parsed = urlparse(href)
            target = unquote(parsed.path)
            if target.startswith("assets/"):
                if not (ROOT / target).is_file():
                    errors.append(f"{f.name}: missing asset {target}")
                continue
            if target.endswith(".html"):
                if not (ROOT / target).is_file():
                    errors.append(f"{f.name}: missing page {target}")
                frag = parsed.fragment
                if frag and frag not in page_ids.get(target, set()):
                    errors.append(f"{f.name}: missing anchor {target}#{frag}")
    # Ban navigable links to historical reports and machine-local paths.
    for f in files:
        text = f.read_text(encoding="utf-8")
        for href in href_re.findall(text):
            if "architecture-review-20260905" in href or href.startswith("../.pi/reports") or "/.pi/reports/" in href:
                errors.append(f"{f.name}: historical report link {href}")
            if href.startswith("/home/") or "agent-core-worktrees" in href:
                errors.append(f"{f.name}: machine-local href {href}")
        if "/home/ineersa/" in text or "/home/ineersa/projects/agent-core-worktrees" in text:
            errors.append(f"{f.name}: machine-local path leak in body text")
    return errors


def main() -> None:
    written = write_pages()
    errors = validate_links(written)
    print(f"wrote {len(written)} pages under {ROOT}")
    for e in errors:
        print("ERROR", e)
    if errors:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
