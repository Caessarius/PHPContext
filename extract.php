#!/usr/bin/env php
<?php

/**
 * @file
 * PHP code metadata extractor for AI context.
 *
 * SECURITY: This tool extracts sensitive code information.
 * Use with caution and review output before sharing.
 */

// Find autoloader from main project.
// scripts/phpcontext || standalone with own composer.
$autoload_paths = [
  __DIR__ . '/../../vendor/autoload.php',
  __DIR__ . '/vendor/autoload.php',
];

$autoload_found = FALSE;
foreach ($autoload_paths as $file) {
  $real_autoload_path = realpath($file);
  if ($real_autoload_path && file_exists($real_autoload_path)) {
    require_once $real_autoload_path;
    $autoload_found = TRUE;
    break;
  }
}

if (!$autoload_found) {
  fwrite(STDERR, "Error: Composer autoloader not found.\n");
  fwrite(STDERR, "Run 'composer require nikic/php-parser symfony/yaml --dev' in your main project.\n");
  exit(1);
}

// Check for symfony/yaml.
if (!class_exists('Symfony\Component\Yaml\Yaml')) {
  fwrite(STDERR, "Error: symfony/yaml not found.\n");
  fwrite(STDERR, "Run 'composer require symfony/yaml --dev'\n");
  exit(1);
}

// Load context-extractor classes.
foreach (glob(__DIR__ . '/src/*.php') as $file) {
  require_once $file;
}

use ContextExtractor\MetadataExtractor;
use ContextExtractor\ContextFormatter;
use Symfony\Component\Yaml\Yaml;

// Check for help flag first (can be at any position)
for ($i = 1; $i < $argc; $i++) {
  if ($argv[$i] === '--help' || $argv[$i] === '-h') {
    display_help();
    exit(0);
  }
}

// Load default config.yaml.
$default_config = load_config(__DIR__ . '/config.yaml');
$config_note = 'using default config.yaml';
$project_root = getcwd();

// Parse CLI arguments.
$cli_paths = [];
$external_config_path = NULL;
$cli_options = [
  'full' => FALSE,
  'minimal' => FALSE,
  'suppress_warnings' => FALSE,
  'exclude' => [],
  'output' => NULL,
];

for ($i = 1; $i < $argc; $i++) {
  if ($argv[$i] === '--full' || $argv[$i] === '--no-limits') {
    $cli_options['full'] = TRUE;
  }
  elseif ($argv[$i] === '--minimal') {
    $cli_options['minimal'] = TRUE;
  }
  elseif ($argv[$i] === '--suppress-warnings') {
    $cli_options['suppress_warnings'] = TRUE;
  }
  elseif (str_starts_with($argv[$i], '--exclude=')) {
    $cli_options['exclude'][] = substr($argv[$i], 10);
  }
  elseif (str_starts_with($argv[$i], '--config=')) {
    $external_config_path = substr($argv[$i], 9);
  }
  elseif (str_starts_with($argv[$i], '--output=')) {
    $cli_options['output'] = substr($argv[$i], 9);
  }
  elseif (!str_starts_with($argv[$i], '--')) {
    // Positional argument - treat as path.
    $cli_paths[] = $argv[$i];
  }
}

// Load external config if provided, or auto-detect phpcontext.yaml.
$external_config = [];
if ($external_config_path) {
  // Explicit --config provided.
  $full_config_path = validate_config_path($external_config_path, $project_root);
  if (!file_exists($full_config_path)) {
    fwrite(STDERR, "Error: Config file not found: {$external_config_path}\n");
    exit(1);
  }
  $external_config = load_config($full_config_path);
  $config_note = 'using explicit --config=' . $external_config_path;
}
elseif (file_exists($project_root . DIRECTORY_SEPARATOR . 'phpcontext.yaml')) {
  // Auto-detect phpcontext.yaml in project root.
  $external_config_path = 'phpcontext.yaml';
  $full_config_path = $project_root . DIRECTORY_SEPARATOR . 'phpcontext.yaml';
  $external_config = load_config($full_config_path);
  $config_note = 'using implicit project ' . $external_config_path;
}

// Merge configurations (CLI > external > default)
$config = merge_configs($default_config, $external_config, $cli_options, $cli_paths);

// Set resource limits.
ini_set('memory_limit', $config['resource_limits']['memory_limit']);
set_time_limit($config['resource_limits']['time_limit']);

// Display configuration.
echo "PHPContext - Code Metadata Extractor\n";
echo "=====================================\n\n";
echo "Config: " . $config_note . "\n";
echo "Paths: " . implode(', ', $config['paths']) . "\n";
echo "Output: {$config['output']}\n";
echo "Follow Dependencies: depth={$config['follow_dependencies']['depth']}, ";
echo "scope={$config['follow_dependencies']['scope']}, ";
echo "internal_only=" . ($config['follow_dependencies']['internal_only'] ? 'true' : 'false') . "\n";

if (!empty($config['whitelist'])) {
  echo "Whitelist: " . implode(', ', $config['whitelist']) . "\n";
}

echo "Excludes: " . implode(', ', $config['exclude']) . "\n\n";

// Extract metadata from all paths.
echo "Analyzing PHP files...\n";
$extractor = new MetadataExtractor();
$extractor->setFollowDependencies($config['follow_dependencies']);
$extractor->setWhitelist($config['whitelist']);

// Inject search patterns for manual file resolution.
if (!empty($config['follow_dependencies']['search_patterns'])) {
  $extractor->setSearchPatterns($config['follow_dependencies']['search_patterns']);
}

$all_metadata = NULL;
foreach ($config['paths'] as $path_config) {
  $path = is_array($path_config) ? $path_config['path'] : $path_config;
  $path_excludes = $config['exclude'];
  $path_options = $config['options'];

  // Per-path options override.
  if (is_array($path_config)) {
    if (isset($path_config['exclude'])) {
      $path_excludes = array_merge($path_excludes, $path_config['exclude']);
    }
    if (isset($path_config['options'])) {
      $path_options = array_replace_recursive($path_options, $path_config['options']);
    }
  }

  $validated_path = validate_and_normalize_path($path, $project_root);
  echo "Scanning: {$path}\n";

  $metadata = $extractor->extractFromDirectory($validated_path, $path_excludes);

  // Merge metadata (union strategy)
  if ($all_metadata === NULL) {
    $all_metadata = $metadata;
  }
  else {
    $all_metadata = merge_metadata($all_metadata, $metadata);
  }
}

echo "\nFiles analyzed: {$all_metadata['summary']['files_analyzed']}\n";
echo "Classes: {$all_metadata['summary']['classes']}\n";
echo "Interfaces: {$all_metadata['summary']['interfaces']}\n";
echo "Traits: {$all_metadata['summary']['traits']}\n";
echo "Enums: {$all_metadata['summary']['enums']}\n\n";

// Convert absolute paths to relative paths for output.
// Store project root for relative path conversion.
$all_metadata['files'] = array_map(function ($file) use ($project_root) {
  $relative_path = str_replace($project_root . DIRECTORY_SEPARATOR, '', $file);
  // Normalize directory separators to forward slashes for consistency.
  return str_replace('\\', '/', $relative_path);
}, $all_metadata['files']);

// Format and write output.
echo "Generating CONTEXT.md...\n";
$formatter = new ContextFormatter($config['options']);
$context_md = $formatter->format($all_metadata);

// Check output size.
$output_size_mb = strlen($context_md) / 1024 / 1024;
if ($output_size_mb > 50) {
  fwrite(STDERR, "\nWarning: Output file is very large (" . number_format($output_size_mb, 2) . "MB)\n");
  fwrite(STDERR, "Consider using --minimal flag or custom exclusions.\n\n");
}

$output_path = validate_output_path($config['output'], $project_root);
file_put_contents($output_path, $context_md);

// Set restrictive permissions on output file.
chmod($output_path, 0644);

echo "✓ Context generated: {$config['output']}\n";
echo "  Size: " . number_format(strlen($context_md)) . " bytes (" . number_format($output_size_mb, 2) . " MB)\n";
echo "  Tokens: ~" . number_format(strlen($context_md) / 4) . " (estimated)\n";

// Security warnings.
if (!$cli_options['suppress_warnings']) {
  display_security_warning();
}

// ============================================================================
// FUNCTIONS
// ============================================================================

/**
 * Display help message.
 */
function display_help(): void {
  echo <<<HELP
PHPContext - AI Code Context Extractor

Usage: extract.php [paths...] [options]

Arguments:
  paths               Source directories to scan (relative paths, default from config.yaml)

Options:
  --config=<file>     External config file (default: auto-detect phpcontext.yaml)
  --output=<file>     Override output file path (relative to project root)
  --full              Remove most limits for detailed output
  --minimal           Minimal output for large projects
  --exclude=<path>    Add exclusion path (can be repeated, cumulative)
  --suppress-warnings Suppress security warnings
  --help, -h          Show this help message

Configuration:
  Default config:     config.yaml (in tool directory)
  Project config:     phpcontext.yaml (auto-detected in project root)
  Merge order:        CLI > project config > default config

Security:
  - Only relative paths allowed (no absolute paths)
  - Paths must be within project root
  - Output contains sensitive information - review before sharing

Examples:
  extract.php src                              # Scan src/ with defaults
  extract.php --config=phpcontext.yaml         # Use project config (explicit)
  extract.php src modules --exclude=Tests/     # Multiple paths + exclusion
  extract.php --full                           # High detail mode
  extract.php --output=docs/context.md         # Override output path
  extract.php --config=custom.yaml --output=out.md  # Custom config + output

HELP;
}

/**
 * Load and parse YAML configuration file.
 *
 * @param string $path
 *   Path to YAML config file.
 *
 * @return array
 *   Parsed configuration.
 */
function load_config(string $path): array {
  try {
    $config = Yaml::parseFile($path);
    // Validate required keys.
    if (!isset($config['options']) || !isset($config['exclude'])) {
      fwrite(STDERR, "Error: Invalid config file format: {$path}\n");
      exit(1);
    }
    return $config;
  }
  catch (\Exception $e) {
    fwrite(STDERR, "Error parsing config file {$path}: " . $e->getMessage() . "\n");
    exit(1);
  }
}

/**
 * Merge multiple configuration sources with proper precedence.
 *
 * Merge strategy:
 * - Options/limits: Override (last wins)
 * - Paths/exclude: Cumulative (union)
 * - CLI has highest precedence, then external config, then defaults.
 *
 * @param array $default
 *   Default configuration from config.yaml.
 * @param array $external
 *   External configuration from --config file.
 * @param array $cli_options
 *   CLI flags and options.
 * @param array $cli_paths
 *   CLI positional path arguments.
 *
 * @return array
 *   Merged configuration.
 */
function merge_configs(array $default, array $external, array $cli_options, array $cli_paths): array {
  // Start with default.
  $config = $default;

  // Merge external config (overrides options/limits, extends paths/exclude)
  if (!empty($external)) {
    // Override options and limits.
    if (isset($external['options'])) {
      $config['options'] = array_replace_recursive($config['options'], $external['options']);
    }
    if (isset($external['resource_limits'])) {
      $config['resource_limits'] = array_merge($config['resource_limits'], $external['resource_limits']);
    }
    if (isset($external['follow_dependencies'])) {
      $config['follow_dependencies'] = array_merge($config['follow_dependencies'], $external['follow_dependencies']);
    }
    if (isset($external['output'])) {
      $config['output'] = $external['output'];
    }

    // Extend paths (union)
    if (isset($external['paths'])) {
      $config['paths'] = array_merge($config['paths'], $external['paths']);
    }

    // Extend exclude (cumulative)
    if (isset($external['exclude'])) {
      $config['exclude'] = array_merge($config['exclude'], $external['exclude']);
    }

    // Extend whitelist (cumulative)
    if (isset($external['whitelist'])) {
      $config['whitelist'] = array_merge($config['whitelist'] ?? [], $external['whitelist']);
    }
  }

  // Add CLI paths (cumulative)
  if (!empty($cli_paths)) {
    $config['paths'] = array_merge($config['paths'], $cli_paths);
  }

  // Add CLI excludes (cumulative)
  if (!empty($cli_options['exclude'])) {
    $config['exclude'] = array_merge($config['exclude'], $cli_options['exclude']);
  }

  // Apply CLI presets (--full or --minimal)
  if ($cli_options['full'] && isset($config['presets']['full'])) {
    // Apply full preset from config.
    if (isset($config['presets']['full']['options'])) {
      $config['options'] = array_replace_recursive($config['options'], $config['presets']['full']['options']);
    }
    if (isset($config['presets']['full']['limits'])) {
      $config['options']['limits'] = array_merge($config['options']['limits'], $config['presets']['full']['limits']);
    }
  }

  if ($cli_options['minimal'] && isset($config['presets']['minimal'])) {
    // Apply minimal preset from config.
    if (isset($config['presets']['minimal']['options'])) {
      $config['options'] = array_replace_recursive($config['options'], $config['presets']['minimal']['options']);
    }
    if (isset($config['presets']['minimal']['limits'])) {
      $config['options']['limits'] = array_merge($config['options']['limits'], $config['presets']['minimal']['limits']);
    }
  }

  // Remove duplicates from paths and exclude.
  $config['paths'] = array_unique($config['paths']);
  $config['exclude'] = array_unique($config['exclude']);

  // Apply CLI output override (highest precedence).
  if (!empty($cli_options['output'])) {
    $config['output'] = $cli_options['output'];
  }

  return $config;
}

/**
 * Merge two metadata arrays using union strategy.
 *
 * Combines classes by FQCN, preventing duplicates.
 *
 * @param array $existing
 *   Existing metadata.
 * @param array $new
 *   New metadata to merge.
 *
 * @return array
 *   Merged metadata.
 */
function merge_metadata(array $existing, array $new): array {
  // Merge summary.
  $existing['summary']['files_analyzed'] += $new['summary']['files_analyzed'];
  $existing['summary']['classes'] += $new['summary']['classes'];
  $existing['summary']['interfaces'] += $new['summary']['interfaces'];
  $existing['summary']['traits'] += $new['summary']['traits'];
  $existing['summary']['enums'] += $new['summary']['enums'];

  // Merge classes, interfaces, traits, enums (union by FQCN)
  $existing['classes'] = merge_by_fqcn($existing['classes'], $new['classes']);
  $existing['interfaces'] = merge_by_fqcn($existing['interfaces'], $new['interfaces']);
  $existing['traits'] = merge_by_fqcn($existing['traits'], $new['traits']);
  $existing['enums'] = merge_by_fqcn($existing['enums'], $new['enums']);

  // Merge files.
  $existing['files'] = array_unique(array_merge($existing['files'], $new['files']));

  // Merge dependencies.
  foreach ($new['dependencies'] as $dep => $usages) {
    if (isset($existing['dependencies'][$dep])) {
      $existing['dependencies'][$dep] = array_unique(array_merge($existing['dependencies'][$dep], $usages));
    }
    else {
      $existing['dependencies'][$dep] = $usages;
    }
  }

  // Merge patterns.
  foreach ($new['patterns'] as $pattern => $classes) {
    if (isset($existing['patterns'][$pattern])) {
      $existing['patterns'][$pattern] = array_unique(array_merge($existing['patterns'][$pattern], $classes));
    }
    else {
      $existing['patterns'][$pattern] = $classes;
    }
  }

  // Merge namespaces.
  foreach ($new['namespaces'] as $ns => $types) {
    if (isset($existing['namespaces'][$ns])) {
      foreach ($types as $type => $items) {
        $existing['namespaces'][$ns][$type] = array_unique(array_merge($existing['namespaces'][$ns][$type], $items));
      }
    }
    else {
      $existing['namespaces'][$ns] = $types;
    }
  }

  return $existing;
}

/**
 * Merge arrays by FQCN to prevent duplicates.
 *
 * @param array $existing
 *   Existing items.
 * @param array $new
 *   New items to add.
 *
 * @return array
 *   Merged array without FQCN duplicates.
 */
function merge_by_fqcn(array $existing, array $new): array {
  $merged = $existing;
  $existing_fqcns = array_column($existing, 'fqcn');

  foreach ($new as $item) {
    if (!in_array($item['fqcn'], $existing_fqcns)) {
      $merged[] = $item;
    }
  }

  return $merged;
}

/**
 * Validate and normalize a config file path.
 *
 * Security: Only relative paths within project root allowed.
 *
 * @param string $path
 *   Relative path to config file.
 * @param string $project_root
 *   Project root directory.
 *
 * @return string
 *   Validated absolute path.
 */
function validate_config_path(string $path, string $project_root): string {
  // Check if absolute path (security risk).
  if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\\\\/', $path)) {
    fwrite(STDERR, "Error: Absolute config paths not allowed.\n");
    exit(1);
  }

  // Sanitize filename.
  if (preg_match('/[^a-zA-Z0-9._-]/', str_replace(['/', '\\'], '', basename($path)))) {
    fwrite(STDERR, "Error: Invalid characters in config filename.\n");
    exit(1);
  }

  return $project_root . DIRECTORY_SEPARATOR . $path;
}

/**
 * Validates and normalizes a directory path.
 *
 * Security: Prevents path traversal attacks
 * and ensures path is within project root.
 *
 * @param string $path
 *   The path to validate (must be relative).
 * @param string $project_root
 *   Project root directory.
 *
 * @return string
 *   The normalized absolute path.
 */
function validate_and_normalize_path(string $path, string $project_root): string {
  // Check if path is absolute (security risk).
  if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\\\\/', $path)) {
    fwrite(STDERR, "Error: Absolute paths are not allowed for security reasons.\n");
    fwrite(STDERR, "Please use relative paths from project root: {$project_root}\n");
    exit(1);
  }

  // Construct full path from project root.
  $full_path = $project_root . DIRECTORY_SEPARATOR . $path;

  // Normalize path.
  $real_path = realpath($full_path);

  // Check if path exists.
  if ($real_path === FALSE) {
    fwrite(STDERR, "Error: Path does not exist: {$path}\n");
    exit(1);
  }

  // Verify it's a directory.
  if (!is_dir($real_path)) {
    fwrite(STDERR, "Error: Path is not a directory: {$path}\n");
    exit(1);
  }

  // Verify it's readable.
  if (!is_readable($real_path)) {
    fwrite(STDERR, "Error: Path is not readable: {$path}\n");
    exit(1);
  }

  // Security: Ensure path is within project root.
  if (strpos($real_path, $project_root) !== 0) {
    fwrite(STDERR, "Error: Path must be within project root for security reasons.\n");
    exit(1);
  }

  return $real_path;
}

/**
 * Validates and normalizes an output file path.
 *
 * Security: Creates directory structure safely and validates path.
 *
 * @param string $output_path
 *   The output path to validate (must be relative).
 * @param string $project_root
 *   Project root directory.
 *
 * @return string
 *   The normalized absolute path.
 */
function validate_output_path(string $output_path, string $project_root): string {
  // Check if path is absolute (security risk).
  if (str_starts_with($output_path, '/') || preg_match('/^[a-zA-Z]:\\\\/', $output_path)) {
    fwrite(STDERR, "Error: Absolute paths are not allowed for security reasons.\n");
    fwrite(STDERR, "Please use relative paths from project root: {$project_root}\n");
    exit(1);
  }

  // Construct full path from project root.
  $full_output_path = $project_root . DIRECTORY_SEPARATOR . $output_path;

  // Get directory and filename.
  $output_dir = dirname($full_output_path);
  $filename = basename($full_output_path);

  // Validate filename.
  if (preg_match('/[^a-zA-Z0-9._-]/', str_replace(['/', '\\'], '', $filename))) {
    fwrite(STDERR, "Error: Invalid characters in filename: {$filename}\n");
    exit(1);
  }

  // Create directory if needed.
  if (!is_dir($output_dir)) {
    if (!mkdir($output_dir, 0755, TRUE)) {
      fwrite(STDERR, "Error: Cannot create output directory: {$output_dir}\n");
      exit(1);
    }
  }

  // Normalize directory path.
  $real_output_dir = realpath($output_dir);
  if ($real_output_dir === FALSE) {
    fwrite(STDERR, "Error: Invalid output directory: {$output_dir}\n");
    exit(1);
  }

  // Security: Ensure output is within project root.
  if (strpos($real_output_dir, $project_root) !== 0) {
    fwrite(STDERR, "Error: Output path must be within project root for security reasons.\n");
    exit(1);
  }

  return $real_output_dir . DIRECTORY_SEPARATOR . $filename;
}

/**
 * Display security warning about sensitive output.
 */
function display_security_warning(): void {
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
