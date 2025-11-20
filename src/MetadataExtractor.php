<?php

namespace ContextExtractor;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class MetadataExtractor
{
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
    ];

    private array $files = [];

    public function extractFromDirectory(string $directory, array $excludePaths = []): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $nameResolver = new NameResolver();
        $visitor = new MetadataVisitor();

        $traverser->addVisitor($nameResolver);
        $traverser->addVisitor($visitor);

        $this->scanDirectory($directory, $parser, $traverser, $visitor, $excludePaths);

        return $this->aggregateMetadata($visitor);
    }

    private function scanDirectory(
        string $directory,
        $parser,
        NodeTraverser $traverser,
        MetadataVisitor $visitor,
        array $excludePaths
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

            // Security: Check for symlink attacks
            if ($file->isLink()) {
                $realPath = realpath($filepath);
                // Skip symlinks that point outside the scanned directory
                if ($realPath === false || strpos($realPath, $realDirectory) !== 0) {
                    error_log("Skipped symlink outside directory: {$filepath}");
                    continue;
                }
            }

            // Check exclusions with improved logic
            if ($this->isExcluded($filepath, $excludePaths)) {
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
            } catch (Error $e) {
                // Log parse errors without exposing full paths in production
                $relativePath = str_replace($realDirectory . DIRECTORY_SEPARATOR, '', $filepath);
                error_log("Parse error in {$relativePath}: " . $e->getMessage());
            }
        }
    }

    /**
     * Check if a file path should be excluded.
     *
     * @param string $filepath The file path to check
     * @param array $excludePaths Array of exclusion patterns
     * @return bool True if should be excluded
     */
    private function isExcluded(string $filepath, array $excludePaths): bool
    {
        // Normalize path for comparison
        $normalizedPath = str_replace('\\', '/', $filepath);

        foreach ($excludePaths as $exclude) {
            $exclude = str_replace('\\', '/', $exclude);

            // Support for glob patterns
            if (strpos($exclude, '*') !== false) {
                if (fnmatch($exclude, $normalizedPath)) {
                    return true;
                }
            } else {
                // Substring match (original behavior)
                if (str_contains($normalizedPath, $exclude)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function aggregateMetadata(MetadataVisitor $visitor): array
    {
        $data = $visitor->getMetadata();
        
        return [
            'summary' => [
                'files_analyzed' => count($this->files),
                'classes' => count($data['classes']),
                'interfaces' => count($data['interfaces']),
                'traits' => count($data['traits']),
                'enums' => count($data['enums']),
            ],
            'namespaces' => $this->groupByNamespace($data),
            'classes' => $data['classes'],
            'interfaces' => $data['interfaces'],
            'traits' => $data['traits'],
            'enums' => $data['enums'],
            'dependencies' => $this->analyzeDependencies($data),
            'patterns' => $this->detectPatterns($data),
            'files' => $this->files,
        ];
    }

    private function groupByNamespace(array $data): array
    {
        $namespaces = [];
        
        foreach (['classes', 'interfaces', 'traits', 'enums'] as $type) {
            foreach ($data[$type] as $item) {
                $ns = $item['namespace'] ?? 'global';
                if (!isset($namespaces[$ns])) {
                    $namespaces[$ns] = ['classes' => [], 'interfaces' => [], 'traits' => [], 'enums' => []];
                }
                $namespaces[$ns][$type][] = $item['name'];
            }
        }
        
        ksort($namespaces);
        return $namespaces;
    }

    private function analyzeDependencies(array $data): array
    {
        $deps = [];

        foreach ($data['classes'] as $class) {
            $classDeps = array_merge(
                $class['extends'] ?? [],
                $class['implements'] ?? [],
                $class['uses'] ?? []
            );

            foreach ($classDeps as $dep) {
                if (!isset($deps[$dep])) {
                    $deps[$dep] = [];
                }
                $deps[$dep][] = $class['fqcn'];
            }
        }

        // Deduplicate usages first
        $deps = array_map(fn($d) => array_unique($d), $deps);

        // Sort by usage count in descending order
        uasort($deps, fn($a, $b) => count($b) <=> count($a));

        return $deps;
    }

    private function detectPatterns(array $data): array
    {
        $patterns = [];
        
        foreach ($data['classes'] as $class) {
            // Entity pattern
            if (str_ends_with($class['name'], 'Entity') || 
                in_array('Doctrine\\ORM\\Mapping', $class['uses'] ?? [])) {
                $patterns['entities'][] = $class['fqcn'];
            }
            
            // Repository pattern
            if (str_ends_with($class['name'], 'Repository')) {
                $patterns['repositories'][] = $class['fqcn'];
            }
            
            // Service pattern
            if (str_ends_with($class['name'], 'Service')) {
                $patterns['services'][] = $class['fqcn'];
            }
            
            // Controller pattern
            if (str_ends_with($class['name'], 'Controller')) {
                $patterns['controllers'][] = $class['fqcn'];
            }
            
            // Event/Listener pattern
            if (str_ends_with($class['name'], 'Event') || str_ends_with($class['name'], 'Listener')) {
                $patterns['events'][] = $class['fqcn'];
            }
            
            // Factory pattern
            if (str_ends_with($class['name'], 'Factory')) {
                $patterns['factories'][] = $class['fqcn'];
            }
            
            // Builder pattern
            if (str_ends_with($class['name'], 'Builder')) {
                $patterns['builders'][] = $class['fqcn'];
            }
        }
        
        return $patterns;
    }
}
