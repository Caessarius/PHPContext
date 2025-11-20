#!/usr/bin/env php
<?php

/**
 * @file
 * PHP code metadata extractor for AI context.
 *
 * SECURITY: This tool extracts sensitive code information.
 * Use with caution and review output before sharing.
 */

include 'config.php';

// Find autoloader from main project.
// scripts/phpcontext || standalone with own composer.
$autoloadPaths = [
  __DIR__ . '/../../vendor/autoload.php',
  __DIR__ . '/vendor/autoload.php',
];

$autoloadFound = FALSE;
foreach ($autoloadPaths as $file) {
  $realAutoloadPath = realpath($file);
  if ($realAutoloadPath && file_exists($realAutoloadPath)) {
    require_once $realAutoloadPath;
    $autoloadFound = TRUE;
    break;
  }
}

if (!$autoloadFound) {
  fwrite(STDERR, "Error: Composer autoloader not found.\n");
  fwrite(STDERR, "Run 'composer require nikic/php-parser --dev' in your main project.\n");
  exit(1);
}

// Load context-extractor classes.
foreach (glob(__DIR__ . '/src/*.php') as $file) {
  require_once $file;
}

use ContextExtractor\MetadataExtractor;
use ContextExtractor\ContextFormatter;

// Check for help flag first (can be at any position)
for ($i = 1; $i < $argc; $i++) {
  if ($argv[$i] === '--help' || $argv[$i] === '-h') {
    echo "PHPContext - AI Code Context Extractor\n\n";
    echo "Usage: extract.php [directory] [output_file] [options]\n\n";
    echo "Arguments:\n";
    echo "  directory      Source directory to scan (relative path, default: .)\n";
    echo "  output_file    Output file path (relative path, default: context/CONTEXT.md)\n\n";
    echo "Options:\n";
    echo "  --full                   Remove most limits for detailed output\n";
    echo "  --minimal                Minimal output for large projects\n";
    echo "  --exclude=<path>         Add exclusion path (can be repeated)\n";
    echo "  --suppress-warnings      Suppress security warnings\n";
    echo "  --help, -h               Show this help message\n\n";
    echo "Security:\n";
    echo "  - Only relative paths allowed (no absolute paths)\n";
    echo "  - Paths must be within project root\n";
    echo "  - Output contains sensitive information - review before sharing\n\n";
    echo "Examples:\n";
    echo "  extract.php src CONTEXT.md\n";
    echo "  extract.php . output/context.md --full\n";
    echo "  extract.php src context.md --exclude=Migrations/ --exclude=Tests/\n";
    exit(0);
  }
}

// CLI arguments parsing.
$suppressWarnings = FALSE;
for ($i = 3; $i < $argc; $i++) {
  if ($argv[$i] === '--full' || $argv[$i] === '--no-limits') {
    // Override limits with high but reasonable values (not infinite)
    $config['options']['limits'] = [
      'max_methods_per_class' => 1000,
      'max_properties_per_class' => 500,
      'max_pattern_classes' => 200,
      'max_dependencies' => 100,
      'max_class_constants' => 200,
      'max_array_keys' => 100,
      'max_body_patterns_service_calls' => 50,
    ];
  }
  elseif (str_starts_with($argv[$i], '--exclude=')) {
    $config['exclude'][] = substr($argv[$i], 10);
  }
  elseif ($argv[$i] === '--minimal') {
    // Minimal context for very large projects.
    $config['options']['architecture_patterns'] = FALSE;
    $config['options']['constants_magic_values'] = FALSE;
    $config['options']['class_details']['properties'] = FALSE;
    $config['options']['method_details']['body_patterns'] = FALSE;
    $config['options']['limits']['max_methods_per_class'] = 10;
  }
  elseif ($argv[$i] === '--suppress-warnings') {
    // Suppress security warnings.
    $suppressWarnings = TRUE;
  }
}

// Validate paths.
$config['directory'] = validateAndNormalizePath($config['directory']);
$config['output'] = validateOutputPath($config['output']);

// Display configuration.
echo "Context Extractor\n";
echo "=================\n\n";
echo "Scanning: {$config['directory']}\n";
echo "Output: {$config['output']}\n";
echo "Excludes: " . implode(', ', $config['exclude']) . "\n\n";

// Extract metadata.
echo "Analyzing PHP files...\n";
$extractor = new MetadataExtractor();
$metadata = $extractor->extractFromDirectory($config['directory'], $config['exclude']);

echo "Files analyzed: {$metadata['summary']['files_analyzed']}\n";
echo "Classes found: {$metadata['summary']['classes']}\n";
echo "Interfaces found: {$metadata['summary']['interfaces']}\n";
echo "Traits found: {$metadata['summary']['traits']}\n";
echo "Enums found: {$metadata['summary']['enums']}\n\n";

// Convert absolute paths to relative paths for output.
// Store project root for relative path conversion.
$projectRoot = getcwd();
if (!empty($metadata['files'])) {
  $metadata['files'] = array_map(function ($file) use ($projectRoot) {
    $relativePath = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $file);
    // Normalize directory separators to forward slashes for consistency.
    return str_replace('\\', '/', $relativePath);
  }, $metadata['files']);
}

// Format and write output.
echo "Generating CONTEXT.md...\n";
$formatter = new ContextFormatter($config['options']);
$contextMd = $formatter->format($metadata);

// Check output size.
$outputSizeMB = strlen($contextMd) / 1024 / 1024;
if ($outputSizeMB > 50) {
  fwrite(STDERR, "\nWarning: Output file is very large (" . number_format($outputSizeMB, 2) . "MB)\n");
  fwrite(STDERR, "Consider using --minimal flag or custom exclusions.\n\n");
}

file_put_contents($config['output'], $contextMd);

// Set restrictive permissions on output file.
chmod($config['output'], 0644);

echo "✓ Context generated: {$config['output']}\n";
echo "  Size: " . number_format(strlen($contextMd)) . " bytes (" . number_format($outputSizeMB, 2) . " MB)\n";
echo "  Tokens: ~" . number_format(strlen($contextMd) / 4) . " (estimated)\n";

// Security warnings.
if (!$suppressWarnings) {
  echo "\n";
  echo "┌─────────────────────────────────────────────────────────────┐\n";
  echo "│                   ⚠️  SECURITY WARNING ⚠️                     │\n";
  echo "├─────────────────────────────────────────────────────────────┤\n";
  echo "│ CONTEXT.md contains SENSITIVE information:                  │\n";
  echo "│  • Internal file paths and directory structure              │\n";
  echo "│  • Complete class names and architecture details            │\n";
  echo "│  • Dependency information and versions                      │\n";
  echo "│  • Array keys and configuration patterns                    │\n";
  echo "│  • Public API surface and method signatures                 │\n";
  echo "│                                                             │\n";
  echo "│ ❌ DO NOT:                                                  │\n";
  echo "│  • Commit to public repositories                            │\n";
  echo "│  • Share with untrusted parties                             │\n";
  echo "│  • Upload to public AI services without review              │\n";
  echo "│  • Include in public documentation                          │\n";
  echo "│                                                             │\n";
  echo "│ ✅ RECOMMENDED:                                             │\n";
  echo "│  • Add CONTEXT.md to .gitignore                             │\n";
  echo "│  • Review output before sharing                             │\n";
  echo "│  • Use only with trusted/self-hosted AI                     │\n";
  echo "│  • Keep in private/internal documentation only              │\n";
  echo "│                                                             │\n";
  echo "│ Use --suppress-warnings to hide this message                │\n";
  echo "└─────────────────────────────────────────────────────────────┘\n";
  echo "\n";
}

/**
 * Validates and normalizes a directory path.
 *
 * @param string $path
 *   The path to validate.
 *
 * @return string
 *   The normalized absolute path
 */
function validateAndNormalizePath(string $path): string {
  $projectRoot = getcwd();

  // Check if path is absolute (security risk).
  if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\\\\/', $path)) {
    fwrite(STDERR, "Error: Absolute paths are not allowed for security reasons.\n");
    fwrite(STDERR, "Please use relative paths from project root: {$projectRoot}\n");
    exit(1);
  }

  // Construct full path from project root.
  $fullPath = $projectRoot . DIRECTORY_SEPARATOR . $path;

  // Normalize path.
  $realPath = realpath($fullPath);

  // Check if path exists.
  if ($realPath === FALSE) {
    fwrite(STDERR, "Error: Path does not exist: {$path}\n");
    exit(1);
  }

  // Verify it's a directory.
  if (!is_dir($realPath)) {
    fwrite(STDERR, "Error: Path is not a directory: {$path}\n");
    exit(1);
  }

  // Verify it's readable.
  if (!is_readable($realPath)) {
    fwrite(STDERR, "Error: Path is not readable: {$path}\n");
    exit(1);
  }

  // Security: Ensure path is within project root.
  if (strpos($realPath, $projectRoot) !== 0) {
    fwrite(STDERR, "Error: Path must be within project root for security reasons.\n");
    exit(1);
  }

  return $realPath;
}

/**
 * Validates and normalizes an output file path.
 *
 * @param string $outputPath
 *   The output path to validate.
 *
 * @return string
 *   The normalized absolute path.
 */
function validateOutputPath(string $outputPath): string {
  $projectRoot = getcwd();

  // Check if path is absolute (security risk).
  if (str_starts_with($outputPath, '/') || preg_match('/^[a-zA-Z]:\\\\/', $outputPath)) {
    fwrite(STDERR, "Error: Absolute paths are not allowed for security reasons.\n");
    fwrite(STDERR, "Please use relative paths from project root: {$projectRoot}\n");
    exit(1);
  }

  // Construct full path from project root.
  $fullOutputPath = $projectRoot . DIRECTORY_SEPARATOR . $outputPath;

  // Get directory and filename.
  $outputDir = dirname($fullOutputPath);
  $filename = basename($fullOutputPath);

  // Validate filename.
  if (preg_match('/[^a-zA-Z0-9._-]/', str_replace(['/', '\\'], '', $filename))) {
    fwrite(STDERR, "Error: Invalid characters in filename: {$filename}\n");
    exit(1);
  }

  // Create directory if needed.
  if (!is_dir($outputDir)) {
    if (!mkdir($outputDir, 0755, TRUE)) {
      fwrite(STDERR, "Error: Cannot create output directory: {$outputDir}\n");
      exit(1);
    }
  }

  // Normalize directory path.
  $realOutputDir = realpath($outputDir);
  if ($realOutputDir === FALSE) {
    fwrite(STDERR, "Error: Invalid output directory: {$outputDir}\n");
    exit(1);
  }

  // Security: Ensure output is within project root.
  if (strpos($realOutputDir, $projectRoot) !== 0) {
    fwrite(STDERR, "Error: Output path must be within project root for security reasons.\n");
    exit(1);
  }

  return $realOutputDir . DIRECTORY_SEPARATOR . $filename;
}
