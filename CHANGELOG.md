# Changelog

All notable changes to PHPContext will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6.1] - 2025-11-28

### Fixed
- `public_api: false` config option now properly respected - added config check before rendering Public API section

### Changed
- Methods in Classes/Interfaces/Traits sections now sorted by visibility (public → protected → private) for better readability

## [0.6.0] - 2025-11-27

### Changed
- **BREAKING:** Scope filter (`interfaces_and_abstract`, etc.) now only applies to recursive dependencies (depth > 1)
- **BREAKING:** `internal_only` filter now only applies to recursive dependencies (depth > 1)
- Direct dependencies (Level 1) are now ALL extracted without any scope or internal_only filtering
- Classes/Interfaces/Traits/Enums sections now ordered in two phases: Namespace Structure items first, then Key Dependencies items (usage-sorted, limited by max_dependencies)
- `max_dependencies` limit now consistently controls both Key Dependencies display AND which dependencies get detailed in Classes/Interfaces sections

### Fixed
- Direct dependencies from vendor packages (Drupal Core, Symfony) now properly extracted when referenced in codebase
- `shouldProcessFile()` now respects depth parameter - vendor files at depth=1 are always processed
- Vendor dependencies at depth=1 no longer incorrectly marked as "excluded by filter"
- Not Found Dependencies section now only shows genuine path discovery failures within max_dependencies limit
- Removed 'filtered_by_scope' entries from Not Found Dependencies (these are intentional filters, not failures)
- Classes/Interfaces sections now respect Key Dependencies usage-sorted order after Namespace Structure items

### Added
- Dependency depth tracking in Key Dependencies section - displays depth level for recursive dependencies (depth > 1)
- New `dependency_depths` metadata array to track at which level each dependency was discovered
- New `orderTypesByPriority()` method in ContextFormatter for consistent ordering logic
- Depth parameter to `shouldFollowDependency()` and `shouldProcessFile()` methods to enable level-based filtering

## [0.5.1] - 2025-11-27

### Fixed
- **Not Found Dependencies** section now correctly filters out dependencies already in Key Dependencies list
- Dependencies hidden by `max_dependencies` limit no longer incorrectly appear as "not found"
- Section is now hidden entirely when all "not found" entries are actually tracked in Key Dependencies

## [0.5.0] - 2025-11-27

### Added
- **Multi-framework auto-discovery system** with automatic project type detection
- Project type detection: Drupal, Symfony, OroPlatform, Laravel, or standard PHP
- `detectProjectType()` method with filesystem and Composer package detection
- `checkComposerPackage()` helper for reliable package detection
- `findFileViaFrameworkAutoDiscovery()` unified routing method
- **Symfony auto-discovery**: Symfony\Bundle\*, Symfony\Component\*, App\* namespaces
- **OroPlatform auto-discovery**: Oro\Bundle\*, Oro\Component\* namespaces
- **Laravel auto-discovery**: App\*, Illuminate\* namespaces
- Framework-specific path resolution with camelCase to kebab-case conversion
- Support for mixed projects (e.g., Symfony bundles in non-Symfony projects)

### Changed
- Auto-discovery now framework-aware with intelligent routing
- Fallback chain: Composer autoloader → framework auto-discovery → manual patterns
- `findFileViaDrupalAutoDiscovery()` now integrated into unified system

## [0.4.0] - 2025-11-27

### Added
- Automatic Drupal module file discovery via new `findFileViaDrupalAutoDiscovery()` method
- Auto-detection for contrib, custom, and core Drupal modules without manual configuration
- Support for package/submodule structures (e.g., commerce_order → commerce/modules/order)
- Automatic fallback chain: Composer autoloader → Drupal auto-discovery → manual search patterns

### Fixed
- Drupal contrib/custom modules now properly resolved without requiring manual search_patterns
- Previously missing Drupal dependencies (webform, commerce_*, migrate, telephone) now found automatically

### Changed
- search_patterns remain available for edge cases and non-Drupal projects but no longer required

## [0.3.0] - 2025-11-27

### Added
- Built-in PHP classes tracking with configurable display (`include_builtin_php_classes`)
- New config option `follow_dependencies.include_builtin_php_classes` (default: false)
- New config option `options.limits.max_builtin_php_classes` (default: 50)
- "Built-in PHP Dependencies" section in output when enabled (SPL, DOM, etc.)
- Usage count tracking for built-in PHP classes, sorted descending

### Fixed
- Built-in PHP classes (Traversable, Countable, RuntimeException, DOMDocument, etc.) no longer incorrectly marked as "not found dependencies"
- Built-in classes properly detected via `isBuiltinPhpClass()` method

### Changed
- Built-in PHP classes now have dedicated section after Key Dependencies when enabled
- "Not Found Dependencies" section now only shows actual missing files, not built-in classes

## [0.2.2] - 2025-11-26

### Fixed
- Key Dependencies now shows all referenced dependencies instead of only documented ones
- Path resolution issue where Composer autoloader returned paths with relative segments causing false vendor detection
- Dependency extraction now includes USE statements in addition to type hints, extends, and implements

### Added
- `{drupal_package}/{drupal_submodule}` placeholder support for Drupal contrib modules with submodules
- Search pattern for submodule structure: `web/modules/contrib/{drupal_package}/modules/{drupal_submodule}/src/{class_path}`
- "Not Found Dependencies" section in output after Key Dependencies showing failed dependencies with reasons:
  - file_not_found: File couldn't be located
  - excluded_by_filter: File exists but matches exclude patterns
  - filtered_by_scope: Doesn't match follow_dependencies scope setting
  - parse_error: File found but PHP parse error
- `not_found_dependencies` tracking in metadata arrays

### Changed
- `shouldProcessFile()` now uses `realpath()` before checking if file is in vendor/
- `extractNewDependencies()` includes `uses` array from all metadata types
- `analyzeDependencies()` filtering removed to show all dependencies (not just documented)
- Significantly increased context file size and documented class coverage

### Removed
- PHP keywords (static, self, parent) from type dependencies extraction
- Bogus dependency entries from "Not Found Dependencies" section

## [0.2.1] - 2025-11-26

### Fixed
- YAML single-quoted strings with double backslashes now correctly use single backslashes for pattern matching
- Bug in `findFileManually()` that prevented class file resolution after refactoring

### Changed
- Updated all condition patterns in config.yaml from double backslashes (`\\`) to single backslashes (`\`) for correct pattern matching

## [0.2.0] - 2025-11-26

### Added
- Refactored `findFileManually()` to use YAML patterns from config.yaml
- `evaluateCondition()` method for condition matching (starts_with, always, condition_exclude)
- `resolvePlaceholders()` method supporting placeholders: {cwd}, {path}, {class_path}, {module}, {module_lower}, {component}, {package}
- "Search Patterns (Advanced)" subsection in README.md under "Follow Dependencies Feature"

### Changed
- Significantly reduced `findFileManually()` complexity by replacing hardcoded logic with YAML patterns
- More maintainable and user-extensible via YAML configuration
- **Generated:** output in CONTEXT.md now only includes date (removed hours/minutes/seconds) for better caching

### Improved
- Code follows coding standards with minimal pre-existing warnings
- Fully backward compatible - existing patterns preserved in config.yaml

## [0.1.1] - 2025-11-25

### Added
- Comprehensive path resolution for vendor/Symfony/contrib classes in `findFileManually()`
- Support for Symfony components (with Component/Bridge prefix)
- Support for Drupal contrib modules (with lowercase conversion)
- Support for OroPlatform, Doctrine, Guzzle, Laravel/Illuminate packages
- Detection for `new ClassName()` instantiations in `extractTypeDependencies()`

### Fixed
- `shouldProcessFile()` now allows vendor files when `internal_only=false`
- Depth:2 recursive dependency behavior verified working correctly
- Empty if/else blocks removed from `followDependenciesRecursive()`

### Improved
- Code passes phpcs validation with Coding Standards
- Dependencies of dependencies properly extracted at multiple levels

### Documented
- Proposed `search_patterns` structure added to `config.yaml` for future extensibility
- Current implementation marked as functional and sufficient

## [0.1.0] - 2025-11-25

### Fixed
- Sort distinct Key Dependencies descending by usage count
- Group and sort service calls in method body patterns descending
- Option `files_analyzed=false` now properly respected (section hidden when disabled)
- Class Constants limit now overridden by `--full` CLI option
- Extra lines removed between **Extends:** and **Methods:** in Interfaces section

### Changed
- Service call patterns now grouped with count: `→ $this->request: 3* get(), ...` instead of listing all calls
- Implemented `max_body_patterns_service_calls=10` limit (configurable)

### Improved
- Code formatting standardized according to Coding Standards:
  - 2 spaces indentation
  - TRUE, FALSE, NULL uppercase
  - Inline comments end with proper punctuation
  - Comments properly positioned

### Updated
- README.md Usage section paths updated from `scripts/context-extractor/` to `scripts/phpcontext/`
- "Oro Platform" replaced with "OroPlatform" throughout documentation
- Configuration section updated with all `$config['options']` from extract.php
- Removed asterisk from "Architecture Patterns" description

### Removed
- `magic_strings` and `magic_numbers` logic and configuration completely removed
- Merged `.gitignore` into `.gitignore.recommended` (removed duplicate)
- Removed `context` folder (output at project root level)
- Removed `composer.lock` (not needed)

### Changed (--full option)
- `--full` option now only affects elements in limits, not `files_analyzed`
- Files Analyzed section displays relative paths (not absolute paths)

### Added
- Metrics: cyclomatic complexity and coupling scores

## Earlier Versions

Prior to version 0.1.0, the project was in initial development phase without formal versioning.
