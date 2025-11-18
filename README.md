# PHPContext - AI Code Context Extractor

Token-efficient PHP metadata extraction using `nikic/php-parser` for AI assistants.


## Requirements

PHP 8.1+, nikic/php-parser


## Installation

### 1. Add to main project
```bash
composer require nikic/php-parser --dev
```

### 2. Place in scripts/phpcontext/
```
/var/www/project/
├── scripts/phpcontext/         # ← Place here
│   ├── extract.php
│   ├── src/
│   └── README.md
└── vendor/                     # Contains nikic/php-parser
```

### 3. Make executable
```bash
chmod +x scripts/phpcontext/extract.php
```

### 4. Run
```bash
scripts/phpcontext/extract.php src/ CONTEXT.md
```


## Verification

```bash
# Check if nikic/php-parser is installed
composer show nikic/php-parser

# Test extraction
scripts/phpcontext/extract.php --help 2>&1 | head -5
```


## Troubleshooting

**Error: "Composer autoloader not found"**
- Run `composer require nikic/php-parser --dev` in project root
- Verify `vendor/autoload.php` exists

**Error: "Parse error in..."**
- Check PHP version compatibility (requires PHP 8.1+)
- File may have syntax errors


## Usage

```bash
# Basic
scripts/phpcontext/extract.php /path/to/src CONTEXT.md

# Full detail (no truncation)
scripts/phpcontext/extract.php src/ CONTEXT.md --full

# Minimal (large projects)
scripts/phpcontext/extract.php src/ CONTEXT.md --minimal

# Custom exclusions
scripts/phpcontext/extract.php src/ CONTEXT.md --exclude=Migrations/

# Additional exclusions
scripts/phpcontext/extract.php /path/to/project CONTEXT.md --exclude=migrations/ --exclude=fixtures/

# Drupal custom modules
scripts/phpcontext/extract.php web/modules/custom CUSTOM_CONTEXT.md

# Drupal specific module
scripts/phpcontext/extract.php web/modules/custom/my_module MODULE_CONTEXT.md
```


## Configuration

```php
'options' => [
    // Top-level sections
    'summary' => true,
    'architecture_patterns' => true,
    'constants_magic_values' => true,
    'key_dependencies' => true,
    'namespace_structure' => true,
    'files_analyzed' => false,

    // Constants & Magic Values subsections
    'magic_values' => [
        'class_constants' => true,
        'magic_strings' => true,
        'magic_numbers' => true,
        'array_keys' => true,
    ],

    // Class-level details
    'class_details' => [
        'docblock_summary' => true,
        'plugin_annotations' => true,
        'attributes' => true,
        'inheritance' => true,
        'type_dependencies' => true,
        'constructor_injection' => true,
        'public_api' => true,
        'constants' => true,
        'properties' => true,
        'methods' => true,
    ],

    // Method-level details
    'method_details' => [
        'docblock_summary' => true,
        'attributes' => true,
        'parameters' => true,
        'return_type' => true,
        'body_patterns' => true,
    ],

    // Method body patterns
    'body_patterns' => [
        'service_calls' => true,
        'throws' => true,
        'control_flow' => true,
        'returns' => true,
    ],

    // Property details
    'property_details' => [
        'docblock_summary' => true,
        'default_values' => true,
    ],

    // Limits
    'limits' => [
        'max_methods_per_class' => PHP_INT_MAX,
        'max_properties_per_class' => 15,
        'max_pattern_classes' => 20,
        'max_dependencies' => 15,
        'max_magic_strings' => 15,
        'max_magic_numbers' => 10,
        'max_array_keys' => 15,
    ],
];
```


## Symfony/Drupal/OroPlatform features

Automatically detects common patterns:
- Entities (Doctrine annotations)
- Repositories
- Services
- Controllers
- Event Listeners
- Form Types
- Console Commands


## Limits by Project Type

**Drupal:**
```php
'limits' => [
    'max_methods_per_class' => 15,      // Verbose classes
    'max_properties_per_class' => 10,
    'max_magic_strings' => 20,          // Config keys
]
```

**Symfony:**
```php
'limits' => [
    'max_methods_per_class' => 12,
    'max_properties_per_class' => 12,
    'max_magic_strings' => 12,
]
```

**OroPlatform:**
```php
'limits' => [
    'max_methods_per_class' => 10,
    'max_properties_per_class' => 20,   // Large entities
    'max_dependencies' => 25,           // Complex DI
]
```


## Priority: Options Impact on AI Quality

Ranked by reduction in hallucinations:

### 🔴 Critical (10-8/10)
1. **plugin_annotations** - Framework metadata
2. **type_dependencies** - Class relationships
3. **public_api** - Entry points
4. **constructor_injection** - DI patterns
5. **inheritance** - extends/implements

### 🟡 High (7-6/10)
6. **service_calls** - Method behavior
7. **throws** - Error handling
8. **parameters** - Full signatures
9. **docblock_summary** - Intent
10. **constants** - Named values

### 🟢 Moderate (5-4/10)
11. **array_keys** - Config patterns
12. **properties** - State structure
13. **control_flow** - Logic complexity
14. **magic_strings** - Hard-coded values

### ⚪ Optional (3-2/10)
15. **default_values** - Initial state
16. **magic_numbers** - Numeric literals
17. **namespace_structure** - Organization


## Extracted Metadata

**Sections:**
- Summary - Counts
- Architecture Patterns - Auto-detected (Repository, Entities, Services, Controllers, etc.)
- Constants & Magic Values - Literals, array keys
- Key Dependencies - Classes/interfaces usage ranking
- Namespace Structure - Organized by namespace
- Classes/Interfaces/Traits/Enums - Detailed definitions

**Per Class:**
```
Description, Annotations, FQCN, Inheritance,
Type Dependencies, Constructor Injection, Public API,
Constants, Properties, Methods
```

**Per Method (indented):**
```
Signature, Docblock, Service calls, Throws,
Control flow, Returns
```


## Token Efficiency

Typical output:
- Small (10-50 classes): ~20-50KB (~5-12k tokens)
- Medium (50-200): ~50-150KB (~12-35k tokens)
- Large (200-500): ~150-400KB (~35-100k tokens)

`--minimal`: -50% | `--full`: +40%


## Examples

### Integration with AI Assistants - Claude/ChatGPT/Cursor

Upload `CONTEXT.md` to provide comprehensive codebase context:

```
I'm working on a Symfony project. Here's the code context:
[Attach CONTEXT.md]

Help me implement...
```

### Programmatic
```php
<?php
// Assumes nikic/php-parser installed in main project
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/src/MetadataExtractor.php';
require_once __DIR__ . '/src/MetadataVisitor.php';
require_once __DIR__ . '/src/ContextFormatter.php';

use ContextExtractor\{MetadataExtractor, ContextFormatter};

$extractor = new MetadataExtractor();
$metadata = $extractor->extractFromDirectory('/src', ['vendor/']);

$options = ['limits' => ['max_methods_per_class' => 5]];
$formatter = new ContextFormatter($options);

file_put_contents('CONTEXT.md', $formatter->format($metadata));
```

### Pre-commit Hook

```bash
#!/bin/bash
# .git/hooks/pre-commit
scripts/phpcontext/extract.php . CONTEXT.md --exclude=vendor/
git add CONTEXT.md
```

### CI/CD Integration

```yaml
# .github/workflows/context.yml
name: Update Context
on: [push]
jobs:
  context:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Install Dependencies
        run: composer install --dev
      - name: Generate Context
        run: scripts/phpcontext/extract.php . CONTEXT.md
      - name: Commit Context
        run: |
          git config user.name "Bot"
          git config user.email "bot@example.com"
          git add CONTEXT.md
          git commit -m "Update CONTEXT.md" || exit 0
          git push
```
