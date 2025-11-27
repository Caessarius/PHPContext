# PHPContext - AI Code Context Extractor Tool

Token-efficient PHP metadata extraction tool using `nikic/php-parser` with dependency following and multi-path analysis.

---

## ⚠️ SECURITY WARNING

**CONTEXT.md contains SENSITIVE information about your codebase:**
- Internal file paths and directory structure
- Complete class names and architecture details
- Dependency information and versions
- Array keys and configuration patterns
- Public API surface and method signatures

### ❌ DO NOT:
- Commit to public repositories
- Share with untrusted parties
- Upload to public AI services without review
- Include in public documentation

### ✅ RECOMMENDED:
- Add CONTEXT.md to `.gitignore` (already configured)
- Review output before sharing
- Use only with trusted/self-hosted AI assistants
- Keep in private/internal documentation only

### 🔒 Security Features:
- **Path validation**: Only relative paths allowed (no absolute paths)
- **Sanitization**: Output is sanitized to prevent markdown/XSS injection
- **YAML sanitization**: Validates config files
- **Symlink protection**: Rejects symlinks outside project
- **Resource limits**: Memory and execution time limits
- **Whitelist filtering**: Optional namespace/class filtering

---

## Extracted Metadata

### Sections

- **Summary** - File/class/interface/trait/enum counts
- **Architecture Patterns** - Auto-detected (Entities, Services, Controllers, etc.)
- **Constants & Magic Values** - Class constants, array keys
- **Key Dependencies** - Most-used classes/interfaces
- **Namespace Structure** - Organization overview
- **Classes/Interfaces/Traits/Enums** - Detailed definitions

### Per Class

- Description, Annotations, FQCN
- Inheritance (extends/implements/uses traits)
- Type Dependencies
- Constructor Injection order
- Public API surface
- Constants, Properties, Methods

### Per Method

- Signature with full parameters
- Docblock summary
- Service calls (grouped by service)
- Throws, Control flow, Returns

---

## Requirements

- PHP 8.1+
- nikic/php-parser ^5.6
- symfony/yaml ^6.0|^7.0

## Installation

### 1. Install Dependencies (main project)
```bash
composer require nikic/php-parser symfony/yaml --dev
```

### 2. Place Tool in Project
```
/var/www/project/
├── scripts/phpcontext/         # ← Place here
│   ├── extract.php
│   ├── config.yaml
│   ├── src/
│   └── README.md
├── phpcontext.yaml             # Optional project config
└── vendor/                     # Contains dependencies
```

### 3. Make Executable
```bash
chmod +x scripts/phpcontext/extract.php
```

### 4. Run
```bash
scripts/phpcontext/extract.php src/
```

## Verification

```bash
# Check dependencies
composer show nikic/php-parser
composer show symfony/yaml

# Test extraction
scripts/phpcontext/extract.php --help

# Validate config
scripts/phpcontext/extract.php --config=phpcontext.yaml src --minimal
```

---

## Configuration System

PHPContext uses a **3-tier configuration system**:

1. **Default Config** (`config.yaml`) - Tool defaults
2. **Project Config** (`phpcontext.yaml`) - Project overrides auto-detected in project root or via `--config`
3. **CLI Arguments** - Runtime overrides

**Merge Strategy:**
- **Options/Limits**: CLI > Project > Default (override)
- **Paths/Exclude**: CLI + Project + Default (cumulative/union)

### config.yaml (Default)

Located in tool directory. Contains base configuration:

```yaml
# Default paths
paths:
  - '.'

# Exclusions (cumulative)
exclude:
  - 'vendor/'
  - 'tests/'
  - 'Migrations/'

# Whitelist (optional namespace filtering)
whitelist: []

# Follow dependencies
follow_dependencies:
  depth: 0                          # 0=disabled, 1=direct, 2=transitive
  scope: 'interfaces_and_abstract'  # all|interfaces|abstract_classes|interfaces_and_abstract
  internal_only: true               # Skip vendor/

# Output file
output: 'context/CONTEXT.md'

# Options and limits...
options:
  limits:
    max_methods_per_class: 100
    max_dependencies: 50
```

### phpcontext.yaml (Project Override)

Place in project root for team-wide configuration:

```yaml
# Override output
output: 'docs/ARCHITECTURE.md'

# Multiple paths with per-path config
paths:
  - path: 'src/Core'
    exclude: ['Tests/']
    options:
      limits:
        max_methods_per_class: 50

  - path: 'src/Modules/Payment'

  - path: 'lib/Internal'
    options:
      class_details:
        methods: false

# Follow direct dependencies only
follow_dependencies:
  depth: 1
  scope: 'interfaces_and_abstract'
  internal_only: true

# Additional exclusions
exclude:
  - 'fixtures/'

# Whitelist specific namespaces
whitelist:
  - 'App\Service\*'
  - '*Interface'

# Override limits
options:
  limits:
    max_methods_per_class: 30
    max_dependencies: 100
```

---

## Usage

**IMPORTANT**: All paths must be **relative** to project root. Absolute paths are rejected for security.

### Basic Usage

```bash
# Scan current directory with defaults
scripts/phpcontext/extract.php

# Scan specific directory
scripts/phpcontext/extract.php src

# Multiple directories
scripts/phpcontext/extract.php src lib/Core
```

### With Project Config

```bash
# Use project (phpcontext.yaml) auto-detected configuration
scripts/phpcontext/extract.php

# Project explicit config + additional path
scripts/phpcontext/extract.php modules --config=project_context.yaml

# Override output path + multiple paths
scripts/phpcontext/extract.php src modules --output=PROJECT_CONTEXT.md

# Override exclusions
scripts/phpcontext/extract.php --config=project_context.yaml --exclude=Tests/
```

### Advanced Options

```bash
# Full detail mode (high limits)
scripts/phpcontext/extract.php src --full

# Minimal mode (large projects)
scripts/phpcontext/extract.php src --minimal

# Custom exclusions (cumulative)
scripts/phpcontext/extract.php src --exclude=Migrations/ --exclude=fixtures/

# Suppress security warnings
scripts/phpcontext/extract.php src --suppress-warnings

# Combine everything
scripts/phpcontext/extract.php src modules \
  --config=project_context.yaml \
  --output=PROJECT_CONTEXT.md \
  --exclude=Tests/ \
  --full
```

### CLI Options

- `--config=<file>` - External config file (e.g., `phpcontext.yaml`)
- `--output=<file>` - Override output file path (relative to project root)
- `--full` - Remove most limits for detailed output
- `--minimal` - Minimal output for large projects
- `--exclude=<path>` - Add exclusion path (cumulative, can be repeated)
- `--suppress-warnings` - Suppress security warnings
- `--help`, `-h` - Show help message

## Follow Dependencies Feature

Extract metadata for dependencies automatically.

### Configuration

```yaml
follow_dependencies:
  depth: 1              # How deep to follow
  scope: 'interfaces_and_abstract'  # What to follow
  internal_only: true   # Skip vendor/
```

**Depth Levels:**
- `0` - Disabled (default)
- `1` - Direct dependencies only (recommended)
- `2` - Dependencies of dependencies (can explode)

**Scope Options:**
- `all` - Follow all dependencies
- `interfaces` - Only interfaces
- `abstract_classes` - Only abstract classes
- `interfaces_and_abstract` - Both (recommended)

**Internal Only:**
- `true` - Skip vendor/ dependencies (recommended)
- `false` - Include vendor classes

### Use Cases

**Understanding Service Contracts:**
```yaml
follow_dependencies:
  depth: 1
  scope: 'interfaces'
  internal_only: true
```

**Complete Architecture View:**
```yaml
follow_dependencies:
  depth: 2
  scope: 'interfaces_and_abstract'
  internal_only: true
```

### Search Patterns (Advanced)

PHPContext uses configurable search patterns to locate dependency files when the Composer autoloader fails. Patterns are defined in `config.yaml` and can be customized in `phpcontext.yaml`:

```yaml
follow_dependencies:
  search_patterns:
    # Drupal core modules
    - condition: 'starts_with:Drupal\'
      condition_exclude: ['Drupal\Core\', 'Drupal\Component\']
      paths:
        - '{cwd}/web/core/modules/{module}/src/{class_path}'

    # Symfony components
    - condition: 'starts_with:Symfony\'
      paths:
        - '{cwd}/vendor/symfony/{component}/src/{class_path}'

    # Custom patterns
    - condition: 'always'
      paths:
        - '{cwd}/src/{path}'
```

**Supported Placeholders:**
- `{cwd}` - Current working directory
- `{path}` - Full FQCN as path
- `{class_path}` - Class path after namespace extraction
- `{module}`, `{module_lower}` - Drupal module name
- `{component}` - Symfony component (kebab-case)
- `{package}` - Package name (Doctrine, etc.)

**Conditions:**
- `always` - Apply to all classes
- `starts_with:PREFIX` - Apply to FQCNs starting with PREFIX
- `condition_exclude` - Exclude specific patterns

Most projects don't need to customize these patterns. They're pre-configured for Drupal, Symfony, Doctrine, Laravel, and other common frameworks.

## Whitelist Filtering

Filter analysis to specific namespaces/classes:

```yaml
whitelist:
  - 'App\Service\*'        # All services
  - 'App\Core\*'           # Core namespace
  - '*Interface'           # All interfaces
  - '*Contract'            # All contracts
  - 'App\Entity\User'      # Specific class
```

Empty array = no filtering (include all).

## Path Resolution

**All paths are relative to project root.**

```yaml
paths:
  - 'src/Core'                    # Relative path
  - 'modules/custom/my_module'    # Drupal module
  - 'lib/CompanyFramework'        # Internal library
```

## Per-Path Configuration

Override options/limits for specific paths:

```yaml
paths:
  # Detailed analysis of core
  - path: 'src/Core'
    options:
      limits:
        max_methods_per_class: 100

  # Minimal analysis of large module
  - path: 'src/Modules/Legacy'
    exclude: ['Tests/', 'fixtures/']
    options:
      class_details:
        properties: false
        methods: false
      limits:
        max_methods_per_class: 10
```

## Configuration Examples

### Symfony Project

```yaml
# phpcontext.yaml
paths:
  - 'src/Controller'
  - 'src/Entity'
  - 'src/Service'
  - 'src/Repository'

follow_dependencies:
  depth: 1
  scope: 'interfaces_and_abstract'
  internal_only: true

whitelist:
  - 'App\\*'

exclude:
  - 'Tests/'
  - 'Migrations/'

options:
  limits:
    max_methods_per_class: 30
    max_dependencies: 75
```

### Drupal Module

```yaml
# phpcontext.yaml
paths:
  - 'web/modules/custom/my_module/src'

follow_dependencies:
  depth: 1
  scope: 'interfaces'
  internal_only: true

whitelist:
  - 'Drupal\\my_module\\*'

exclude:
  - 'Tests/'

options:
  class_details:
    plugin_annotations: true
  limits:
    max_methods_per_class: 20
```

### Modular Monolith

```yaml
# phpcontext.yaml
paths:
  - path: 'src/Core'
    options:
      limits:
        max_methods_per_class: 50

  - path: 'src/Modules/Payment'
  - path: 'src/Modules/Inventory'
  - path: 'src/Modules/Shipping'

  - path: 'lib/SharedKernel'
    options:
      class_details:
        methods: false

follow_dependencies:
  depth: 1
  scope: 'interfaces_and_abstract'
  internal_only: true

output: 'docs/CODEBASE_CONTEXT.md'
```

---

## Troubleshooting

**Error: "Composer autoloader not found"**
- Run `composer require nikic/php-parser symfony/yaml --dev`
- Verify `vendor/autoload.php` exists

**Error: "symfony/yaml not found"**
- Run `composer require symfony/yaml --dev`

**Error: "Parse error in..."**
- File may have syntax errors
- Check PHP version compatibility (requires 8.1+)

**Error: "Config file not found"**
- Ensure path is relative to project root
- Use `--config=./phpcontext.yaml` if in root

**Output too large**
- Use `--minimal` flag
- Add more exclusions
- Reduce `max_methods_per_class` limit
- Disable `follow_dependencies`

**Missing dependencies in output**
- Increase `follow_dependencies.depth`
- Change `scope` to `all`
- Check if dependencies are in excluded paths

---

## Best Practices

### 1. Start Conservative

```yaml
follow_dependencies:
  depth: 0              # Start disabled

options:
  limits:
    max_methods_per_class: 20
```

### 2. Increase Gradually

```yaml
follow_dependencies:
  depth: 1              # Add after baseline works
  scope: 'interfaces'   # Start narrow
  internal_only: true
```

### 3. Use Whitelist for Large Codebases

```yaml
whitelist:
  - 'App\\Service\\*'
  - '*Interface'
```

### 4. Per-Path Optimization

```yaml
paths:
  - path: 'src/Core'
    options:
      limits:
        max_methods_per_class: 50

  - path: 'src/Legacy'
    options:
      limits:
        max_methods_per_class: 5
      class_details:
        properties: false
```

### 5. Version Control Config

Commit `phpcontext.yaml` to share team configuration.
Add `CONTEXT.md` to `.gitignore` unless intentionally shared.

## Security Considerations

### Path Traversal Prevention

- Only relative paths accepted
- Paths validated against project root
- Symlinks outside root rejected

### YAML Injection Prevention

- symfony/yaml validates structure
- Config keys strictly checked
- Malformed YAML rejected

### Resource Limits

```yaml
resource_limits:
  memory_limit: '512M'  # Prevent memory exhaustion
  time_limit: 300       # Prevent infinite loops
```

### Sensitive Data

- Review `CONTEXT.md` before sharing
- Use `.gitignore` for output files
- Consider separate configs for public/private analysis

---

## Options Potential Impact on AI Quality

Ranked by reduction potential in hallucinations (Claude's estimates :thinking:):

### 🔴 Critical (10-8/10)
1. **plugin_annotations** - Framework metadata
   - Config: `options.class_details.plugin_annotations`
2. **type_dependencies** - Class relationships
   - Config: `options.class_details.type_dependencies`
3. **public_api** - Entry points
   - Config: `options.class_details.public_api`
4. **constructor_injection** - DI patterns
   - Config: `options.class_details.constructor_injection`
5. **inheritance** - extends/implements
   - Config: `options.class_details.inheritance`

### 🟡 High (7-6/10)
6. **service_calls** - Method behavior
   - Config: `options.body_patterns.service_calls`
7. **throws** - Error handling
   - Config: `options.body_patterns.throws`
8. **parameters** - Full signatures
   - Config: `options.method_details.parameters`
9. **docblock_summary** - Intent
   - Config: `options.class_details.docblock_summary` and `options.method_details.docblock_summary`
10. **constants** - Named values
   - Config: `options.class_details.constants` and `options.magic_values.class_constants`

### 🟢 Moderate (5-4/10)
11. **array_keys** - Config patterns
   - Config: `options.magic_values.array_keys`
12. **properties** - State structure
   - Config: `options.class_details.properties`
13. **control_flow** - Logic complexity
   - Config: `options.body_patterns.control_flow`

### ⚪ Optional (3-2/10)
14. **default_values** - Initial state
   - Config: `options.property_details.default_values`
15. **namespace_structure** - Organization
   - Config: `options.namespace_structure`

## Token Efficiency

Typical output size:

- **Small** (10-50 classes): ~20-50KB (~5-12k tokens)
- **Medium** (50-200 classes): ~50-150KB (~12-35k tokens)
- **Large** (200-500 classes): ~150-400KB (~35-100k tokens)

**Modifiers:**
- `--minimal`: -50% size
- `--full`: +40% size
- `follow_dependencies depth=1`: +20-50% (depends on deps)

---

## Integration with AI Assistants

### Claude/ChatGPT/Mistral

Upload `CONTEXT.md` for comprehensive codebase understanding:

```
I'm working on a Symfony project. Here's the architectural context:
[Attach CONTEXT.md]

Help me implement a new payment service that follows our patterns...
```

### Programmatic Usage

```php
<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/src/MetadataExtractor.php';
require_once __DIR__ . '/src/MetadataVisitor.php';
require_once __DIR__ . '/src/ContextFormatter.php';

use ContextExtractor\{MetadataExtractor, ContextFormatter};

$extractor = new MetadataExtractor();
$extractor->setFollowDependencies([
    'depth' => 1,
    'scope' => 'interfaces',
    'internal_only' => true,
]);
$extractor->setWhitelist(['App\\Service\\*']);

$metadata = $extractor->extractFromDirectory('src/', ['vendor/', 'tests/']);

$formatter = new ContextFormatter(['limits' => ['max_methods_per_class' => 20]]);
file_put_contents('CONTEXT.md', $formatter->format($metadata));
```

## CI/CD Integration

### GitHub Actions

```yaml
name: Update Architecture Context
on: [push]
jobs:
  context:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
      - name: Install Dependencies
        run: composer install --dev
      - name: Generate Context
        run: scripts/phpcontext/extract.php --config=phpcontext.yaml
      - name: Commit Context
        run: |
          git config user.name "Bot"
          git config user.email "bot@example.com"
          git add docs/ARCHITECTURE.md
          git commit -m "Update architecture context" || exit 0
          git push
```

### Pre-commit Hook

```bash
#!/bin/bash
# .git/hooks/pre-commit
scripts/phpcontext/extract.php --config=phpcontext.yaml
git add docs/ARCHITECTURE.md
```

## Disclaimer
Experimental code from *vibe coding* sessions with *hours* of *prompt crafting*, review, and testing. Mixed results. :confused:

## License

MIT

```
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY ...
```

## Support

For issues or questions:
1. Check troubleshooting section
2. Verify configuration syntax
3. Test with `--minimal` flag first
4. Review security warnings
5. Review code
...
9. Ask your *assistant* for clarification :smiley:
