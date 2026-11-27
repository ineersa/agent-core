# TUI

- Mount chrome in `ChatScreen` as Symfony TUI widgets: project widgets built on Symfony's `AbstractWidget`/`ContainerWidget`, or Symfony's own widgets.
- Keep status text passed to `ChatScreen::setStatus`/`setTransientStatus` (pushed to `StatusPanelWidget::setEntries`) plain; `StatusPanelWidget` selects styling by status key.
- Use owning CodingAgent services directly where Deptrac allows them.
- Use public ExtensionApi TUI contracts, never concrete extension implementations.
