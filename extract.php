#!/usr/bin/env php
<?php

/**
 * @file
 * PHP code metadata extractor for AI context.
 */
 
// Find autoloader from main project
$autoloadPaths = [
    __DIR__ . '/../../vendor/autoload.php',     // scripts/context-extractor/
    __DIR__ . '/vendor/autoload.php',           // standalone with own composer
];

$autoloadFound = false;
foreach ($autoloadPaths as $file) {
    if (file_exists($file)) {
        require_once $file;
        $autoloadFound = true;
        break;
    }
}

if (!$autoloadFound) {
    fwrite(STDERR, "Error: Composer autoloader not found.\n");
    fwrite(STDERR, "Run 'composer require nikic/php-parser --dev' in your main project.\n");
    exit(1);
}

// Load context-extractor classes
foreach (glob(__DIR__ . '/src/*.php') as $file) {
    require_once $file;
}

use ContextExtractor\MetadataExtractor;
use ContextExtractor\ContextFormatter;

// Configuration
$config = [
    'directory' => $argv[1] ?? getcwd(),
    'output' => 'context/' . $argv[2] ?? 'context/CONTEXT.md',
    'exclude' => [
        'vendor/',
        'node_modules/',
        'tests/',
        'Test/',
        '.git/',
        'var/',
        'cache/',
        'tmp',
        'private',
        'scripts',
        'Migrations/',
    ],
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
            'max_class_constants' => 20,
            'max_magic_strings' => 15,
            'max_magic_numbers' => 10,
            'max_array_keys' => 15,
            'max_body_patterns_service_calls' => 10,
        ],
    ],
];

// CLI arguments parsing
for ($i = 3; $i < $argc; $i++) {
    if ($argv[$i] === '--full' || $argv[$i] === '--no-limits') {
        // Override all limits to show everything
        $config['options']['limits'] = [
            'max_methods_per_class' => PHP_INT_MAX,
            'max_properties_per_class' => PHP_INT_MAX,
            'max_pattern_classes' => PHP_INT_MAX,
            'max_dependencies' => PHP_INT_MAX,
            'max_class_constants' => PHP_INT_MAX,
            'max_magic_strings' => PHP_INT_MAX,
            'max_magic_numbers' => PHP_INT_MAX,
            'max_array_keys' => PHP_INT_MAX,
            'max_body_patterns_service_calls' => PHP_INT_MAX,
        ];
        $config['options']['files_analyzed'] = true;
    } elseif (str_starts_with($argv[$i], '--exclude=')) {
        $config['exclude'][] = substr($argv[$i], 10);
    } elseif ($argv[$i] === '--minimal') {
        // Minimal context for very large projects
        $config['options']['architecture_patterns'] = false;
        $config['options']['constants_magic_values'] = false;
        $config['options']['class_details']['properties'] = false;
        $config['options']['method_details']['body_patterns'] = false;
        $config['options']['limits']['max_methods_per_class'] = 10;
    }
}

// Display configuration
echo "Context Extractor\n";
echo "=================\n\n";
echo "Scanning: {$config['directory']}\n";
echo "Output: {$config['output']}\n";
echo "Excludes: " . implode(', ', $config['exclude']) . "\n\n";

// Extract metadata
echo "Analyzing PHP files...\n";
$extractor = new MetadataExtractor();
$metadata = $extractor->extractFromDirectory($config['directory'], $config['exclude']);

echo "Files analyzed: {$metadata['summary']['files_analyzed']}\n";
echo "Classes found: {$metadata['summary']['classes']}\n";
echo "Interfaces found: {$metadata['summary']['interfaces']}\n";
echo "Traits found: {$metadata['summary']['traits']}\n";
echo "Enums found: {$metadata['summary']['enums']}\n\n";

// Format and write output
echo "Generating CONTEXT.md...\n";
$formatter = new ContextFormatter($config['options']);
$contextMd = $formatter->format($metadata);

file_put_contents($config['output'], $contextMd);

echo "✓ Context generated: {$config['output']}\n";
echo "  Size: " . number_format(strlen($contextMd)) . " bytes\n";
echo "  Tokens: ~" . number_format(strlen($contextMd) / 4) . " (estimated)\n";
