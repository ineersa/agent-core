# Hatfield task-workflow extension

Native Hatfield port of the pi `task-workflow` extension. It registers task board tools, slash commands, and system prompt guidance for the external task workflow.

`ExtensionApi` types (`Ineersa\Hatfield\ExtensionApi\*`) are provided by the host application at runtime and are not duplicated in this package.

## Runtime loading

`ExtensionManager` requires `.hatfield/extensions/vendor/autoload.php`. After cloning or updating this package, run:

```bash
composer install
```

from the `.hatfield/extensions/` directory (path repository root).
