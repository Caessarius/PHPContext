<?php

namespace ContextExtractor;

/**
 * Formats code metadata into markdown documentation.
 *
 * This class takes structured metadata about PHP code (classes, interfaces,
 * traits, enums) and generates comprehensive markdown documentation with
 * security considerations and code metrics.
 */
class ContextFormatter {

  /**
   * Configuration options for formatting output.
   *
   * @var array
   */
  private array $options;

  /**
   * Constructor.
   *
   * @param array $options
   *   Configuration options for formatting. Merged with defaults.
   */
  public function __construct(array $options = []) {
    // Default options.
    $defaults = [
      'summary' => TRUE,
      'architecture_patterns' => TRUE,
      'constants_magic_values' => TRUE,
      'key_dependencies' => TRUE,
      'namespace_structure' => TRUE,
      'files_analyzed' => FALSE,
      'magic_values' => [
        'class_constants' => TRUE,
        'array_keys' => TRUE,
      ],
      'class_details' => [
        'docblock_summary' => TRUE,
        'plugin_annotations' => TRUE,
        'attributes' => TRUE,
        'inheritance' => TRUE,
        'type_dependencies' => TRUE,
        'constructor_injection' => TRUE,
        'public_api' => TRUE,
        'constants' => TRUE,
        'properties' => TRUE,
        'methods' => TRUE,
      ],
      'method_details' => [
        'docblock_summary' => TRUE,
        'attributes' => TRUE,
        'parameters' => TRUE,
        'return_type' => TRUE,
        'body_patterns' => TRUE,
      ],
      'body_patterns' => [
        'service_calls' => TRUE,
        'throws' => TRUE,
        'control_flow' => TRUE,
        'returns' => TRUE,
      ],
      'property_details' => [
        'docblock_summary' => TRUE,
        'default_values' => TRUE,
      ],
      'limits' => [
        'max_methods_per_class' => 100,
        'max_properties_per_class' => 15,
        'max_pattern_classes' => 20,
        'max_dependencies' => 15,
        'max_builtin_php_classes' => 15,
        'max_class_constants' => 20,
        'max_array_keys' => 15,
        'max_body_patterns_service_calls' => 10,
      ],
    ];

    $this->options = array_replace_recursive($defaults, $options);
  }

  /**
   * Sanitizes markdown content to prevent injection attacks.
   *
   * @param string $text
   *   The text to sanitize.
   *
   * @return string
   *   The sanitized text.
   */
  private function sanitizeMarkdown(string $text): string {
    // Escape HTML special characters.
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Remove malicious markdown patterns.
    $text = preg_replace(
      '/\[([^\]]+)\]\(javascript:[^\)]*\)/',
      '[$1](BLOCKED)',
      $text
    );
    $text = preg_replace(
      '/<script[^>]*>.*?<\/script>/is',
      '[SCRIPT_BLOCKED]',
      $text
    );
    $text = preg_replace(
      '/<iframe[^>]*>.*?<\/iframe>/is',
      '[IFRAME_BLOCKED]',
      $text
    );

    // Limit line length to prevent DoS.
    $lines = explode("\n", $text);
    $lines = array_map(function ($line) {
      return strlen($line) > 500 ? substr($line, 0, 500) . '...' : $line;
    }, $lines);

    return implode("\n", $lines);
  }

  /**
   * Formats metadata into markdown output.
   *
   * @param array $metadata
   *   The metadata array to format.
   *
   * @return string
   *   The formatted markdown output.
   */
  public function format(array $metadata): string {
    $output = [];

    // Header with security warning.
    $output[] = "# Code Context Reference";
    $output[] = "";
    $output[] = "> ⚠️ **CONFIDENTIAL** - This file contains internal architecture details.";
    $output[] = "> Do NOT commit to public repositories or share publicly without review.";
    $output[] = "";
    $output[] = "**Generated:** " . date('Y-m-d');
    $output[] = "**Files Analyzed:** {$metadata['summary']['files_analyzed']}";
    $output[] = "";

    // Summary.
    if ($this->options['summary']) {
      $output[] = "## Summary";
      $output[] = "";
      $output[] = "- **Classes:** {$metadata['summary']['classes']}";
      $output[] = "- **Interfaces:** {$metadata['summary']['interfaces']}";
      $output[] = "- **Traits:** {$metadata['summary']['traits']}";
      $output[] = "- **Enums:** {$metadata['summary']['enums']}";
      $output[] = "";

      // Add metrics if available.
      if (!empty($metadata['metrics'])) {
        $metrics = $metadata['metrics'];

        // Cyclomatic complexity.
        if (!empty($metrics['cyclomatic_complexity'])) {
          $cc = $metrics['cyclomatic_complexity'];
          $output[] = "### Code Metrics";
          $output[] = "";
          $output[] = "**Cyclomatic Complexity:**";
          $output[] = "- Total: {$cc['total']}";
          $output[] = "- Average per method: {$cc['average_per_method']}";
          if (!empty($cc['max_class'])) {
            $output[] = "- Most complex class: `{$cc['max_class']}` (score: {$cc['max_class_score']})";
          }

          if (!empty($cc['top_complex_classes'])) {
            $output[] = "";
            $output[] = "**Top 5 Most Complex Classes:**";
            $count = 0;
            foreach ($cc['top_complex_classes'] as $class => $score) {
              if ($count++ >= 5) {
                break;
              }
              $output[] = "- `{$class}`: {$score}";
            }
          }
          $output[] = "";
        }

        // Coupling.
        if (!empty($metrics['coupling'])) {
          $coupling = $metrics['coupling'];
          $output[] = "**Coupling Scores:**";
          $output[] = "- Average instability: {$coupling['average_instability']}";

          if (!empty($coupling['most_unstable'])) {
            $output[] = "";
            $output[] = "**Top 5 Most Unstable Classes** (I = Ce / (Ca + Ce)):";
            $count = 0;
            foreach ($coupling['most_unstable'] as $class => $scores) {
              if ($count++ >= 5) {
                break;
              }
              $instability = $scores['instability'];
              $afferent = $scores['afferent'];
              $efferent = $scores['efferent'];
              $output[] = "- `{$class}`: I={$instability} (Ca={$afferent}, Ce={$efferent})";
            }
          }

          if (!empty($coupling['most_coupled'])) {
            $output[] = "";
            $output[] = "**Top 5 Most Coupled Classes:**";
            $count = 0;
            foreach ($coupling['most_coupled'] as $class => $totalCoupling) {
              if ($count++ >= 5) {
                break;
              }
              $output[] = "- `{$class}`: {$totalCoupling} total dependencies";
            }
          }
          $output[] = "";
        }
      }

      $output[] = "";
    }

    // Architecture patterns.
    if ($this->options['architecture_patterns'] && !empty($metadata['patterns'])) {
      $output[] = "## Architecture Patterns";
      $output[] = "";
      foreach ($metadata['patterns'] as $pattern => $classes) {
        $output[] = "### " . ucfirst($pattern) . " (" . count($classes) . ")";
        $limit = $this->options['limits']['max_pattern_classes'];
        foreach (array_slice($classes, 0, $limit) as $class) {
          $output[] = "- `{$class}`";
        }
        if (count($classes) > $limit) {
          $remaining = count($classes) - $limit;
          $output[] = "- *... and {$remaining} more*";
        }
        $output[] = "";
      }
      $output[] = "";
    }

    // Constants & Magic Values.
    if ($this->options['constants_magic_values']) {
      $output = array_merge($output, $this->formatConstantsAndMagicValues($metadata));
    }

    // Key dependencies.
    if ($this->options['key_dependencies'] && !empty($metadata['dependencies'])) {
      $output[] = "## Key Dependencies";
      $output[] = "";
      $limit = $this->options['limits']['max_dependencies'];
      $topDeps = array_slice($metadata['dependencies'], 0, $limit);
      $depths = $metadata['dependency_depths'] ?? [];
      foreach ($topDeps as $dep => $usages) {
        $count = count($usages);
        $plural = $count > 1 ? 's' : '';
        $depthInfo = '';
        if (isset($depths[$dep]) && $depths[$dep] > 1) {
          $depthInfo = ", depth {$depths[$dep]}";
        }
        $output[] = "- `{$dep}` ({$count} usage{$plural}{$depthInfo})";
      }
      if (count($metadata['dependencies']) > $limit) {
        $remaining = count($metadata['dependencies']) - $limit;
        $output[] = "- *... and {$remaining} more*";
      }
      $output[] = "";
      $output[] = "";
    }

    // Built-in PHP Dependencies section.
    if (!empty($metadata['builtin_php_classes'])) {
      $output[] = "## Built-in PHP Dependencies";
      $output[] = "";
      $output[] = "The following built-in PHP classes/interfaces are referenced by the codebase:";
      $output[] = "";
      $limit = $this->options['limits']['max_builtin_php_classes'] ?? 50;
      $classes = array_slice($metadata['builtin_php_classes'], 0, $limit, TRUE);
      foreach ($classes as $class => $usages) {
        $count = count($usages);
        $plural = $count > 1 ? 's' : '';
        $output[] = "- `{$class}` ({$count} usage{$plural})";
      }
      if (count($metadata['builtin_php_classes']) > $limit) {
        $remaining = count($metadata['builtin_php_classes']) - $limit;
        $output[] = "- *... and {$remaining} more*";
      }
      $output[] = "";
      $output[] = "";
    }

    // Not Found Dependencies section.
    if (!empty($metadata['not_found_dependencies'])) {
      // Only show path discovery failures (file_not_found, excluded_by_filter,
      // parse_error) that are within max_dependencies limit.
      // Exclude 'filtered_by_scope' as these are intentionally filtered.
      $limit = $this->options['limits']['max_dependencies'];
      $topDependencies = array_slice($metadata['dependencies'] ?? [], 0, $limit, TRUE);

      $filteredNotFound = [];
      foreach ($metadata['not_found_dependencies'] as $fqcn => $reason) {
        // Only include if it's a real path discovery failure.
        if ($reason === 'filtered_by_scope') {
          continue;
        }
        // Only include if it's within the max_dependencies limit.
        if (isset($topDependencies[$fqcn])) {
          $filteredNotFound[$fqcn] = $reason;
        }
      }

      if (!empty($filteredNotFound)) {
        $output[] = "## Not Found Dependencies";
        $output[] = "";
        $output[] = "⚠️  The following dependencies were referenced but could not be extracted:";
        $output[] = "";
        foreach ($filteredNotFound as $fqcn => $reason) {
          $reasonText = match ($reason) {
            'file_not_found' => 'file not found',
            'excluded_by_filter' => 'excluded by filter',
            'parse_error' => 'parse error',
            default => $reason,
          };
          $output[] = "- `{$fqcn}` ({$reasonText})";
        }
        $output[] = "";
        $output[] = "**Recommendation:** Review search patterns in `config.yaml` if dependencies are missing.";
        $output[] = "Check `follow_dependencies.search_patterns` for path resolution rules.";
        $output[] = "";
        $output[] = "";
      }
    }

    // Namespace overview.
    if ($this->options['namespace_structure'] && !empty($metadata['namespaces'])) {
      $output[] = "## Namespace Structure";
      $output[] = "";
      foreach ($metadata['namespaces'] as $ns => $types) {
        $total = count($types['classes']) + count($types['interfaces']) +
                 count($types['traits']) + count($types['enums']);
        if ($total > 0) {
          $output[] = "### `{$ns}`";
          if ($types['classes']) {
            $classes = implode(', ', array_map(fn($c) => "`{$c}`", $types['classes']));
            $output[] = "**Classes:** {$classes}";
          }
          if ($types['interfaces']) {
            $interfaces = implode(', ', array_map(fn($i) => "`{$i}`", $types['interfaces']));
            $output[] = "**Interfaces:** {$interfaces}";
          }
          if ($types['traits']) {
            $traits = implode(', ', array_map(fn($t) => "`{$t}`", $types['traits']));
            $output[] = "**Traits:** {$traits}";
          }
          if ($types['enums']) {
            $enums = implode(', ', array_map(fn($e) => "`{$e}`", $types['enums']));
            $output[] = "**Enums:** {$enums}";
          }
          $output[] = "";
        }
      }
      $output[] = "";
    }

    // Classes detail.
    if (!empty($metadata['classes'])) {
      $output[] = "## Classes";
      $output[] = "";
      $orderedClasses = $this->orderTypesByPriority(
        $metadata['classes'],
        $metadata['namespaces'] ?? [],
        $metadata['dependencies'] ?? []
      );
      foreach ($orderedClasses as $class) {
        $output = array_merge($output, $this->formatClass($class));
      }
    }

    // Interfaces.
    if (!empty($metadata['interfaces'])) {
      $output[] = "## Interfaces";
      $output[] = "";
      $orderedInterfaces = $this->orderTypesByPriority(
        $metadata['interfaces'],
        $metadata['namespaces'] ?? [],
        $metadata['dependencies'] ?? []
      );
      foreach ($orderedInterfaces as $interface) {
        $output = array_merge($output, $this->formatInterface($interface));
      }
    }

    // Traits.
    if (!empty($metadata['traits'])) {
      $output[] = "## Traits";
      $output[] = "";
      $orderedTraits = $this->orderTypesByPriority(
        $metadata['traits'],
        $metadata['namespaces'] ?? [],
        $metadata['dependencies'] ?? []
      );
      foreach ($orderedTraits as $trait) {
        $output = array_merge($output, $this->formatTrait($trait));
      }
    }

    // Enums.
    if (!empty($metadata['enums'])) {
      $output[] = "## Enums";
      $output[] = "";
      $orderedEnums = $this->orderTypesByPriority(
        $metadata['enums'],
        $metadata['namespaces'] ?? [],
        $metadata['dependencies'] ?? []
      );
      foreach ($orderedEnums as $enum) {
        $output = array_merge($output, $this->formatEnum($enum));
      }
    }

    // File list (optional).
    if ($this->options['files_analyzed'] && !empty($metadata['files'])) {
      $output[] = "";
      $output[] = "---";
      $output[] = "";
      $output[] = "## Files Analyzed";
      $output[] = "";
      foreach ($metadata['files'] as $file) {
        $output[] = "- `{$file}`";
      }
      $output[] = "";
    }

    return implode("\n", $output);
  }

  /**
   * Order types (classes/interfaces/traits/enums) by priority.
   *
   * Priority order:
   * 1. Items from Namespace Structure (all, namespace-grouped)
   * 2. Items from Key Dependencies (usage-sorted, limited by max_dependencies)
   *
   * @param array $types
   *   Array of type metadata (classes, interfaces, traits, or enums).
   * @param array $namespaces
   *   Namespace structure metadata.
   * @param array $dependencies
   *   Key dependencies array (FQCN => usages).
   *
   * @return array
   *   Reordered types array.
   */
  private function orderTypesByPriority(array $types, array $namespaces, array $dependencies): array {
    // Build FQCN lookup for types.
    $typesLookup = [];
    foreach ($types as $type) {
      $typesLookup[$type['fqcn']] = $type;
    }

    // Phase 1: Collect namespace structure FQCNs.
    $namespaceFqcns = [];
    foreach ($namespaces as $ns => $nsTypes) {
      foreach (['classes', 'interfaces', 'traits', 'enums'] as $typeKey) {
        if (!empty($nsTypes[$typeKey])) {
          foreach ($nsTypes[$typeKey] as $shortName) {
            $fqcn = $ns . '\\' . $shortName;
            $namespaceFqcns[$fqcn] = TRUE;
          }
        }
      }
    }

    // Phase 2: Get top Key Dependencies (limited by max_dependencies).
    $limit = $this->options['limits']['max_dependencies'];
    $topDependencies = array_slice($dependencies, 0, $limit, TRUE);

    // Phase 3: Build ordered output.
    $ordered = [];
    $processed = [];

    // First: Add namespace structure items (preserve current order).
    foreach ($types as $type) {
      if (isset($namespaceFqcns[$type['fqcn']])) {
        $ordered[] = $type;
        $processed[$type['fqcn']] = TRUE;
      }
    }

    // Second: Add Key Dependencies items (in dependency order).
    foreach ($topDependencies as $fqcn => $usages) {
      if (isset($typesLookup[$fqcn]) && !isset($processed[$fqcn])) {
        $ordered[] = $typesLookup[$fqcn];
        $processed[$fqcn] = TRUE;
      }
    }

    return $ordered;
  }

  /**
   * Formats constants and magic values section.
   *
   * @param array $metadata
   *   The metadata array.
   *
   * @return array
   *   The formatted output lines.
   */
  private function formatConstantsAndMagicValues(array $metadata): array {
    $output = [];
    $output[] = "## Constants & Magic Values";
    $output[] = "";

    // Collect all class constants.
    $allConstants = [];
    foreach ($metadata['classes'] as $class) {
      if (!empty($class['constants'])) {
        foreach ($class['constants'] as $const) {
          $allConstants[] = [
            'class' => $class['name'],
            'name' => $const['name'],
            'value' => $const['value'],
          ];
        }
      }
    }

    if ($this->options['magic_values']['class_constants'] && $allConstants) {
      $output[] = "### Class Constants";
      $limit = $this->options['limits']['max_class_constants'];
      foreach (array_slice($allConstants, 0, $limit) as $const) {
        $output[] = "- `{$const['class']}::{$const['name']}` = {$const['value']}";
      }
      if (count($allConstants) > $limit) {
        $remaining = count($allConstants) - $limit;
        $output[] = "- *... and {$remaining} more*";
      }
      $output[] = "";
    }

    // Collect array keys.
    $allArrayKeys = [];

    foreach ($metadata['classes'] as $class) {
      if (!empty($class['magic_values']['array_keys'])) {
        foreach ($class['magic_values']['array_keys'] as $key) {
          $allArrayKeys[$key][] = $class['name'];
        }
      }
    }

    if ($this->options['magic_values']['array_keys'] && $allArrayKeys) {
      $output[] = "### Common Array Keys";
      $limit = $this->options['limits']['max_array_keys'];
      $count = 0;
      foreach ($allArrayKeys as $key => $classes) {
        if ($count++ >= $limit) {
          break;
        }
        $classList = implode(', ', array_slice(array_unique($classes), 0, 3));
        if (count($classes) > 3) {
          $classList .= ', +' . (count($classes) - 3);
        }
        $output[] = "- `'{$key}'` (in: {$classList})";
      }
      if (count($allArrayKeys) > $limit) {
        $remaining = count($allArrayKeys) - $limit;
        $output[] = "- *... and {$remaining} more*";
      }
      $output[] = "";
    }

    $output[] = "";
    return $output;
  }

  /**
   * Sorts methods by visibility (public first, protected, then private).
   *
   * @param array $methods
   *   Array of method metadata.
   *
   * @return array
   *   Sorted methods array.
   */
  private function sortMethodsByVisibility(array $methods): array {
    $visibilityOrder = ['public' => 0, 'protected' => 1, 'private' => 2];
    usort($methods, function ($a, $b) use ($visibilityOrder) {
      $orderA = $visibilityOrder[$a['visibility']] ?? 3;
      $orderB = $visibilityOrder[$b['visibility']] ?? 3;
      return $orderA <=> $orderB;
    });
    return $methods;
  }

  /**
   * Formats a class definition.
   *
   * @param array $class
   *   The class metadata.
   *
   * @return array
   *   The formatted output lines.
   */
  private function formatClass(array $class): array {
    $output = [];

    $modifiers = [];
    if ($class['abstract']) {
      $modifiers[] = 'abstract';
    }
    if ($class['final']) {
      $modifiers[] = 'final';
    }

    $header = "### `{$class['name']}`";
    if ($modifiers) {
      $header .= " *(" . implode(', ', $modifiers) . ")*";
    }
    $output[] = $header;
    $output[] = "";

    // Docblock summary (sanitized).
    if (!empty($class['docblock']['summary'])) {
      $summary = $this->sanitizeMarkdown($class['docblock']['summary']);
      $output[] = "**Description:** {$summary}";
    }

    // Attributes.
    if (!empty($class['attributes'])) {
      $attrs = [];
      foreach ($class['attributes'] as $attr) {
        $attrStr = $attr['name'];
        if (!empty($attr['args'])) {
          $args = implode(', ', array_slice($attr['args'], 0, 3));
          $attrStr .= '(' . $args . ')';
        }
        $attrs[] = $attrStr;
      }
      $output[] = "**Attributes:** `" . implode('`, `', $attrs) . "`";
    }

    // Annotations from docblock (Drupal plugins).
    if (!empty($class['docblock']['annotations'])) {
      $importantAnnotations = array_intersect_key(
        $class['docblock']['annotations'],
        array_flip([
          'Plugin',
          'Block',
          'CommercePaymentGateway',
          'WebformElement',
          'FormElement',
          'Action',
          'Condition',
          'Entity',
          'ContentEntityType',
        ])
      );
      if ($importantAnnotations) {
        $output[] = "**Plugin Annotations:**";
        foreach ($importantAnnotations as $tag => $values) {
          foreach ($values as $value) {
            // Format multi-line annotations more readably.
            if (strlen($value) > 80 || strpos($value, '{') !== FALSE) {
              $output[] = "```php";
              $output[] = "@{$tag}(";
              $output[] = "  " . $value;
              $output[] = ")";
              $output[] = "```";
            }
            else {
              $output[] = "- `@{$tag} {$value}`";
            }
          }
        }
      }
    }

    $output[] = "**FQCN:** `{$class['fqcn']}`";

    if (!empty($class['extends'])) {
      $extends = implode('`, `', $class['extends']);
      $output[] = "**Extends:** `{$extends}`";
    }

    if (!empty($class['implements'])) {
      $implements = implode('`, `', $class['implements']);
      $output[] = "**Implements:** `{$implements}`";
    }

    if (!empty($class['traits_used'])) {
      $traits = implode('`, `', $class['traits_used']);
      $output[] = "**Uses Traits:** `{$traits}`";
    }

    // Show ALL type dependencies (no truncation).
    if (!empty($class['type_dependencies'])) {
      $deps = implode('`, `', $class['type_dependencies']);
      $output[] = "**Type Dependencies:** `{$deps}`";
    }

    // Constructor injection order.
    if (!empty($class['constructor_injection'])) {
      $injection = implode('` → `', $class['constructor_injection']);
      $output[] = "**Constructor Injection:** `{$injection}`";
    }

    // Public API surface with signatures.
    if ($this->options['class_details']['public_api'] && !empty($class['public_api'])) {
      $output[] = "**Public API:**";
      foreach ($class['methods'] as $method) {
        if (in_array($method['name'], $class['public_api'])) {
          $sig = $method['name'] . '(';
          if (!empty($method['params'])) {
            $params = array_map(function ($p) {
              return $p['type'] ?? 'mixed';
            }, $method['params']);
            $sig .= implode(', ', $params);
          }
          $sig .= ")";
          if (isset($method['return'])) {
            $sig .= ": " . $method['return'];
          }
          $output[] = "- `{$sig}`";
        }
      }
    }

    // Constants with values.
    if (!empty($class['constants'])) {
      $output[] = "**Constants:**";
      foreach ($class['constants'] as $const) {
        $line = "- `{$const['name']} = {$const['value']}`";
        if (!empty($const['docblock']['summary'])) {
          $summary = $this->sanitizeMarkdown($const['docblock']['summary']);
          $line .= " - {$summary}";
        }
        $output[] = $line;
      }
    }

    // Properties with defaults.
    if ($this->options['class_details']['properties'] && !empty($class['properties'])) {
      $output[] = "**Properties:**";
      $limit = $this->options['limits']['max_properties_per_class'];
      foreach (array_slice($class['properties'], 0, $limit) as $prop) {
        $line = "- `{$prop['visibility']}";
        if ($prop['static']) {
          $line .= " static";
        }
        if (isset($prop['type'])) {
          $line .= " {$prop['type']}";
        }
        $line .= " {$prop['name']}";
        if (isset($prop['default'])) {
          $line .= " = {$prop['default']}";
        }
        $line .= "`";
        if (!empty($prop['docblock']['summary'])) {
          $summary = $this->sanitizeMarkdown($prop['docblock']['summary']);
          $line .= " - {$summary}";
        }
        $output[] = $line;
      }
      if (count($class['properties']) > $limit) {
        $remaining = count($class['properties']) - $limit;
        $output[] = "- *... {$remaining} more*";
      }
    }

    // Methods.
    if ($this->options['class_details']['methods'] && !empty($class['methods'])) {
      $output[] = "**Methods:**";
      $maxMethods = $this->options['limits']['max_methods_per_class'];
      // Sort methods by visibility: public first, then protected, then private.
      $sortedMethods = $this->sortMethodsByVisibility($class['methods']);
      foreach (array_slice($sortedMethods, 0, $maxMethods) as $method) {
        $output = array_merge($output, $this->formatMethodSignature($method));
      }
      if (count($class['methods']) > $maxMethods) {
        $remaining = count($class['methods']) - $maxMethods;
        $output[] = "- *... {$remaining} more*";
      }
    }

    $output[] = "";
    return $output;
  }

  /**
   * Formats an interface definition.
   *
   * @param array $interface
   *   The interface metadata.
   *
   * @return array
   *   The formatted output lines.
   */
  private function formatInterface(array $interface): array {
    $output = [];
    $output[] = "### `{$interface['name']}`";
    $output[] = "";

    // Docblock summary (sanitized).
    if (!empty($interface['docblock']['summary'])) {
      $summary = $this->sanitizeMarkdown($interface['docblock']['summary']);
      $output[] = "**Description:** {$summary}";
    }

    $output[] = "**FQCN:** `{$interface['fqcn']}`";

    if (!empty($interface['extends'])) {
      $extends = implode('`, `', $interface['extends']);
      $output[] = "**Extends:** `{$extends}`";
    }

    if (!empty($interface['methods'])) {
      $output[] = "**Methods:**";
      foreach ($interface['methods'] as $method) {
        $output = array_merge($output, $this->formatMethodSignature($method));
      }
    }

    $output[] = "";
    $output[] = "";
    return $output;
  }

  /**
   * Formats a trait definition.
   *
   * @param array $trait
   *   The trait metadata.
   *
   * @return array
   *   The formatted output lines.
   */
  private function formatTrait(array $trait): array {
    $output = [];
    $output[] = "### `{$trait['name']}`";
    $output[] = "";
    $output[] = "**FQCN:** `{$trait['fqcn']}`";

    if (!empty($trait['methods'])) {
      $output[] = "**Methods:**";
      foreach ($trait['methods'] as $method) {
        $output = array_merge($output, $this->formatMethodSignature($method));
      }
    }

    $output[] = "";
    $output[] = "";
    return $output;
  }

  /**
   * Formats an enum definition.
   *
   * @param array $enum
   *   The enum metadata.
   *
   * @return array
   *   The formatted output lines.
   */
  private function formatEnum(array $enum): array {
    $output = [];
    $output[] = "### `{$enum['name']}`";
    $output[] = "";
    $output[] = "**FQCN:** `{$enum['fqcn']}`";

    if (isset($enum['type'])) {
      $output[] = "**Type:** `{$enum['type']}`";
    }

    if (!empty($enum['implements'])) {
      $implements = implode('`, `', $enum['implements']);
      $output[] = "**Implements:** `{$implements}`";
    }

    if (!empty($enum['cases'])) {
      $cases = implode('`, `', $enum['cases']);
      $output[] = "**Cases:** `{$cases}`";
    }

    $output[] = "";
    $output[] = "";
    return $output;
  }

  /**
   * Formats a method signature with documentation and patterns.
   *
   * @param array $method
   *   The method metadata.
   *
   * @return array
   *   The formatted output lines.
   */
  private function formatMethodSignature(array $method): array {
    $output = [];

    $line = "- `{$method['visibility']}";

    if ($method['static']) {
      $line .= " static";
    }
    if ($method['abstract']) {
      $line .= " abstract";
    }
    if ($method['final']) {
      $line .= " final";
    }

    $line .= " {$method['name']}(";

    if ($this->options['method_details']['parameters'] && !empty($method['params'])) {
      $params = [];
      foreach ($method['params'] as $param) {
        $p = '';
        // Always show type, if set.
        if (!empty($param['type'])) {
          $p .= $param['type'] . ' ';
        }
        $p .= $param['name'];
        if (isset($param['default'])) {
          $p .= ' = ' . $param['default'];
        }
        if (isset($param['variadic'])) {
          $p = '...' . $p;
        }
        $params[] = $p;
      }
      $line .= implode(', ', $params);
    }

    $line .= ")";

    if ($this->options['method_details']['return_type'] && isset($method['return'])) {
      $line .= ": {$method['return']}";
    }

    $line .= "`";
    $output[] = $line;

    // Docblock summary (sanitized).
    if ($this->options['method_details']['docblock_summary'] && !empty($method['docblock']['summary'])) {
      $summary = $this->sanitizeMarkdown($method['docblock']['summary']);
      // Filter out inheritdoc placeholders (provide no useful information).
      if ($summary !== '{@inheritdoc}' && $summary !== '@inheritdoc') {
        $output[] = "  *{$summary}*";
      }
    }

    // Attributes.
    if ($this->options['method_details']['attributes'] && !empty($method['attributes'])) {
      $attrs = [];
      foreach ($method['attributes'] as $attr) {
        $attrStr = '@' . $attr['name'];
        if (!empty($attr['args'])) {
          $args = implode(', ', array_slice($attr['args'], 0, 2));
          $attrStr .= '(' . $args . ')';
        }
        $attrs[] = $attrStr;
      }
      $output[] = "  `" . implode(' ', $attrs) . "`";
    }

    // Body patterns.
    if ($this->options['method_details']['body_patterns'] && !empty($method['body_patterns'])) {
      // Service calls.
      if ($this->options['body_patterns']['service_calls'] && !empty($method['body_patterns']['service_calls'])) {
        foreach ($method['body_patterns']['service_calls'] as $service => $calls) {
          // Group and count identical calls.
          $callCounts = array_count_values($calls);

          // Sort by count descending.
          arsort($callCounts);

          // Apply limit.
          $limit = $this->options['limits']['max_body_patterns_service_calls'];
          $limitedCalls = array_slice($callCounts, 0, $limit, TRUE);

          // Format as "N* method()".
          $formattedCalls = [];
          foreach ($limitedCalls as $call => $count) {
            if ($count > 1) {
              $formattedCalls[] = "{$count}× {$call}";
            }
            else {
              $formattedCalls[] = $call;
            }
          }

          $callsLine = implode(', ', $formattedCalls);
          if (count($callCounts) > $limit) {
            $remaining = count($callCounts) - $limit;
            $callsLine .= ', +' . $remaining;
          }

          $output[] = "  → `\$this->{$service}`: {$callsLine}";
        }
      }

      // Throws.
      if ($this->options['body_patterns']['throws'] && !empty($method['body_patterns']['throws'])) {
        $throws = implode('`, `', $method['body_patterns']['throws']);
        $output[] = "  ⚠ Throws: `{$throws}`";
      }

      // Control flow.
      if ($this->options['body_patterns']['control_flow'] && !empty($method['body_patterns']['control_flow'])) {
        $control = implode(', ', $method['body_patterns']['control_flow']);
        $output[] = "  ⚙ Control: {$control}";
      }

      // Returns.
      if ($this->options['body_patterns']['returns'] && !empty($method['body_patterns']['returns'])) {
        $returns = implode('`, `', $method['body_patterns']['returns']);
        $output[] = "  ← Returns: `{$returns}`";
      }
    }

    return $output;
  }

}
