<?php

namespace ContextExtractor;

class ContextFormatter
{
    private array $options;

    public function __construct(array $options = [])
    {
        // Default options
        $defaults = [
            'summary' => true,
            'architecture_patterns' => true,
            'constants_magic_values' => true,
            'key_dependencies' => true,
            'namespace_structure' => true,
            'files_analyzed' => false,
            'magic_values' => [
                'class_constants' => true,
                'magic_strings' => true,
                'magic_numbers' => true,
                'array_keys' => true,
            ],
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
            'method_details' => [
                'docblock_summary' => true,
                'attributes' => true,
                'parameters' => true,
                'return_type' => true,
                'body_patterns' => true,
            ],
            'body_patterns' => [
                'service_calls' => true,
                'throws' => true,
                'control_flow' => true,
                'returns' => true,
            ],
            'property_details' => [
                'docblock_summary' => true,
                'default_values' => true,
            ],
            'limits' => [
                'max_methods_per_class' => PHP_INT_MAX,
                'max_properties_per_class' => 15,
                'max_pattern_classes' => 20,
                'max_dependencies' => 15,
                'max_magic_strings' => 15,
                'max_magic_numbers' => 10,
                'max_array_keys' => 15,
                'max_body_patterns_service_calls' => 10,
            ],
        ];
        
        $this->options = array_replace_recursive($defaults, $options);
    }

    public function format(array $metadata): string
    {
        $output = [];
        
        // Header
        $output[] = "# Code Context Reference";
        $output[] = "";
        $output[] = "**Generated:** " . date('Y-m-d H:i:s');
        $output[] = "**Files Analyzed:** {$metadata['summary']['files_analyzed']}";
        $output[] = "";
        
        // Summary
        if ($this->options['summary']) {
            $output[] = "## Summary";
            $output[] = "";
            $output[] = "- **Classes:** {$metadata['summary']['classes']}";
            $output[] = "- **Interfaces:** {$metadata['summary']['interfaces']}";
            $output[] = "- **Traits:** {$metadata['summary']['traits']}";
            $output[] = "- **Enums:** {$metadata['summary']['enums']}";
            $output[] = "";
            $output[] = "";
        }
        
        // Architecture patterns
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
                    $output[] = "- *... and " . (count($classes) - $limit) . " more*";
                }
                $output[] = "";
            }
            $output[] = "";
        }
        
        // Constants & Magic Values
        if ($this->options['constants_magic_values']) {
            $output = array_merge($output, $this->formatConstantsAndMagicValues($metadata));
        }
        
        // Key dependencies
        if ($this->options['key_dependencies'] && !empty($metadata['dependencies'])) {
            $output[] = "## Key Dependencies";
            $output[] = "";
            $limit = $this->options['limits']['max_dependencies'];
            $topDeps = array_slice($metadata['dependencies'], 0, $limit);
            foreach ($topDeps as $dep => $usages) {
                $count = count($usages);
                $output[] = "- `{$dep}` ({$count} usage" . ($count > 1 ? 's' : '') . ")";
            }
            $output[] = "";
            $output[] = "";
        }
        
        // Namespace overview
        if ($this->options['namespace_structure'] && !empty($metadata['namespaces'])) {
            $output[] = "## Namespace Structure";
            $output[] = "";
            foreach ($metadata['namespaces'] as $ns => $types) {
                $total = count($types['classes']) + count($types['interfaces']) + 
                         count($types['traits']) + count($types['enums']);
                if ($total > 0) {
                    $output[] = "### `{$ns}`";
                    if ($types['classes']) {
                        $output[] = "**Classes:** " . implode(', ', array_map(fn($c) => "`{$c}`", $types['classes']));
                    }
                    if ($types['interfaces']) {
                        $output[] = "**Interfaces:** " . implode(', ', array_map(fn($i) => "`{$i}`", $types['interfaces']));
                    }
                    if ($types['traits']) {
                        $output[] = "**Traits:** " . implode(', ', array_map(fn($t) => "`{$t}`", $types['traits']));
                    }
                    if ($types['enums']) {
                        $output[] = "**Enums:** " . implode(', ', array_map(fn($e) => "`{$e}`", $types['enums']));
                    }
                    $output[] = "";
                }
            }
            $output[] = "";
        }
        
        // Classes detail
        if (!empty($metadata['classes'])) {
            $output[] = "## Classes";
            $output[] = "";
            foreach ($metadata['classes'] as $class) {
                $output = array_merge($output, $this->formatClass($class));
            }
        }
        
        // Interfaces
        if (!empty($metadata['interfaces'])) {
            $output[] = "## Interfaces";
            $output[] = "";
            foreach ($metadata['interfaces'] as $interface) {
                $output = array_merge($output, $this->formatInterface($interface));
            }
        }
        
        // Traits
        if (!empty($metadata['traits'])) {
            $output[] = "## Traits";
            $output[] = "";
            foreach ($metadata['traits'] as $trait) {
                $output = array_merge($output, $this->formatTrait($trait));
            }
        }
        
        // Enums
        if (!empty($metadata['enums'])) {
            $output[] = "## Enums";
            $output[] = "";
            foreach ($metadata['enums'] as $enum) {
                $output = array_merge($output, $this->formatEnum($enum));
            }
        }
        
        // File list (optional)
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

    private function formatConstantsAndMagicValues(array $metadata): array
    {
        $output = [];
        $output[] = "## Constants & Magic Values";
        $output[] = "";
        
        // Collect all class constants
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
            foreach (array_slice($allConstants, 0, 20) as $const) {
                $output[] = "- `{$const['class']}::{$const['name']}` = {$const['value']}";
            }
            if (count($allConstants) > 20) {
                $output[] = "- *... and " . (count($allConstants) - 20) . " more*";
            }
            $output[] = "";
        }
        
        // Collect magic values
        $allMagicStrings = [];
        $allMagicNumbers = [];
        $allArrayKeys = [];
        
        foreach ($metadata['classes'] as $class) {
            if (!empty($class['magic_values']['strings'])) {
                foreach ($class['magic_values']['strings'] as $str) {
                    $allMagicStrings[$str][] = $class['name'];
                }
            }
            if (!empty($class['magic_values']['numbers'])) {
                foreach ($class['magic_values']['numbers'] as $num) {
                    $allMagicNumbers[$num][] = $class['name'];
                }
            }
            if (!empty($class['magic_values']['array_keys'])) {
                foreach ($class['magic_values']['array_keys'] as $key) {
                    $allArrayKeys[$key][] = $class['name'];
                }
            }
        }
        
        if ($this->options['magic_values']['magic_strings'] && $allMagicStrings) {
            $output[] = "### Magic Strings";
            $limit = $this->options['limits']['max_magic_strings'];
            $count = 0;
            foreach ($allMagicStrings as $str => $classes) {
                if ($count++ >= $limit) break;
                $classList = implode(', ', array_slice(array_unique($classes), 0, 3));
                if (count($classes) > 3) {
                    $classList .= ', +' . (count($classes) - 3);
                }
                $output[] = "- `\"{$str}\"` (in: {$classList})";
            }
            $output[] = "";
        }
        
        if ($this->options['magic_values']['magic_numbers'] && $allMagicNumbers) {
            $output[] = "### Magic Numbers";
            $limit = $this->options['limits']['max_magic_numbers'];
            $count = 0;
            foreach ($allMagicNumbers as $num => $classes) {
                if ($count++ >= $limit) break;
                $classList = implode(', ', array_slice(array_unique($classes), 0, 3));
                if (count($classes) > 3) {
                    $classList .= ', +' . (count($classes) - 3);
                }
                $output[] = "- `{$num}` (in: {$classList})";
            }
            $output[] = "";
        }
        
        if ($this->options['magic_values']['array_keys'] && $allArrayKeys) {
            $output[] = "### Common Array Keys";
            $limit = $this->options['limits']['max_array_keys'];
            $count = 0;
            foreach ($allArrayKeys as $key => $classes) {
                if ($count++ >= $limit) break;
                $classList = implode(', ', array_slice(array_unique($classes), 0, 3));
                if (count($classes) > 3) {
                    $classList .= ', +' . (count($classes) - 3);
                }
                $output[] = "- `'{$key}'` (in: {$classList})";
            }
            $output[] = "";
        }
        
        $output[] = "";
        return $output;
    }

    private function formatClass(array $class): array
    {
        $output = [];
        
        $modifiers = [];
        if ($class['abstract']) $modifiers[] = 'abstract';
        if ($class['final']) $modifiers[] = 'final';
        
        $header = "### `{$class['name']}`";
        if ($modifiers) {
            $header .= " *(" . implode(', ', $modifiers) . ")*";
        }
        $output[] = $header;
        $output[] = "";
        
        // Docblock summary
        if (!empty($class['docblock']['summary'])) {
            $output[] = "**Description:** " . $class['docblock']['summary'];
        }
        
        // Attributes
        if (!empty($class['attributes'])) {
            $attrs = [];
            foreach ($class['attributes'] as $attr) {
                $attrStr = $attr['name'];
                if (!empty($attr['args'])) {
                    $attrStr .= '(' . implode(', ', array_slice($attr['args'], 0, 3)) . ')';
                }
                $attrs[] = $attrStr;
            }
            $output[] = "**Attributes:** `" . implode('`, `', $attrs) . "`";
        }
        
        // Annotations from docblock (Drupal plugins)
        if (!empty($class['docblock']['annotations'])) {
            $importantAnnotations = array_intersect_key(
                $class['docblock']['annotations'],
                array_flip(['Plugin', 'Block', 'CommercePaymentGateway', 'WebformElement', 
                           'FormElement', 'Action', 'Condition', 'Entity', 'ContentEntityType'])
            );
            if ($importantAnnotations) {
                $output[] = "**Plugin Annotations:**";
                foreach ($importantAnnotations as $tag => $values) {
                    foreach ($values as $value) {
                        // Format multi-line annotations more readably
                        if (strlen($value) > 80 || strpos($value, '{') !== false) {
                            $output[] = "```php";
                            $output[] = "@{$tag}(";
                            $output[] = "  " . $value;
                            $output[] = ")";
                            $output[] = "```";
                        } else {
                            $output[] = "- `@{$tag} {$value}`";
                        }
                    }
                }
            }
        }
        
        $output[] = "**FQCN:** `{$class['fqcn']}`";
        
        if (!empty($class['extends'])) {
            $output[] = "**Extends:** `" . implode('`, `', $class['extends']) . "`";
        }
        
        if (!empty($class['implements'])) {
            $output[] = "**Implements:** `" . implode('`, `', $class['implements']) . "`";
        }
        
        if (!empty($class['traits_used'])) {
            $output[] = "**Uses Traits:** `" . implode('`, `', $class['traits_used']) . "`";
        }
        
        // Show ALL type dependencies (no truncation)
        if (!empty($class['type_dependencies'])) {
            $output[] = "**Type Dependencies:** `" . implode('`, `', $class['type_dependencies']) . "`";
        }
        
        // Constructor injection order
        if (!empty($class['constructor_injection'])) {
            $output[] = "**Constructor Injection:** `" . implode('` → `', $class['constructor_injection']) . "`";
        }
        
        // Public API surface with signatures
        if (!empty($class['public_api'])) {
            $output[] = "**Public API:**";
            foreach ($class['methods'] as $method) {
                if (in_array($method['name'], $class['public_api'])) {
                    $sig = $method['name'] . '(';
                    if (!empty($method['params'])) {
                        $params = array_map(function($p) {
                            return (isset($p['type']) ? $p['type'] : 'mixed');
                        }, $method['params']);
                        $sig .= implode(', ', $params);
                    }
                    $sig .= ')';
                    if (isset($method['return'])) {
                        $sig .= ': ' . $method['return'];
                    }
                    $output[] = "- `{$sig}`";
                }
            }
        }
        
        // Constants with values
        if (!empty($class['constants'])) {
            $output[] = "**Constants:**";
            foreach ($class['constants'] as $const) {
                $line = "- `{$const['name']} = {$const['value']}`";
                if (!empty($const['docblock']['summary'])) {
                    $line .= " - {$const['docblock']['summary']}";
                }
                $output[] = $line;
            }
        }
        
        // Properties with defaults
        if ($this->options['class_details']['properties'] && !empty($class['properties'])) {
            $output[] = "**Properties:**";
            $limit = $this->options['limits']['max_properties_per_class'];
            foreach (array_slice($class['properties'], 0, $limit) as $prop) {
                $line = "- `{$prop['visibility']}";
                if ($prop['static']) $line .= " static";
                if (isset($prop['type'])) $line .= " {$prop['type']}";
                $line .= " {$prop['name']}";
                if (isset($prop['default'])) {
                    $line .= " = {$prop['default']}";
                }
                $line .= "`";
                if (!empty($prop['docblock']['summary'])) {
                    $line .= " - {$prop['docblock']['summary']}";
                }
                $output[] = $line;
            }
            if (count($class['properties']) > $limit) {
                $output[] = "- *... " . (count($class['properties']) - $limit) . " more*";
            }
        }
        
        // Methods
        if ($this->options['class_details']['methods'] && !empty($class['methods'])) {
            $output[] = "**Methods:**";
            $maxMethods = $this->options['limits']['max_methods_per_class'];
            foreach (array_slice($class['methods'], 0, $maxMethods) as $method) {
                $output = array_merge($output, $this->formatMethodSignature($method));
            }
            if (count($class['methods']) > $maxMethods) {
                $output[] = "- *... " . (count($class['methods']) - $maxMethods) . " more*";
            }
        }
        
        $output[] = "";
        return $output;
    }

    private function formatInterface(array $interface): array
    {
        $output = [];
        $output[] = "### `{$interface['name']}`";
        $output[] = "";
        
        // Docblock summary
        if (!empty($interface['docblock']['summary'])) {
            $output[] = "**Description:** " . $interface['docblock']['summary'];
        }
        
        $output[] = "**FQCN:** `{$interface['fqcn']}`";
        
        if (!empty($interface['extends'])) {
            $output[] = "**Extends:** `" . implode('`, `', $interface['extends']) . "`";
        }
        
        if (!empty($interface['methods'])) {
            $output[] = "";
            $output[] = "**Methods:**";
            foreach ($interface['methods'] as $method) {
                $output = array_merge($output, $this->formatMethodSignature($method));
            }
        }
        
        $output[] = "";
        $output[] = "";
        return $output;
    }

    private function formatTrait(array $trait): array
    {
        $output = [];
        $output[] = "### `{$trait['name']}`";
        $output[] = "";
        $output[] = "**FQCN:** `{$trait['fqcn']}`";
        
        if (!empty($trait['methods'])) {
            $output[] = "";
            $output[] = "**Methods:**";
            foreach ($trait['methods'] as $method) {
                $output = array_merge($output, $this->formatMethodSignature($method));
            }
        }
        
        $output[] = "";
        $output[] = "";
        return $output;
    }

    private function formatEnum(array $enum): array
    {
        $output = [];
        $output[] = "### `{$enum['name']}`";
        $output[] = "";
        $output[] = "**FQCN:** `{$enum['fqcn']}`";
        
        if (isset($enum['type'])) {
            $output[] = "**Type:** `{$enum['type']}`";
        }
        
        if (!empty($enum['implements'])) {
            $output[] = "**Implements:** `" . implode('`, `', $enum['implements']) . "`";
        }
        
        if (!empty($enum['cases'])) {
            $output[] = "**Cases:** `" . implode('`, `', $enum['cases']) . "`";
        }
        
        $output[] = "";
        $output[] = "";
        return $output;
    }

    private function formatMethodSignature(array $method): array
    {
        $output = [];
        
        $line = "- `{$method['visibility']}";
        
        if ($method['static']) $line .= " static";
        if ($method['abstract']) $line .= " abstract";
        if ($method['final']) $line .= " final";
        
        $line .= " {$method['name']}(";
        
        if ($this->options['method_details']['parameters'] && !empty($method['params'])) {
            $params = [];
            foreach ($method['params'] as $param) {
                $p = '';
                if (isset($param['type'])) $p .= $param['type'] . ' ';
                $p .= $param['name'];
                if (isset($param['default'])) $p .= ' = ' . $param['default'];
                if (isset($param['variadic'])) $p = '...' . $p;
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
        
        // Docblock summary
        if ($this->options['method_details']['docblock_summary'] && !empty($method['docblock']['summary'])) {
            $output[] = "  *{$method['docblock']['summary']}*";
        }
        
        // Attributes
        if ($this->options['method_details']['attributes'] && !empty($method['attributes'])) {
            $attrs = [];
            foreach ($method['attributes'] as $attr) {
                $attrStr = '@' . $attr['name'];
                if (!empty($attr['args'])) {
                    $attrStr .= '(' . implode(', ', array_slice($attr['args'], 0, 2)) . ')';
                }
                $attrs[] = $attrStr;
            }
            $output[] = "  `" . implode(' ', $attrs) . "`";
        }
        
        // Body patterns
        if ($this->options['method_details']['body_patterns'] && !empty($method['body_patterns'])) {
            // Service calls
            if ($this->options['body_patterns']['service_calls'] && !empty($method['body_patterns']['service_calls'])) {
                foreach ($method['body_patterns']['service_calls'] as $service => $calls) {
                    // Group and count identical calls
                    $callCounts = array_count_values($calls);

                    // Sort by count descending
                    arsort($callCounts);

                    // Apply limit
                    $limit = $this->options['limits']['max_body_patterns_service_calls'];
                    $limitedCalls = array_slice($callCounts, 0, $limit, true);

                    // Format as "N* method()"
                    $formattedCalls = [];
                    foreach ($limitedCalls as $call => $count) {
                        if ($count > 1) {
                            $formattedCalls[] = "{$count}× {$call}";
                        } else {
                            $formattedCalls[] = $call;
                        }
                    }

                    $callsLine = implode(', ', $formattedCalls);
                    if (count($callCounts) > $limit) {
                        $callsLine .= ', ...';
                    }

                    $output[] = "  → `\$this->{$service}`: {$callsLine}";
                }
            }
            
            // Throws
            if ($this->options['body_patterns']['throws'] && !empty($method['body_patterns']['throws'])) {
                $output[] = "  ⚠ Throws: `" . implode('`, `', $method['body_patterns']['throws']) . "`";
            }
            
            // Control flow
            if ($this->options['body_patterns']['control_flow'] && !empty($method['body_patterns']['control_flow'])) {
                $output[] = "  ⚙ Control: " . implode(', ', $method['body_patterns']['control_flow']);
            }
            
            // Returns
            if ($this->options['body_patterns']['returns'] && !empty($method['body_patterns']['returns'])) {
                $output[] = "  ← Returns: `" . implode('`, `', $method['body_patterns']['returns']) . "`";
            }
        }
        
        return $output;
    }
}
