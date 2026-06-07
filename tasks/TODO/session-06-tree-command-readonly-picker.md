# SESSION-06 /tree read-only turn tree picker

## Goal
Add the first `/tree` TUI command as a read-only visual picker for the current session's turn tree.

## Desired UX
- `/tree` opens a tree/list overlay near the bottom/editor area.
- It shows turns in the current session, the current leaf/head, branch structure, and useful labels/previews.
- Navigation keys follow existing picker/list patterns.
- This task is read-only: selecting a turn may close the picker or show details, but must not yet change execution state.

## Current code facts

### Reference patterns
- `src/Tui/Picker/ModelPickerController.php` — uses `SelectListWidget` with items array, keybindings, `onSelect`/`onCancel`
- `src/Tui/Picker/FavoritePickerController.php` — additional pattern: multi-select with Space toggle
- `SelectListWidget` — supports `maxVisible` (default 10), keybindings, `onSelect(SelectEvent)`, `onCancel(CancelEvent)`, `onInput(string)` raw handler
- `PickerOverlay` — simple mount/close lifecycle via tui->add/remove(container)

### SelectListWidget keybindings
```php
$kb = new Keybindings([
    'select_up' => [Key::UP],
    'select_down' => [Key::DOWN],
    'select_page_up' => [Key::PAGE_UP],
    'select_page_down' => [Key::PAGE_DOWN],
    'select_confirm' => [Key::ENTER],
    'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
]);
```

### Items shape for SelectListWidget
```php
// Each item has label, value, and optional description:
new SelectListItem(
    label: '└─ Turn 3: Follow-up about routing',
    value: 'turn-3',
    description: '2026-06-07 20:45',  // optional, shown as subtitle
);
```

## Data source
- After SESSION-05: `TurnTreeView` service returns tree data
- Tree shape from read model:
```php
class TurnNode {
    public function __construct(
        public int $turnNo,
        public ?int $parentTurnNo,
        public string $title,       // prompt preview / assistant summary
        public string $role,        // 'user' | 'assistant' | 'system'
        public string $timestamp,
        public bool $isLeaf,        // current active position
        public array $children = [], // list<TurnNode>
    ) {}
}
```

## Rendering tree structure in SelectListWidget
The standard `SelectListWidget` renders a flat list, not a true tree. Two approaches:

### A) Flattened indented list (simpler, recommended for first pass)
Walk the tree depth-first, produce flat items with indentation prefixes:
```
◉ Turn 1: Initial prompt "Create route..."  ← leaf marker
  └─ Turn 2: Model response
    └─ Turn 3: User follow-up
      ○ Turn 4: Model response (abandoned)
```
Use `$prefix` strings: `''` for root, `'  '` for children, `'└─ '` for leaf entries.

### B) Expandable/collapsible tree (future enhancement)
Requires custom widget beyond `SelectListWidget`. Not recommended for first pass.

### C) Leaf marker
- Use `◉` for current leaf item, `○` for non-leaf items in the label prefix.

### Indentation calculation
```php
function itemForNode(TurnNode $node, int $depth = 0, bool $isLeaf = false): SelectListItem {
    $indent = str_repeat('  ', max(0, $depth - 1)) . ($depth > 0 ? '└─ ' : '');
    $prefix = $node->isLeaf ? '◉ ' : '○ ';
    $label = $indent . $prefix . $node->title;
    return new SelectListItem(label: $label, value: (string)$node->turnNo);
}

function flattenTree(TurnNode $root, int $depth = 0): array {
    $items = [itemForNode($root, $depth)];
    foreach ($root->children as $child) {
        $items = array_merge($items, flattenTree($child, $depth + 1));
    }
    return $items;
}
```

## Implementation seams

### New file: `src/Tui/Command/TreeCommand.php`
```php
class TreeCommand implements SlashCommandHandler {
    public function __construct(
        private TurnTreeView $treeView,
        private TuiSessionState $state,
        private PickerOverlay $overlay,
    ) {}

    public function handle(SlashCommand $cmd): CommandResult {
        $tree = $this->treeView->buildFromEvents($this->state->sessionId);
        $items = $this->flattenTree($tree->root);

        $this->overlay->mount(
            new HeaderWidget('Session Turn Tree'),
            new SelectListWidget(
                items: $items,
                onSelect: function (SelectEvent $e) {
                    // Read-only: just close or show details
                    $this->overlay->close();
                    // Option: append status message showing selected turn
                },
                onCancel: fn() => $this->overlay->close(),
            ),
        );

        return new NoOp();
    }
}
```

### Registration
```php
$registry->register(
    new CommandMetadata(name: 'tree', description: 'Show session turn tree (read-only)'),
    new TreeCommand($treeView, $state, $overlay),
);
```

## Known pitfalls
- Tree data must be rebuilt from canonical `events.jsonl` each time `/tree` is opened, not cached across invocations (session may have advanced).
- Long turn titles must be truncated to avoid overflowing the picker width. Use `mb_strimwidth()`.
- The flattened list may be long for deep sessions. `SelectListWidget::maxVisible` controls visible rows; scrolling is handled by the widget.
- If the picker is read-only, confirm `onSelect` does NOT trigger any session switch or state mutation. Close picker or show a brief status message.
- Current session picker doesn't auto-refresh if the session advances while the picker is open. Not a problem for read-only v1.
- No backward-compatibility for old sessions without tree events; they show a single linear line.
- TUI changes require full `castor check` before CODE-REVIEW.

## Dependencies
- SESSION-05 turn tree read model.
- SESSION-03/04 picker patterns may be reused but are not conceptually required.

## Out of scope
- Rewinding/branching/continuing from a selected turn.
- Branch summaries/compaction.
- Cross-session tree/fork extraction.

## Acceptance criteria
- `/tree` is registered with help/usage metadata.
- `/tree` builds its data from the canonical turn tree read model for the current session, not from transient TUI transcript state alone.
- The picker displays enough information to distinguish turns: turn/anchor id, role or kind, prompt/assistant preview where safe, branch depth/indentation, timestamp if available, and current leaf marker.
- Keyboard navigation and cancel behavior match existing `SelectListWidget`/picker conventions.
- Opening/closing the tree picker does not alter run state, transcript, current leaf, or editor text.
- Tests cover tree command registration, rendering data construction for linear and branched histories, and cancel/close behavior.
- Docs/help text document `/tree` as read-only in this phase.
- Validation uses Castor per project rules; TUI changes require full `castor check` before CODE-REVIEW.

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-07T20:46:14.207Z
