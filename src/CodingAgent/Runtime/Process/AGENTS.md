# Process runtime

- Obtain subprocess argv from `RuntimeProcessConfig::executableCommand()` and spread the result.
- Preserve source, PHAR, and fused-native executable resolution through the existing locators.
- Use non-empty `%app.cwd%` for runtime CWD, not the installation directory.
