<?php

/**
 * @file
 * PHPContext config.
 */

// Set resource limits.
ini_set('memory_limit', '512M');
set_time_limit(300);  // 5 minutes

// Configuration.
$config = [
  'directory' => $argv[1] ?? '.',
  'output' => $argv[2] ?? 'context/CONTEXT.md',
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
    // Top-level sections.
    'summary' => TRUE,
    'architecture_patterns' => TRUE,
    'constants_magic_values' => TRUE,
    'key_dependencies' => TRUE,
    'namespace_structure' => TRUE,
    'files_analyzed' => FALSE,

    // Constants & Magic Values subsections.
    'magic_values' => [
      'class_constants' => TRUE,
      'array_keys' => TRUE,
    ],

    // Class-level details.
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

    // Method-level details.
    'method_details' => [
      'docblock_summary' => TRUE,
      'attributes' => TRUE,
      'parameters' => TRUE,
      'return_type' => TRUE,
      'body_patterns' => TRUE,
    ],

    // Method body patterns.
    'body_patterns' => [
      'service_calls' => TRUE,
      'throws' => TRUE,
      'control_flow' => TRUE,
      'returns' => TRUE,
    ],

    // Property details.
    'property_details' => [
      'docblock_summary' => TRUE,
      'default_values' => TRUE,
    ],

    // Limits.
    'limits' => [
      'max_methods_per_class' => 100,
      'max_properties_per_class' => 50,
      'max_pattern_classes' => 25,
      'max_dependencies' => 50,
      'max_class_constants' => 25,
      'max_array_keys' => 25,
      'max_body_patterns_service_calls' => 10,
    ],
  ],
];
