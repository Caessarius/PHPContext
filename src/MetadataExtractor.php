<?php

namespace ContextExtractor;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Extracts metadata from PHP source code.
 *
 * Parses PHP files to extract classes, interfaces, traits, enums, functions,
 * and their relationships. Supports dependency following and pattern detection.
 */
class MetadataExtractor {

  /**
   * Metadata storage array.
   *
   * @var array
   */
  private array $metadata = [
    'namespaces' => [],
    'classes' => [],
    'interfaces' => [],
    'traits' => [],
    'enums' => [],
    'functions' => [],
    'constants' => [],
    'dependencies' => [],
    'patterns' => [],
    'not_found_dependencies' => [],
  ];

  /**
   * Processed files list.
   *
   * @var array
   */
  private array $files = [];

  /**
   * Follow dependencies configuration.
   *
   * @var array
   */
  private array $followDependencies = [];

  /**
   * Whitelist patterns for filtering classes.
   *
   * @var array
   */
  private array $whitelist = [];

  /**
   * Processed FQCNs for cycle detection.
   *
   * @var array
   */
  private array $processedFqcns = [];

  /**
   * Dependency depth tracking.
   *
   * Tracks at which depth each dependency was first discovered.
   * Format: ['FQCN' => depth]
   *
   * @var array
   */
  private array $dependencyDepths = [];

  /**
   * Composer ClassLoader instance for PSR-4 resolution.
   *
   * @var object|null
   */
  private ?object $classLoader = NULL;

  /**
   * Search patterns from configuration for manual file resolution.
   *
   * @var array
   */
  private array $searchPatterns = [];

  /**
   * Detected project type cache.
   *
   * @var string|null
   */
  private ?string $projectType = NULL;

  /**
   * Configure dependency following behavior.
   *
   * @param array $config
   *   Follow dependencies configuration:
   *   - depth: int (0-2) How many levels deep to follow.
   *   - scope: string What types to follow
   *     (all|interfaces|abstract_classes|interfaces_and_abstract).
   *   - internal_only: bool Skip vendor/ classes.
   */
  public function setFollowDependencies(array $config): void {
    $this->followDependencies = $config;
  }

  /**
   * Set whitelist patterns for filtering classes.
   *
   * Only classes matching these glob patterns will be included.
   *
   * @param array $patterns
   *   Glob patterns (e.g., ['App\\Service\\*', '*Interface']).
   */
  public function setWhitelist(array $patterns): void {
    $this->whitelist = $patterns;
  }

  /**
   * Set search patterns for manual file resolution.
   *
   * Patterns from follow_dependencies.search_patterns configuration.
   *
   * @param array $patterns
   *   Search patterns with conditions and path templates.
   */
  public function setSearchPatterns(array $patterns): void {
    $this->searchPatterns = $patterns;
  }

  /**
   * Extract metadata from directory.
   *
   * @param string $directory
   *   Directory path to scan.
   * @param array $excludePaths
   *   Paths to exclude from scanning.
   *
   * @return array
   *   Extracted metadata.
   */
  public function extractFromDirectory(string $directory, array $excludePaths = []): array {
    // Initialize Composer ClassLoader for PSR-4 resolution.
    $this->initializeClassLoader();

    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $traverser = new NodeTraverser();
    $nameResolver = new NameResolver();
    $visitor = new MetadataVisitor();

    $traverser->addVisitor($nameResolver);
    $traverser->addVisitor($visitor);

    $this->scanDirectory($directory, $parser, $traverser, $visitor, $excludePaths);

    $metadata = $this->aggregateMetadata($visitor);

    // Follow dependencies if enabled.
    if ($this->followDependencies['depth'] > 0) {
      $metadata = $this->followDependenciesRecursive(
        $metadata,
        $parser,
        $traverser,
        $visitor,
        $excludePaths,
        1
      );
    }

    // Analyze built-in PHP classes from the final metadata.
    $metadata['builtin_php_classes'] = $this->analyzeBuiltinPhpClasses($metadata);

    return $metadata;
  }

  /**
   * Initialize Composer ClassLoader for PSR-4 class resolution.
   *
   * Attempts to find and use the Composer autoloader to resolve class files.
   */
  private function initializeClassLoader(): void {
    // Try to find Composer autoloader.
    $autoloadPaths = [
      getcwd() . '/vendor/autoload.php',
      getcwd() . '/../../vendor/autoload.php',
      __DIR__ . '/../vendor/autoload.php',
      __DIR__ . '/../../vendor/autoload.php',
    ];

    foreach ($autoloadPaths as $autoloadPath) {
      if (file_exists($autoloadPath)) {
        // Get all registered autoloaders.
        $autoloaders = spl_autoload_functions();

        // Find Composer's ClassLoader.
        foreach ($autoloaders as $autoloader) {
          if (is_array($autoloader) && isset($autoloader[0])) {
            $loader = $autoloader[0];
            // Check if it's Composer\Autoload\ClassLoader.
            if (method_exists($loader, 'findFile')) {
              $this->classLoader = $loader;
              return;
            }
          }
        }
        break;
      }
    }
  }

  /**
   * Detect the project type based on filesystem markers.
   *
   * Detects: drupal, symfony, oroplatform, laravel, or standard.
   * Results are cached for performance.
   *
   * @return string
   *   Project type identifier.
   */
  private function detectProjectType(): string {
    // Return cached result if available.
    if ($this->projectType !== NULL) {
      return $this->projectType;
    }

    $cwd = getcwd();

    // Drupal: check for core/lib/Drupal or drupal/core in composer.json.
    if (file_exists("{$cwd}/web/core/lib/Drupal/Core") ||
        file_exists("{$cwd}/core/lib/Drupal/Core") ||
        $this->checkComposerPackage('drupal/core')) {
      $this->projectType = 'drupal';
      return $this->projectType;
    }

    // OroPlatform: check for oro/platform in vendor or composer.json.
    if (file_exists("{$cwd}/vendor/oro/platform") ||
        $this->checkComposerPackage('oro/platform')) {
      $this->projectType = 'oroplatform';
      return $this->projectType;
    }

    // Laravel: check for artisan, bootstrap/app.php, or laravel/framework.
    if (file_exists("{$cwd}/artisan") ||
        file_exists("{$cwd}/bootstrap/app.php") ||
        $this->checkComposerPackage('laravel/framework')) {
      $this->projectType = 'laravel';
      return $this->projectType;
    }

    // Symfony: check for symfony/symfony, symfony/kernel or config/bundles.php.
    if (file_exists("{$cwd}/config/bundles.php") ||
        file_exists("{$cwd}/symfony.lock") ||
        $this->checkComposerPackage('symfony/symfony') ||
        $this->checkComposerPackage('symfony/kernel')) {
      $this->projectType = 'symfony';
      return $this->projectType;
    }

    // Default to standard PHP project.
    $this->projectType = 'standard';
    return $this->projectType;
  }

  /**
   * Check if a Composer package is installed.
   *
   * @param string $packageName
   *   Package name (e.g., 'drupal/core').
   *
   * @return bool
   *   TRUE if package is found in composer.json or installed.json.
   */
  private function checkComposerPackage(string $packageName): bool {
    $cwd = getcwd();

    // Check composer.json require/require-dev.
    $composerFile = "{$cwd}/composer.json";
    if (file_exists($composerFile)) {
      $composer = json_decode(file_get_contents($composerFile), TRUE);
      if (isset($composer['require'][$packageName]) ||
          isset($composer['require-dev'][$packageName])) {
        return TRUE;
      }
    }

    // Check vendor/composer/installed.json.
    $installedFile = "{$cwd}/vendor/composer/installed.json";
    if (file_exists($installedFile)) {
      $installed = json_decode(file_get_contents($installedFile), TRUE);
      if (isset($installed['packages'])) {
        foreach ($installed['packages'] as $package) {
          if (($package['name'] ?? '') === $packageName) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Recursively follow class dependencies up to configured depth.
   *
   * Uses cycle detection to prevent infinite loops.
   *
   * @param array $metadata
   *   Current metadata state.
   * @param object $parser
   *   PHP parser instance.
   * @param \PhpParser\NodeTraverser $traverser
   *   AST traverser.
   * @param \ContextExtractor\MetadataVisitor $visitor
   *   Metadata collector.
   * @param array $excludePaths
   *   Paths to exclude from scanning.
   * @param int $currentDepth
   *   Current recursion depth (1-indexed).
   *
   * @return array
   *   Updated metadata with followed dependencies.
   */
  private function followDependenciesRecursive(
    array $metadata,
    $parser,
    NodeTraverser $traverser,
    MetadataVisitor $visitor,
    array $excludePaths,
    int $currentDepth,
  ): array {
    if ($currentDepth > $this->followDependencies['depth']) {
      return $metadata;
    }

    $newDependencies = $this->extractNewDependencies($metadata);
    $processedInThisRound = [];

    foreach ($newDependencies as $depFqcn) {
      // Track depth at first discovery.
      if (!isset($this->dependencyDepths[$depFqcn])) {
        $this->dependencyDepths[$depFqcn] = $currentDepth;
      }

      // Skip if already processed.
      if (in_array($depFqcn, $this->processedFqcns)) {
        continue;
      }

      // Apply scope filter (only for depth > 0).
      if (!$this->shouldFollowDependency($depFqcn, $metadata, $currentDepth)) {
        $this->metadata['not_found_dependencies'][$depFqcn] = 'filtered_by_scope';
        $this->processedFqcns[] = $depFqcn;
        continue;
      }

      // Try to find and parse the dependency file.
      $depFile = $this->findDependencyFile($depFqcn);
      if ($depFile && $this->shouldProcessFile($depFile, $excludePaths, $currentDepth)) {
        try {
          $code = file_get_contents($depFile);
          $stmts = $parser->parse($code);

          if ($stmts) {
            $visitor->setCurrentFile($depFile);
            $traverser->traverse($stmts);
            $this->files[] = $depFile;
            $this->processedFqcns[] = $depFqcn;
            $processedInThisRound[] = $depFqcn;
          }
        }
        catch (Error $e) {
          // Parse error in dependency file - track as not found.
          $this->metadata['not_found_dependencies'][$depFqcn] = 'parse_error';
          $this->processedFqcns[] = $depFqcn;
        }
      }
      else {
        // Dependency file not found or excluded - track it.
        if (!$depFile) {
          // Skip built-in PHP classes (they will be analyzed separately).
          if (!$this->isBuiltinPhpClass($depFqcn)) {
            $this->metadata['not_found_dependencies'][$depFqcn] = 'file_not_found';
          }
        }
        elseif (!$this->shouldProcessFile($depFile, $excludePaths, $currentDepth)) {
          $this->metadata['not_found_dependencies'][$depFqcn] = 'excluded_by_filter';
        }
        $this->processedFqcns[] = $depFqcn;
      }
    }

    // If we processed new deps, aggregate and recurse.
    if (!empty($processedInThisRound)) {
      $newMetadata = $this->aggregateMetadata($visitor);
      $metadata = $this->mergeMetadataArrays($metadata, $newMetadata);

      // Recurse to next depth level.
      $metadata = $this->followDependenciesRecursive(
        $metadata,
        $parser,
        $traverser,
        $visitor,
        $excludePaths,
        $currentDepth + 1
      );
    }

    return $metadata;
  }

  /**
   * Extract all unique dependencies from current metadata.
   *
   * Collects extends, implements, and type dependencies from classes and
   * interfaces.
   *
   * @param array $metadata
   *   Current metadata.
   *
   * @return array
   *   Unique FQCNs of all dependencies.
   */
  private function extractNewDependencies(array $metadata): array {
    $allDeps = [];

    foreach ($metadata['classes'] as $class) {
      $classDeps = array_merge(
        $class['extends'] ?? [],
        $class['implements'] ?? [],
        $class['type_dependencies'] ?? [],
        $class['uses'] ?? []
      );

      $allDeps = array_merge($allDeps, $classDeps);
    }

    foreach ($metadata['interfaces'] as $interface) {
      $interfaceDeps = array_merge(
        $interface['extends'] ?? [],
        $interface['uses'] ?? []
      );
      $allDeps = array_merge($allDeps, $interfaceDeps);
    }

    foreach ($metadata['traits'] as $trait) {
      $traitDeps = array_merge(
        $trait['uses'] ?? []
      );
      $allDeps = array_merge($allDeps, $traitDeps);
    }

    foreach ($metadata['enums'] as $enum) {
      $enumDeps = array_merge(
        $enum['implements'] ?? [],
        $enum['uses'] ?? []
      );
      $allDeps = array_merge($allDeps, $enumDeps);
    }

    $uniqueDeps = array_unique($allDeps);

    return $uniqueDeps;
  }

  /**
   * Determine if a dependency should be followed based on scope and filters.
   *
   * Applies internal_only and scope filters (interfaces/abstract/all).
   * Both filters only apply to depth > 1 (recursive dependencies).
   * Level 1 dependencies (direct) are always followed (all filters bypassed).
   *
   * @param string $fqcn
   *   Fully qualified class name.
   * @param array $metadata
   *   Current metadata for type checking.
   * @param int $depth
   *   Current recursion depth (1 = direct dependencies, 2+ = recursive).
   *
   * @return bool
   *   TRUE if dependency should be followed.
   */
  private function shouldFollowDependency(string $fqcn, array $metadata, int $depth): bool {
    // Level 1 (direct dependencies) are always followed.
    // All filters (internal_only, scope) only apply to depth > 1.
    if ($depth === 1) {
      return TRUE;
    }

    // Apply internal_only filter for depth > 1.
    if ($this->followDependencies['internal_only'] && $this->isVendorClass($fqcn)) {
      return FALSE;
    }

    // Apply scope filter for depth > 1.
    $scope = $this->followDependencies['scope'];

    if ($scope === 'all') {
      return TRUE;
    }

    // Check if it's an interface.
    $isInterface = $this->isInterface($fqcn, $metadata);
    if ($scope === 'interfaces' && $isInterface) {
      return TRUE;
    }

    // Check if it's an abstract class.
    $isAbstract = $this->isAbstractClass($fqcn, $metadata);
    if ($scope === 'abstract_classes' && $isAbstract) {
      return TRUE;
    }

    if ($scope === 'interfaces_and_abstract' && ($isInterface || $isAbstract)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Check if FQCN represents an interface by searching metadata.
   *
   * @param string $fqcn
   *   Fully qualified class name.
   * @param array $metadata
   *   Metadata to search.
   *
   * @return bool
   *   TRUE if FQCN is an interface.
   */
  private function isInterface(string $fqcn, array $metadata): bool {
    foreach ($metadata['interfaces'] as $interface) {
      if ($interface['fqcn'] === $fqcn) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Check if FQCN represents an abstract class by searching metadata.
   *
   * @param string $fqcn
   *   Fully qualified class name.
   * @param array $metadata
   *   Metadata to search.
   *
   * @return bool
   *   TRUE if FQCN is an abstract class.
   */
  private function isAbstractClass(string $fqcn, array $metadata): bool {
    foreach ($metadata['classes'] as $class) {
      if ($class['fqcn'] === $fqcn && $class['abstract']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Detect if class is from vendor/ based on common namespace prefixes.
   *
   * Checks against Symfony, Doctrine, Drupal, PSR, Monolog, Guzzle, Twig.
   *
   * @param string $fqcn
   *   Fully qualified class name.
   *
   * @return bool
   *   TRUE if vendor class.
   */
  private function isVendorClass(string $fqcn): bool {
    // Common vendor namespace prefixes.
    $vendorPrefixes = [
      'Symfony\\',
      'Doctrine\\',
      'Drupal\\Core\\',
      'Psr\\',
      'Monolog\\',
      'Guzzle\\',
      'Twig\\',
    ];

    foreach ($vendorPrefixes as $prefix) {
      if (str_starts_with($fqcn, $prefix)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Check if a class/interface/trait is a built-in PHP class.
   *
   * Built-in PHP classes (SPL, DOM, etc.) exist but have no source files.
   *
   * @param string $fqcn
   *   Fully qualified class name.
   *
   * @return bool
   *   TRUE if it's a built-in PHP class/interface/trait.
   */
  private function isBuiltinPhpClass(string $fqcn): bool {
    try {
      // Check if class/interface/trait exists.
      if (class_exists($fqcn, TRUE) || interface_exists($fqcn, TRUE) || trait_exists($fqcn, TRUE)) {
        $reflection = new \ReflectionClass($fqcn);
        // Built-in classes return FALSE for getFileName().
        return $reflection->getFileName() === FALSE;
      }
    }
    catch (\Throwable $e) {
      // If reflection fails, it's not a built-in class.
      return FALSE;
    }

    return FALSE;
  }

  /**
   * Locate source file for a dependency class.
   *
   * Uses Composer autoloader (PSR-4) first, then falls back to manual search.
   *
   * @param string $fqcn
   *   Fully qualified class name.
   *
   * @return string|null
   *   Absolute file path or NULL if not found.
   */
  private function findDependencyFile(string $fqcn): ?string {

    // Try Composer autoloader first (PSR-4 resolution).
    if ($this->classLoader !== NULL) {
      $file = $this->findFileViaAutoloader($fqcn);
      if ($file !== NULL) {
        return $file;
      }
    }

    // Try framework-specific auto-discovery (Drupal, Symfony, Oro, Laravel).
    $file = $this->findFileViaFrameworkAutoDiscovery($fqcn);
    if ($file !== NULL) {
      return $file;
    }

    // Fallback: manual search patterns from config.
    $file = $this->findFileManually($fqcn);

    return $file;
  }

  /**
   * Find dependency file using Composer autoloader.
   *
   * @param string $fqcn
   *   Fully qualified class name.
   *
   * @return string|null
   *   Absolute file path or NULL if not found.
   */
  private function findFileViaAutoloader(string $fqcn): ?string {

    try {
      // Use Composer's findFile method.
      if (method_exists($this->classLoader, 'findFile')) {
        $file = $this->classLoader->findFile($fqcn);

        if ($file !== FALSE && file_exists($file)) {
          return $file;
        }
      }

      // Alternative: try class_exists with autoload disabled, then reflect.
      if (class_exists($fqcn, FALSE) || interface_exists($fqcn, FALSE) || trait_exists($fqcn, FALSE)) {
        $reflection = new \ReflectionClass($fqcn);
        $file = $reflection->getFileName();
        if ($file !== FALSE && file_exists($file)) {
          return $file;
        }
      }

      // Try autoloading the class.
      if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn)) {
        $reflection = new \ReflectionClass($fqcn);
        $file = $reflection->getFileName();
        if ($file !== FALSE && file_exists($file)) {
          return $file;
        }
      }
    }
    catch (\Throwable $e) {
      // Autoloader failed, fall back to manual search.
    }

    return NULL;
  }

  /**
   * Route to appropriate framework auto-discovery based on project type.
   *
   * Detects project type and dispatches to specialized discovery methods.
   *
   * @param string $fqcn
   *   Fully qualified class name.
   *
   * @return string|null
   *   Absolute file path or NULL if not found.
   */
  private function findFileViaFrameworkAutoDiscovery(string $fqcn): ?string {
    $projectType = $this->detectProjectType();

    // Try project-specific discovery first.
    switch ($projectType) {
      case 'drupal':
        $file = $this->findFileViaDrupalAutoDiscovery($fqcn);
        if ($file !== NULL) {
          return $file;
        }
        break;

      case 'symfony':
        $file = $this->findFileViaSymfonyAutoDiscovery($fqcn);
        if ($file !== NULL) {
          return $file;
        }
        break;

      case 'oroplatform':
        // Oro is based on Symfony, try both.
        $file = $this->findFileViaOroAutoDiscovery($fqcn);
        if ($file !== NULL) {
          return $file;
        }
        $file = $this->findFileViaSymfonyAutoDiscovery($fqcn);
        if ($file !== NULL) {
          return $file;
        }
        break;

      case 'laravel':
        $file = $this->findFileViaLaravelAutoDiscovery($fqcn);
        if ($file !== NULL) {
          return $file;
        }
        break;
    }

    // For mixed projects or undetected frameworks, try common patterns.
    // This handles cases like Symfony bundles in non-Symfony projects.
    if ($projectType !== 'drupal' && str_starts_with($fqcn, 'Drupal\\')) {
      return $this->findFileViaDrupalAutoDiscovery($fqcn);
    }
    if ($projectType !== 'symfony' && str_starts_with($fqcn, 'Symfony\\')) {
      return $this->findFileViaSymfonyAutoDiscovery($fqcn);
    }
    if ($projectType !== 'oroplatform' && str_starts_with($fqcn, 'Oro\\')) {
      return $this->findFileViaOroAutoDiscovery($fqcn);
    }
    if ($projectType !== 'laravel' && str_starts_with($fqcn, 'App\\')) {
      return $this->findFileViaLaravelAutoDiscovery($fqcn);
    }

    return NULL;
  }

  /**
   * Auto-discover Drupal module files without manual configuration.
   *
   * Handles contrib/custom modules not in Composer's PSR-4 map.
   * Supports both simple modules and package/submodule structures.
   *
   * @param string $fqcn
   *   Fully qualified class name (e.g., 'Drupal\webform\WebformInterface').
   *
   * @return string|null
   *   Absolute file path or NULL if not found.
   */
  private function findFileViaDrupalAutoDiscovery(string $fqcn): ?string {
    // Only handle Drupal namespaces.
    if (!str_starts_with($fqcn, 'Drupal\\')) {
      return NULL;
    }

    // Skip Core and Component (should be in autoloader).
    if (str_starts_with($fqcn, 'Drupal\\Core\\') ||
        str_starts_with($fqcn, 'Drupal\\Component\\')) {
      return NULL;
    }

    // Parse FQCN: Drupal\MODULE\Path\To\ClassName.
    $parts = explode('\\', $fqcn);
    if (count($parts) < 3) {
      // Invalid Drupal namespace (need at least Drupal\MODULE\Class).
      return NULL;
    }

    // Extract module name (second part) and class path.
    $module_name = $parts[1];
    $module_name_lower = strtolower($module_name);

    // Build relative class path: Path\To\ClassName.php.
    $class_path_parts = array_slice($parts, 2);
    $class_path = implode(DIRECTORY_SEPARATOR, $class_path_parts) . '.php';

    $cwd = getcwd();

    // Try standard module locations in priority order.
    $search_paths = [];

    // For modules with underscores, try package/submodule structure first.
    // Example: commerce_order → commerce/modules/order.
    if (str_contains($module_name, '_')) {
      $underscore_pos = strpos($module_name, '_');
      $package = substr($module_name, 0, $underscore_pos);
      $submodule = substr($module_name, $underscore_pos + 1);

      $search_paths[] = "{$cwd}/web/modules/contrib/{$package}/modules/{$submodule}/src/{$class_path}";
      $search_paths[] = "{$cwd}/modules/contrib/{$package}/modules/{$submodule}/src/{$class_path}";
    }

    // Standard contrib module paths.
    $search_paths[] = "{$cwd}/web/modules/contrib/{$module_name_lower}/src/{$class_path}";
    $search_paths[] = "{$cwd}/modules/contrib/{$module_name_lower}/src/{$class_path}";

    // Custom module paths.
    $search_paths[] = "{$cwd}/web/modules/custom/{$module_name_lower}/src/{$class_path}";
    $search_paths[] = "{$cwd}/modules/custom/{$module_name_lower}/src/{$class_path}";

    // Core module paths.
    $search_paths[] = "{$cwd}/web/core/modules/{$module_name_lower}/src/{$class_path}";
    $search_paths[] = "{$cwd}/core/modules/{$module_name_lower}/src/{$class_path}";

    // Check each path until we find an existing file.
    foreach ($search_paths as $path) {
      if (file_exists($path)) {
        return $path;
      }
    }

    return NULL;
  }

  /**
   * Auto-discover Symfony bundle files without manual configuration.
   *
   * Handles Symfony framework bundles and custom bundles.
   *
   * @param string $fqcn
   *   Fully qualified class name (e.g., 'Symfony\Bundle\FrameworkBundle\*').
   *
   * @return string|null
   *   Absolute file path or NULL if not found.
   */
  private function findFileViaSymfonyAutoDiscovery(string $fqcn): ?string {
    $cwd = getcwd();

    // Symfony\Bundle\* → vendor/symfony/*/.
    if (str_starts_with($fqcn, 'Symfony\\Bundle\\')) {
      $parts = explode('\\', $fqcn);
      if (count($parts) >= 4) {
        // Extract: Symfony\Bundle\FrameworkBundle\...
        $bundle_name = $parts[2];
        // Convert FrameworkBundle → framework-bundle.
        $bundle_dir = strtolower(preg_replace('/Bundle$/', '', $bundle_name));
        $bundle_dir = preg_replace('/([a-z])([A-Z])/', '$1-$2', $bundle_dir);
        $bundle_dir = strtolower($bundle_dir) . '-bundle';

        $class_path_parts = array_slice($parts, 3);
        $class_path = implode(DIRECTORY_SEPARATOR, $class_path_parts) . '.php';

        $search_paths = [
          "{$cwd}/vendor/symfony/{$bundle_dir}/{$class_path}",
          "{$cwd}/vendor/symfony/{$bundle_dir}/src/{$class_path}",
        ];

        foreach ($search_paths as $path) {
          if (file_exists($path)) {
            return $path;
          }
        }
      }
    }

    // Symfony\Component\* → vendor/symfony/*/.
    if (str_starts_with($fqcn, 'Symfony\\Component\\')) {
      $parts = explode('\\', $fqcn);
      if (count($parts) >= 4) {
        // Extract: Symfony\Component\HttpFoundation\...
        $component_name = $parts[2];
        // Convert HttpFoundation → http-foundation.
        $component_dir = preg_replace('/([a-z])([A-Z])/', '$1-$2', $component_name);
        $component_dir = strtolower($component_dir);

        $class_path_parts = array_slice($parts, 3);
        $class_path = implode(DIRECTORY_SEPARATOR, $class_path_parts) . '.php';

        $search_paths = [
          "{$cwd}/vendor/symfony/{$component_dir}/{$class_path}",
          "{$cwd}/vendor/symfony/{$component_dir}/src/{$class_path}",
        ];

        foreach ($search_paths as $path) {
          if (file_exists($path)) {
            return $path;
          }
        }
      }
    }

    // App\* → src/* (Symfony app namespace).
    if (str_starts_with($fqcn, 'App\\')) {
      $parts = explode('\\', $fqcn);
      $class_path_parts = array_slice($parts, 1);
      $class_path = implode(DIRECTORY_SEPARATOR, $class_path_parts) . '.php';

      $search_paths = [
        "{$cwd}/src/{$class_path}",
        "{$cwd}/app/{$class_path}",
      ];

      foreach ($search_paths as $path) {
        if (file_exists($path)) {
          return $path;
        }
      }
    }

    return NULL;
  }

  /**
   * Auto-discover OroPlatform bundle files without manual configuration.
   *
   * Handles Oro bundles and components.
   *
   * @param string $fqcn
   *   Fully qualified class name (e.g., 'Oro\Bundle\UserBundle\*').
   *
   * @return string|null
   *   Absolute file path or NULL if not found.
   */
  private function findFileViaOroAutoDiscovery(string $fqcn): ?string {
    $cwd = getcwd();

    // Oro\Bundle\* → vendor/oro/platform/src/Oro/Bundle/*/.
    if (str_starts_with($fqcn, 'Oro\\Bundle\\')) {
      $parts = explode('\\', $fqcn);
      if (count($parts) >= 4) {
        // Extract: Oro\Bundle\UserBundle\...
        $bundle_name = $parts[2];
        $class_path_parts = array_slice($parts, 3);
        $class_path = implode(DIRECTORY_SEPARATOR, $class_path_parts) . '.php';

        $search_paths = [
          "{$cwd}/vendor/oro/platform/src/Oro/Bundle/{$bundle_name}/{$class_path}",
          "{$cwd}/vendor/oro/{$bundle_name}/src/{$class_path}",
          "{$cwd}/src/Oro/Bundle/{$bundle_name}/{$class_path}",
        ];

        foreach ($search_paths as $path) {
          if (file_exists($path)) {
            return $path;
          }
        }
      }
    }

    // Oro\Component\* → vendor/oro/platform/src/Oro/Component/*/.
    if (str_starts_with($fqcn, 'Oro\\Component\\')) {
      $parts = explode('\\', $fqcn);
      if (count($parts) >= 4) {
        $component_name = $parts[2];
        $class_path_parts = array_slice($parts, 3);
        $class_path = implode(DIRECTORY_SEPARATOR, $class_path_parts) . '.php';

        $search_paths = [
          "{$cwd}/vendor/oro/platform/src/Oro/Component/{$component_name}/{$class_path}",
          "{$cwd}/src/Oro/Component/{$component_name}/{$class_path}",
        ];

        foreach ($search_paths as $path) {
          if (file_exists($path)) {
            return $path;
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Auto-discover Laravel application files without manual configuration.
   *
   * Handles Laravel app namespace and Illuminate framework.
   *
   * @param string $fqcn
   *   Fully qualified class name (e.g., 'App\Http\Controllers\*').
   *
   * @return string|null
   *   Absolute file path or NULL if not found.
   */
  private function findFileViaLaravelAutoDiscovery(string $fqcn): ?string {
    $cwd = getcwd();

    // App\* → app/* (Laravel app namespace).
    if (str_starts_with($fqcn, 'App\\')) {
      $parts = explode('\\', $fqcn);
      $class_path_parts = array_slice($parts, 1);
      $class_path = implode(DIRECTORY_SEPARATOR, $class_path_parts) . '.php';

      $search_paths = [
        "{$cwd}/app/{$class_path}",
      ];

      foreach ($search_paths as $path) {
        if (file_exists($path)) {
          return $path;
        }
      }
    }

    // Illuminate\* → vendor/laravel/framework/src/Illuminate/*/.
    if (str_starts_with($fqcn, 'Illuminate\\')) {
      $parts = explode('\\', $fqcn);
      if (count($parts) >= 3) {
        $component_name = $parts[1];
        $class_path_parts = array_slice($parts, 2);
        $class_path = implode(DIRECTORY_SEPARATOR, $class_path_parts) . '.php';

        $search_paths = [
          "{$cwd}/vendor/laravel/framework/src/Illuminate/{$component_name}/{$class_path}",
        ];

        foreach ($search_paths as $path) {
          if (file_exists($path)) {
            return $path;
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Evaluate if a pattern's condition matches the given FQCN.
   *
   * Supports 'starts_with:PREFIX' and 'always' conditions.
   * Applies condition_exclude filters if defined.
   *
   * @param string $fqcn
   *   Fully qualified class name.
   * @param array $pattern
   *   Pattern configuration with 'condition' and optional 'condition_exclude'.
   *
   * @return bool
   *   TRUE if condition matches and not excluded.
   */
  private function evaluateCondition(string $fqcn, array $pattern): bool {
    $condition = $pattern['condition'] ?? 'always';

    // Evaluate main condition.
    $matches = FALSE;
    if ($condition === 'always') {
      $matches = TRUE;
    }
    elseif (str_starts_with($condition, 'starts_with:')) {
      $prefix = substr($condition, strlen('starts_with:'));
      $matches = str_starts_with($fqcn, $prefix);
    }

    if (!$matches) {
      return FALSE;
    }

    // Apply exclusion filters.
    if (!empty($pattern['condition_exclude'])) {
      foreach ($pattern['condition_exclude'] as $exclude) {
        if (str_starts_with($fqcn, $exclude)) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * Resolve placeholders in a path pattern using FQCN context.
   *
   * Supported placeholders:
   * - {cwd}: Current working directory
   * - {path}: Full FQCN as path (Foo\Bar\Baz → Foo/Bar/Baz.php)
   * - {class_path}: Class path after namespace extraction
   * - {module}: Drupal module name from FQCN
   * - {module_lower}: Lowercase module name
   * - {component}: Symfony component name (kebab-case)
   * - {package}: Package name (e.g., Doctrine, Guzzle).
   *
   * @param string $fqcn
   *   Fully qualified class name.
   * @param string $pathPattern
   *   Path pattern with placeholders.
   *
   * @return string
   *   Resolved path with placeholders replaced.
   */
  private function resolvePlaceholders(string $fqcn, string $pathPattern): string {
    $cwd = getcwd();
    $path = str_replace('\\', DIRECTORY_SEPARATOR, $fqcn) . '.php';

    // Extract Drupal module name (Drupal\MODULE\...).
    $module = NULL;
    $module_lower = NULL;
    $class_path = NULL;
    $drupal_package = NULL;
    $drupal_submodule = NULL;
    if (preg_match('/^Drupal\\\\([^\\\\]+)\\\\(.+)$/', $fqcn, $matches)) {
      $module = $matches[1];
      $module_lower = strtolower($module);
      $class_path = str_replace('\\', DIRECTORY_SEPARATOR, $matches[2]) . '.php';

      // For modules with underscores, extract package and submodule.
      // E.g., commerce_order → drupal_package=commerce, drupal_submodule=order.
      if (str_contains($module_lower, '_')) {
        $parts = explode('_', $module_lower, 2);
        $drupal_package = $parts[0];
        $drupal_submodule = $parts[1] ?? '';
      }
    }

    // Extract Symfony component name (Symfony\Component\Foo\...).
    $component = NULL;
    if (str_starts_with($fqcn, 'Symfony\\')) {
      $symfony_path = str_replace('Symfony\\', '', $fqcn);
      $parts = explode('\\', $symfony_path);

      if (count($parts) >= 2) {
        // Skip 'Component' or 'Bridge' prefix.
        $start_index = ($parts[0] === 'Component' || $parts[0] === 'Bridge') ? 1 : 0;

        if (isset($parts[$start_index])) {
          // Convert to kebab-case (e.g., HttpFoundation → http-foundation).
          $component = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $parts[$start_index]));
          $class_path = implode(DIRECTORY_SEPARATOR, array_slice($parts, $start_index + 1)) . '.php';
        }
      }
    }

    // Extract package name for Doctrine, etc.
    $package = NULL;
    if (str_starts_with($fqcn, 'Doctrine\\')) {
      $doctrine_path = str_replace('Doctrine\\', '', $fqcn);
      $parts = explode('\\', $doctrine_path);
      if (count($parts) >= 1) {
        $package = strtolower($parts[0]);
      }
    }

    // Perform placeholder replacement.
    $replacements = [
      '{cwd}' => $cwd,
      '{path}' => $path,
      '{class_path}' => $class_path ?? '',
      '{module}' => $module ?? '',
      '{module_lower}' => $module_lower ?? '',
      '{drupal_package}' => $drupal_package ?? '',
      '{drupal_submodule}' => $drupal_submodule ?? '',
      '{component}' => $component ?? '',
      '{package}' => $package ?? '',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $pathPattern);
  }

  /**
   * Find dependency file manually using configured search patterns.
   *
   * Uses search patterns from configuration (YAML) with condition matching
   * and placeholder resolution for maximum flexibility.
   *
   * @param string $fqcn
   *   Fully qualified class name.
   *
   * @return string|null
   *   Absolute file path or NULL if not found.
   */
  private function findFileManually(string $fqcn): ?string {
    // If no patterns configured, return NULL (fallback to autoloader only).
    if (empty($this->searchPatterns)) {
      return NULL;
    }

    // Iterate through patterns in order (priority preserved from config).
    foreach ($this->searchPatterns as $pattern) {
      // Evaluate condition - skip if doesn't match.
      if (!$this->evaluateCondition($fqcn, $pattern)) {
        continue;
      }

      // Try all paths for this pattern.
      if (!empty($pattern['paths'])) {
        foreach ($pattern['paths'] as $pathPattern) {
          // Resolve placeholders to get actual file path.
          $resolved_path = $this->resolvePlaceholders($fqcn, $pathPattern);

          // Check if file exists.
          if (file_exists($resolved_path)) {
            return $resolved_path;
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Check if file should be processed based on filters.
   *
   * Applies internal_only check (vendor/) and exclusion patterns.
   * When following dependencies with internal_only=false, vendor/ files
   * are allowed even if vendor/ is in the general exclude list.
   * The internal_only filter only applies to depth > 1 (recursive deps).
   *
   * @param string $filepath
   *   Absolute file path.
   * @param array $excludePaths
   *   Exclusion patterns.
   * @param int $depth
   *   Current recursion depth (1 = direct dependencies, 2+ = recursive).
   *
   * @return bool
   *   TRUE if file should be processed.
   */
  private function shouldProcessFile(string $filepath, array $excludePaths, int $depth = 1): bool {
    // Resolve symbolic links and relative paths (../) to get real path.
    // This prevents false vendor detection when autoloader returns paths like
    // 'vendor/composer/../../web/core/lib/Class.php' which resolve to web/core.
    $resolvedPath = realpath($filepath);
    if ($resolvedPath === FALSE) {
      // If realpath fails (file doesn't exist), use original path.
      $resolvedPath = $filepath;
    }

    $isVendorFile = str_contains($resolvedPath, '/vendor/') ||
      str_contains($resolvedPath, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);

    // Level 1 (direct dependencies) bypass internal_only filter for vendor.
    if ($depth === 1 && $isVendorFile) {
      return TRUE;
    }

    // Apply internal_only filter for depth > 1: skip vendor/.
    if ($this->followDependencies['internal_only'] && $isVendorFile) {
      return FALSE;
    }

    // If internal_only is false and this is a vendor file, allow it
    // even if vendor/ is in the exclude list (for dependency following).
    if (!$this->followDependencies['internal_only'] && $isVendorFile) {
      return TRUE;
    }

    return !$this->isExcluded($resolvedPath, $excludePaths);
  }

  /**
   * Merge two metadata arrays (simple concatenation for follow dependencies).
   *
   * @param array $existing
   *   Existing metadata.
   * @param array $new
   *   New metadata to merge.
   *
   * @return array
   *   Merged metadata.
   */
  private function mergeMetadataArrays(array $existing, array $new): array {
    // Deduplicate by FQCN.
    $existing['classes'] = $this->deduplicateByFqcn(
      array_merge($existing['classes'], $new['classes'])
    );
    $existing['interfaces'] = $this->deduplicateByFqcn(
      array_merge($existing['interfaces'], $new['interfaces'])
    );
    $existing['traits'] = $this->deduplicateByFqcn(
      array_merge($existing['traits'], $new['traits'])
    );
    $existing['enums'] = $this->deduplicateByFqcn(
      array_merge($existing['enums'], $new['enums'])
    );

    // Merge not_found_dependencies (keep latest reason for duplicates).
    $existing['not_found_dependencies'] = array_merge(
      $existing['not_found_dependencies'] ?? [],
      $new['not_found_dependencies'] ?? []
    );

    return $existing;
  }

  /**
   * Deduplicate items by FQCN.
   *
   * @param array $items
   *   Items to deduplicate.
   *
   * @return array
   *   Deduplicated items.
   */
  private function deduplicateByFqcn(array $items): array {
    $seen = [];
    $result = [];

    foreach ($items as $item) {
      $fqcn = $item['fqcn'] ?? NULL;
      if ($fqcn && !isset($seen[$fqcn])) {
        $seen[$fqcn] = TRUE;
        $result[] = $item;
      }
    }

    return $result;
  }

  /**
   * Scan directory for PHP files and extract metadata.
   *
   * @param string $directory
   *   Directory path to scan.
   * @param object $parser
   *   PHP parser instance.
   * @param \PhpParser\NodeTraverser $traverser
   *   AST traverser.
   * @param \ContextExtractor\MetadataVisitor $visitor
   *   Metadata collector.
   * @param array $excludePaths
   *   Paths to exclude from scanning.
   */
  private function scanDirectory(
    string $directory,
    $parser,
    NodeTraverser $traverser,
    MetadataVisitor $visitor,
    array $excludePaths,
  ): void {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator(
        $directory,
        \RecursiveDirectoryIterator::SKIP_DOTS
      ),
      \RecursiveIteratorIterator::SELF_FIRST
    );

    $realDirectory = realpath($directory);

    foreach ($iterator as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }

      $filepath = $file->getPathname();

      // Security: Check for symlink attacks.
      if ($file->isLink()) {
        $realPath = realpath($filepath);
        // Skip symlinks that point outside the scanned directory.
        if ($realPath === FALSE || strpos($realPath, $realDirectory) !== 0) {
          error_log("Skipped symlink outside directory: {$filepath}");
          continue;
        }
      }

      // Check exclusions.
      if ($this->isExcluded($filepath, $excludePaths)) {
        continue;
      }

      // Apply whitelist if defined.
      if (!empty($this->whitelist) && !$this->matchesWhitelist($filepath)) {
        continue;
      }

      try {
        $code = file_get_contents($filepath);
        $stmts = $parser->parse($code);

        if ($stmts) {
          $visitor->setCurrentFile($filepath);
          $traverser->traverse($stmts);
          $this->files[] = $filepath;
        }
      }
      catch (Error $e) {
        // Log parse errors without exposing full paths in production.
        $relativePath = str_replace($realDirectory . DIRECTORY_SEPARATOR, '', $filepath);
        error_log("Parse error in {$relativePath}: " . $e->getMessage());
      }
    }
  }

  /**
   * Check if a file path should be excluded.
   *
   * @param string $filepath
   *   The file path to check.
   * @param array $excludePaths
   *   Array of exclusion patterns.
   *
   * @return bool
   *   TRUE if should be excluded.
   */
  private function isExcluded(string $filepath, array $excludePaths): bool {
    // Normalize path for comparison.
    $normalizedPath = str_replace('\\', '/', $filepath);

    foreach ($excludePaths as $exclude) {
      $exclude = str_replace('\\', '/', $exclude);

      // Support for glob patterns.
      if (strpos($exclude, '*') !== FALSE) {
        if (fnmatch($exclude, $normalizedPath)) {
          return TRUE;
        }
      }
      else {
        // Substring match (original behavior).
        if (str_contains($normalizedPath, $exclude)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Check if file path matches any whitelist pattern using glob matching.
   *
   * Extracts potential FQCN from file path heuristically.
   *
   * @param string $filepath
   *   Absolute file path.
   *
   * @return bool
   *   TRUE if matches whitelist or whitelist is empty.
   */
  private function matchesWhitelist(string $filepath): bool {
    // Extract potential FQCN from file path (heuristic).
    // This is a best-effort approach.
    $relativePath = str_replace([getcwd() . DIRECTORY_SEPARATOR, '.php'], '', $filepath);
    $potentialFqcn = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

    foreach ($this->whitelist as $pattern) {
      if (fnmatch($pattern, $potentialFqcn)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Aggregate metadata from visitor.
   *
   * @param \ContextExtractor\MetadataVisitor $visitor
   *   Metadata visitor instance.
   *
   * @return array
   *   Aggregated metadata.
   */
  private function aggregateMetadata(MetadataVisitor $visitor): array {
    $data = $visitor->getMetadata();

    return [
      'summary' => [
        'files_analyzed' => count($this->files),
        'classes' => count($data['classes']),
        'interfaces' => count($data['interfaces']),
        'traits' => count($data['traits']),
        'enums' => count($data['enums']),
      ],
      'metrics' => $this->calculateMetrics($data),
      'namespaces' => $this->groupByNamespace($data),
      'classes' => $data['classes'],
      'interfaces' => $data['interfaces'],
      'traits' => $data['traits'],
      'enums' => $data['enums'],
      'dependencies' => $this->analyzeDependencies($data),
      'dependency_depths' => $this->dependencyDepths,
      'patterns' => $this->detectPatterns($data),
      'files' => $this->files,
      'not_found_dependencies' => $this->metadata['not_found_dependencies'],
    ];
  }

  /**
   * Group metadata items by namespace.
   *
   * @param array $data
   *   Parsed metadata.
   *
   * @return array
   *   Items grouped by namespace.
   */
  private function groupByNamespace(array $data): array {
    $namespaces = [];

    foreach (['classes', 'interfaces', 'traits', 'enums'] as $type) {
      foreach ($data[$type] as $item) {
        $ns = $item['namespace'] ?? 'global';
        if (!isset($namespaces[$ns])) {
          $namespaces[$ns] = [
            'classes' => [],
            'interfaces' => [],
            'traits' => [],
            'enums' => [],
          ];
        }
        $namespaces[$ns][$type][] = $item['name'];
      }
    }

    ksort($namespaces);
    return $namespaces;
  }

  /**
   * Analyze dependencies between classes.
   *
   * @param array $data
   *   Parsed metadata.
   *
   * @return array
   *   Dependencies organized by target class.
   */
  private function analyzeDependencies(array $data): array {
    $deps = [];

    // Analyze class dependencies.
    foreach ($data['classes'] as $class) {
      $classDeps = array_merge(
        $class['extends'] ?? [],
        $class['implements'] ?? [],
        $class['uses'] ?? [],
        $class['type_dependencies'] ?? []
      );

      foreach ($classDeps as $dep) {
        if (!isset($deps[$dep])) {
          $deps[$dep] = [];
        }
        $deps[$dep][] = $class['fqcn'];
      }
    }

    // Analyze interface dependencies.
    foreach ($data['interfaces'] as $interface) {
      $interfaceDeps = array_merge(
        $interface['extends'] ?? [],
        $interface['type_dependencies'] ?? []
      );

      foreach ($interfaceDeps as $dep) {
        if (!isset($deps[$dep])) {
          $deps[$dep] = [];
        }
        $deps[$dep][] = $interface['fqcn'];
      }
    }

    // Deduplicate usages first.
    $deps = array_map(fn($d) => array_unique($d), $deps);

    // Sort by usage count in descending order.
    uasort($deps, fn($a, $b) => count($b) <=> count($a));

    return $deps;
  }

  /**
   * Analyze built-in PHP class dependencies with usage tracking.
   *
   * Identifies built-in PHP classes referenced by the codebase and tracks
   * which classes use them.
   *
   * @param array $data
   *   Parsed metadata.
   *
   * @return array
   *   Built-in PHP classes with usage information.
   *   Format: ['BuiltinClass' => ['UsingClass1', 'UsingClass2'], ...]
   */
  private function analyzeBuiltinPhpClasses(array $data): array {
    $builtinClasses = [];

    // Only proceed if the feature is enabled.
    if (empty($this->followDependencies['include_builtin_php_classes'])) {
      return $builtinClasses;
    }

    // Analyze class dependencies.
    foreach ($data['classes'] as $class) {
      $classDeps = array_merge(
        $class['extends'] ?? [],
        $class['implements'] ?? [],
        $class['uses'] ?? [],
        $class['type_dependencies'] ?? []
      );

      foreach ($classDeps as $dep) {
        if ($this->isBuiltinPhpClass($dep)) {
          if (!isset($builtinClasses[$dep])) {
            $builtinClasses[$dep] = [];
          }
          $builtinClasses[$dep][] = $class['fqcn'];
        }
      }
    }

    // Analyze interface dependencies.
    foreach ($data['interfaces'] as $interface) {
      $interfaceDeps = array_merge(
        $interface['extends'] ?? [],
        $interface['type_dependencies'] ?? []
      );

      foreach ($interfaceDeps as $dep) {
        if ($this->isBuiltinPhpClass($dep)) {
          if (!isset($builtinClasses[$dep])) {
            $builtinClasses[$dep] = [];
          }
          $builtinClasses[$dep][] = $interface['fqcn'];
        }
      }
    }

    // Analyze trait dependencies.
    foreach ($data['traits'] as $trait) {
      $traitDeps = $trait['type_dependencies'] ?? [];

      foreach ($traitDeps as $dep) {
        if ($this->isBuiltinPhpClass($dep)) {
          if (!isset($builtinClasses[$dep])) {
            $builtinClasses[$dep] = [];
          }
          $builtinClasses[$dep][] = $trait['fqcn'];
        }
      }
    }

    // Analyze enum dependencies.
    foreach ($data['enums'] as $enum) {
      $enumDeps = array_merge(
        $enum['implements'] ?? [],
        $enum['type_dependencies'] ?? []
      );

      foreach ($enumDeps as $dep) {
        if ($this->isBuiltinPhpClass($dep)) {
          if (!isset($builtinClasses[$dep])) {
            $builtinClasses[$dep] = [];
          }
          $builtinClasses[$dep][] = $enum['fqcn'];
        }
      }
    }

    // Deduplicate usages.
    $builtinClasses = array_map(fn($d) => array_unique($d), $builtinClasses);

    // Sort by usage count in descending order.
    uasort($builtinClasses, fn($a, $b) => count($b) <=> count($a));

    return $builtinClasses;
  }

  /**
   * Detect design patterns in classes.
   *
   * @param array $data
   *   Parsed metadata.
   *
   * @return array
   *   Detected patterns organized by type.
   */
  private function detectPatterns(array $data): array {
    $patterns = [];

    foreach ($data['classes'] as $class) {
      // Entity pattern.
      if (str_ends_with($class['name'], 'Entity') ||
        in_array('Doctrine\\ORM\\Mapping', $class['uses'] ?? [])) {
        $patterns['entities'][] = $class['fqcn'];
      }

      // Repository pattern.
      if (str_ends_with($class['name'], 'Repository')) {
        $patterns['repositories'][] = $class['fqcn'];
      }

      // Service pattern.
      if (str_ends_with($class['name'], 'Service')) {
        $patterns['services'][] = $class['fqcn'];
      }

      // Controller pattern.
      if (str_ends_with($class['name'], 'Controller')) {
        $patterns['controllers'][] = $class['fqcn'];
      }

      // Event/Listener pattern.
      if (str_ends_with($class['name'], 'Event') ||
        str_ends_with($class['name'], 'Listener')) {
        $patterns['events'][] = $class['fqcn'];
      }

      // Factory pattern.
      if (str_ends_with($class['name'], 'Factory')) {
        $patterns['factories'][] = $class['fqcn'];
      }

      // Builder pattern.
      if (str_ends_with($class['name'], 'Builder')) {
        $patterns['builders'][] = $class['fqcn'];
      }
    }

    return $patterns;
  }

  /**
   * Calculate codebase metrics: cyclomatic complexity, coupling scores, etc.
   *
   * @param array $data
   *   Parsed metadata from MetadataVisitor.
   *
   * @return array
   *   Metrics array.
   */
  private function calculateMetrics(array $data): array {
    $totalComplexity = 0;
    $methodCount = 0;
    $maxComplexity = 0;
    $maxComplexityClass = '';

    // Calculate cyclomatic complexity per class.
    $classComplexities = [];
    foreach ($data['classes'] as $class) {
      $classComplexity = 0;
      if (!empty($class['methods'])) {
        foreach ($class['methods'] as $method) {
          $complexity = $this->calculateMethodComplexity($method);
          $classComplexity += $complexity;
          $totalComplexity += $complexity;
          $methodCount++;
        }
      }
      $classComplexities[$class['fqcn']] = $classComplexity;

      if ($classComplexity > $maxComplexity) {
        $maxComplexity = $classComplexity;
        $maxComplexityClass = $class['fqcn'];
      }
    }

    // Sort by complexity and get top 10.
    arsort($classComplexities);
    $topComplexClasses = array_slice($classComplexities, 0, 10, TRUE);

    // Calculate coupling scores (afferent/efferent coupling).
    $couplingScores = $this->calculateCouplingScores($data);

    // Calculate average complexity.
    $avgComplexity = $methodCount > 0 ? round($totalComplexity / $methodCount, 2) : 0;

    return [
      'cyclomatic_complexity' => [
        'total' => $totalComplexity,
        'average_per_method' => $avgComplexity,
        'max_class' => $maxComplexityClass,
        'max_class_score' => $maxComplexity,
        'top_complex_classes' => $topComplexClasses,
      ],
      'coupling' => $couplingScores,
    ];
  }

  /**
   * Calculate cyclomatic complexity for a single method.
   *
   * Complexity = 1 + number of decision points (if, for, while, case, etc.).
   *
   * @param array $method
   *   Method metadata with body_patterns.
   *
   * @return int
   *   Complexity score.
   */
  private function calculateMethodComplexity(array $method): int {
    // Base complexity.
    $complexity = 1;

    if (!empty($method['body_patterns']['control_flow'])) {
      // Each control flow statement adds 1 to complexity.
      $complexity += count($method['body_patterns']['control_flow']);
    }

    return $complexity;
  }

  /**
   * Calculate coupling scores for classes.
   *
   * Afferent coupling (Ca): Number of classes that depend on this class.
   * Efferent coupling (Ce): Number of classes this class depends on.
   * Instability (I): Ce / (Ca + Ce) - range 0 (stable) to 1 (unstable).
   *
   * @param array $data
   *   Parsed metadata.
   *
   * @return array
   *   Coupling scores per class.
   */
  private function calculateCouplingScores(array $data): array {
    // Build dependency graph:
    // Who depends on me.
    $afferent = [];
    // Who I depend on.
    $efferent = [];

    foreach ($data['classes'] as $class) {
      $fqcn = $class['fqcn'];
      $efferent[$fqcn] = [];

      // Collect efferent coupling (outgoing dependencies).
      $deps = array_merge(
        $class['extends'] ?? [],
        $class['implements'] ?? [],
        $class['type_dependencies'] ?? []
      );

      foreach ($deps as $dep) {
        if (!isset($efferent[$fqcn])) {
          $efferent[$fqcn] = [];
        }
        $efferent[$fqcn][] = $dep;

        // Build afferent coupling (incoming dependencies).
        if (!isset($afferent[$dep])) {
          $afferent[$dep] = [];
        }
        $afferent[$dep][] = $fqcn;
      }
    }

    // Calculate instability for each class.
    $coupling = [];
    foreach ($data['classes'] as $class) {
      $fqcn = $class['fqcn'];
      $ca = count($afferent[$fqcn] ?? []);
      $ce = count(array_unique($efferent[$fqcn] ?? []));
      $total = $ca + $ce;
      $instability = $total > 0 ? round($ce / $total, 2) : 0;

      $coupling[$fqcn] = [
        'afferent' => $ca,
        'efferent' => $ce,
        'instability' => $instability,
      ];
    }

    // Sort by instability (descending) and get top 10 most unstable.
    uasort($coupling, fn($a, $b) => $b['instability'] <=> $a['instability']);
    $mostUnstable = array_slice($coupling, 0, 10, TRUE);

    // Get top 10 most coupled (highest afferent + efferent).
    $couplingScores = [];
    foreach ($coupling as $fqcn => $scores) {
      $couplingScores[$fqcn] = $scores['afferent'] + $scores['efferent'];
    }
    arsort($couplingScores);
    $mostCoupled = array_slice($couplingScores, 0, 10, TRUE);

    return [
      'most_unstable' => $mostUnstable,
      'most_coupled' => $mostCoupled,
      'average_instability' => count($coupling) > 0
        ? round(array_sum(array_column($coupling, 'instability')) / count($coupling), 2) : 0,
    ];
  }

}
