<?php

namespace ContextExtractor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Metadata visitor for extracting PHP code structure information.
 *
 * Traverses PHP AST to extract classes, interfaces, traits, enums and their
 * associated metadata (methods, properties, dependencies, etc).
 */
class MetadataVisitor extends NodeVisitorAbstract {

  /**
   * Metadata storage array.
   *
   * @var array
   */
  private array $metadata = [
    'classes' => [],
    'interfaces' => [],
    'traits' => [],
    'enums' => [],
  ];

  /**
   * Current file being processed.
   *
   * @var string|null
   */
  private ?string $currentFile = NULL;

  /**
   * Current namespace context.
   *
   * @var string|null
   */
  private ?string $currentNamespace = NULL;

  /**
   * Current use statements.
   *
   * @var array
   */
  private array $currentUses = [];

  /**
   * Set the current file being processed.
   *
   * @param string $file
   *   The file path.
   */
  public function setCurrentFile(string $file): void {
    $this->currentFile = $file;
    $this->currentNamespace = NULL;
    $this->currentUses = [];
  }

  /**
   * Enter node visitor callback.
   *
   * @param \PhpParser\Node $node
   *   The node being visited.
   *
   * @return int|null
   *   Traverser control constant or NULL.
   */
  public function enterNode(Node $node) {
    if ($node instanceof Node\Stmt\Namespace_) {
      $this->currentNamespace = $node->name ? $node->name->toString() : NULL;
    }
    elseif ($node instanceof Node\Stmt\Use_) {
      foreach ($node->uses as $use) {
        $alias = $use->alias ? $use->alias->toString() : $use->name->getLast();
        $this->currentUses[$alias] = $use->name->toString();
      }
    }
    elseif ($node instanceof Node\Stmt\Class_) {
      $this->extractClass($node);
      // Don't traverse children - we handle everything in extractClass.
      return NodeTraverser::DONT_TRAVERSE_CHILDREN;
    }
    elseif ($node instanceof Node\Stmt\Interface_) {
      $this->extractInterface($node);
      // Don't traverse children - we handle everything in extractInterface.
      return NodeTraverser::DONT_TRAVERSE_CHILDREN;
    }
    elseif ($node instanceof Node\Stmt\Trait_) {
      $this->extractTrait($node);
      // Don't traverse children - we handle everything in extractTrait.
      return NodeTraverser::DONT_TRAVERSE_CHILDREN;
    }
    elseif ($node instanceof Node\Stmt\Enum_) {
      $this->extractEnum($node);
      // Don't traverse children - we handle everything in extractEnum.
      return NodeTraverser::DONT_TRAVERSE_CHILDREN;
    }

    return NULL;
  }

  /**
   * Extract class metadata.
   *
   * @param \PhpParser\Node\Stmt\Class_ $node
   *   The class node.
   */
  private function extractClass(Node\Stmt\Class_ $node): void {
    $name = $node->name->toString();
    $fqcn = $this->getFQCN($name);

    $class_data = [
      'name' => $name,
      'fqcn' => $fqcn,
      'namespace' => $this->currentNamespace,
      'file' => $this->currentFile,
      'abstract' => $node->isAbstract(),
      'final' => $node->isFinal(),
      'uses' => array_values($this->currentUses),
    ];

    // Extract docblock.
    if ($node->getDocComment()) {
      $class_data['docblock'] = $this->extractDocblock($node->getDocComment());
    }

    // Extract attributes (PHP 8+).
    if ($node->attrGroups) {
      $class_data['attributes'] = $this->extractAttributes($node->attrGroups);
    }

    // Extends.
    if ($node->extends) {
      $class_data['extends'] = [$this->resolveName($node->extends)];
    }

    // Implements.
    if ($node->implements) {
      $class_data['implements'] = array_map(
        fn($i) => $this->resolveName($i),
        $node->implements
      );
    }

    // Traits.
    $traits = [];
    foreach ($node->getTraitUses() as $trait_use) {
      foreach ($trait_use->traits as $trait) {
        $traits[] = $this->resolveName($trait);
      }
    }
    if ($traits) {
      $class_data['traits_used'] = $traits;
    }

    // Methods with full defaults.
    $methods = [];
    $public_methods = [];
    foreach ($node->getMethods() as $method) {
      $method_data = $this->extractMethod($method);
      $methods[] = $method_data;

      // Track public methods for API surface.
      if ($method_data['visibility'] === 'public' &&
        !in_array($method_data['name'], ['__construct', '__destruct', '__clone'])) {
        $public_methods[] = $method_data['name'];
      }
    }
    if ($methods) {
      $class_data['methods'] = $methods;
    }
    if ($public_methods) {
      $class_data['public_api'] = $public_methods;
    }

    // Extract constructor injection order.
    foreach ($methods as $method) {
      if ($method['name'] === '__construct' && !empty($method['params'])) {
        $injection_order = [];
        foreach ($method['params'] as $param) {
          if (isset($param['type']) && !in_array($param['type'], ['array', 'string', 'int', 'bool', 'float', 'mixed'])) {
            $injection_order[] = $param['type'];
          }
        }
        if ($injection_order) {
          $class_data['constructor_injection'] = $injection_order;
        }
        break;
      }
    }

    // Properties.
    $properties = [];
    foreach ($node->getProperties() as $prop) {
      $properties[] = $this->extractProperty($prop);
    }
    if ($properties) {
      $class_data['properties'] = $properties;
    }

    // Constants with values.
    $constants = [];
    foreach ($node->getConstants() as $const) {
      foreach ($const->consts as $c) {
        $constant_data = [
          'name' => $c->name->toString(),
          'value' => $this->getConstantValue($c->value),
        ];
        if ($const->getDocComment()) {
          $constant_data['docblock'] = $this->extractDocblock($const->getDocComment());
        }
        $constants[] = $constant_data;
      }
    }
    if ($constants) {
      $class_data['constants'] = $constants;
    }

    // Type hints and dependencies from method signatures.
    $class_data['type_dependencies'] = $this->extractTypeDependencies($node);

    // Extract magic values (string/numeric literals).
    $class_data['magic_values'] = $this->extractMagicValues($node);

    $this->metadata['classes'][] = $class_data;
  }

  /**
   * Extract interface metadata.
   *
   * @param \PhpParser\Node\Stmt\Interface_ $node
   *   The interface node.
   */
  private function extractInterface(Node\Stmt\Interface_ $node): void {
    $name = $node->name->toString();
    $fqcn = $this->getFQCN($name);

    $data = [
      'name' => $name,
      'fqcn' => $fqcn,
      'namespace' => $this->currentNamespace,
      'file' => $this->currentFile,
      'uses' => array_values($this->currentUses),
    ];

    // Extract docblock.
    if ($node->getDocComment()) {
      $data['docblock'] = $this->extractDocblock($node->getDocComment());
    }

    // Extract attributes.
    if ($node->attrGroups) {
      $data['attributes'] = $this->extractAttributes($node->attrGroups);
    }

    if ($node->extends) {
      $data['extends'] = array_map(
        fn($e) => $this->resolveName($e),
        $node->extends
      );
    }

    $methods = [];
    foreach ($node->getMethods() as $method) {
      $methods[] = $this->extractMethod($method);
    }
    if ($methods) {
      $data['methods'] = $methods;
    }

    $this->metadata['interfaces'][] = $data;
  }

  /**
   * Extract trait metadata.
   *
   * @param \PhpParser\Node\Stmt\Trait_ $node
   *   The trait node.
   */
  private function extractTrait(Node\Stmt\Trait_ $node): void {
    $name = $node->name->toString();
    $fqcn = $this->getFQCN($name);

    $data = [
      'name' => $name,
      'fqcn' => $fqcn,
      'namespace' => $this->currentNamespace,
      'file' => $this->currentFile,
      'uses' => array_values($this->currentUses),
    ];

    // Extract docblock.
    if ($node->getDocComment()) {
      $data['docblock'] = $this->extractDocblock($node->getDocComment());
    }

    $methods = [];
    foreach ($node->getMethods() as $method) {
      $methods[] = $this->extractMethod($method);
    }
    if ($methods) {
      $data['methods'] = $methods;
    }

    $this->metadata['traits'][] = $data;
  }

  /**
   * Extract enum metadata.
   *
   * @param \PhpParser\Node\Stmt\Enum_ $node
   *   The enum node.
   */
  private function extractEnum(Node\Stmt\Enum_ $node): void {
    $name = $node->name->toString();
    $fqcn = $this->getFQCN($name);

    $data = [
      'name' => $name,
      'fqcn' => $fqcn,
      'namespace' => $this->currentNamespace,
      'file' => $this->currentFile,
      'type' => $node->scalarType ? $node->scalarType->toString() : NULL,
      'uses' => array_values($this->currentUses),
    ];

    if ($node->implements) {
      $data['implements'] = array_map(
        fn($i) => $this->resolveName($i),
        $node->implements
      );
    }

    $cases = [];
    foreach ($node->stmts as $stmt) {
      if ($stmt instanceof Node\Stmt\EnumCase) {
        $cases[] = $stmt->name->toString();
      }
    }
    if ($cases) {
      $data['cases'] = $cases;
    }

    $this->metadata['enums'][] = $data;
  }

  /**
   * Extract method metadata.
   *
   * @param \PhpParser\Node\Stmt\ClassMethod $method
   *   The method node.
   *
   * @return array
   *   Method metadata.
   */
  private function extractMethod(Node\Stmt\ClassMethod $method): array {
    $data = [
      'name' => $method->name->toString(),
      'visibility' => $this->getVisibility($method),
      'static' => $method->isStatic(),
      'abstract' => $method->isAbstract(),
      'final' => $method->isFinal(),
    ];

    // Extract docblock.
    if ($method->getDocComment()) {
      $data['docblock'] = $this->extractDocblock($method->getDocComment());
    }

    // Extract attributes.
    if ($method->attrGroups) {
      $data['attributes'] = $this->extractAttributes($method->attrGroups);
    }

    // Parameters with full defaults.
    $params = [];
    foreach ($method->params as $param) {
      $param_data = ['name' => '$' . $param->var->name];

      if ($param->type) {
        $param_data['type'] = $this->getTypeString($param->type);
      }

      if ($param->default) {
        $param_data['default'] = $this->getConstantValue($param->default);
      }

      if ($param->variadic) {
        $param_data['variadic'] = TRUE;
      }

      $params[] = $param_data;
    }
    if ($params) {
      $data['params'] = $params;
    }

    // Return type.
    if ($method->returnType) {
      $data['return'] = $this->getTypeString($method->returnType);
    }

    // Extract method body patterns.
    if ($method->stmts) {
      $data['body_patterns'] = $this->extractMethodBodyPatterns($method->stmts);
    }

    return $data;
  }

  /**
   * Extract property metadata.
   *
   * @param \PhpParser\Node\Stmt\Property $property
   *   The property node.
   *
   * @return array
   *   Property metadata.
   */
  private function extractProperty(Node\Stmt\Property $property): array {
    $prop = $property->props[0];

    $data = [
      'name' => '$' . $prop->name->toString(),
      'visibility' => $this->getVisibility($property),
      'static' => $property->isStatic(),
    ];

    if ($property->type) {
      $data['type'] = $this->getTypeString($property->type);
    }

    // Extract default value.
    if ($prop->default) {
      $data['default'] = $this->getConstantValue($prop->default);
    }

    // Extract docblock.
    if ($property->getDocComment()) {
      $data['docblock'] = $this->extractDocblock($property->getDocComment());
    }

    return $data;
  }

  /**
   * Extract type dependencies from class methods.
   *
   * @param \PhpParser\Node\Stmt\Class_ $node
   *   The class node.
   *
   * @return array
   *   Type dependencies.
   */
  private function extractTypeDependencies(Node\Stmt\Class_ $node): array {
    $deps = [];

    foreach ($node->getMethods() as $method) {
      // Check parameters.
      foreach ($method->params as $param) {
        if ($param->type instanceof Node\Name) {
          // Skip PHP keywords (self, static, parent).
          if (!$this->isPhpKeyword($param->type)) {
            $resolved = $this->resolveName($param->type);
            $deps[] = $resolved;
          }
        }
      }

      // Check return type.
      if ($method->returnType instanceof Node\Name) {
        // Skip PHP keywords (self, static, parent).
        if (!$this->isPhpKeyword($method->returnType)) {
          $resolved = $this->resolveName($method->returnType);
          $deps[] = $resolved;
        }
      }

      // Check for instanceof in method body.
      if ($method->stmts) {
        $this->findInstanceOf($method->stmts, $deps);
      }

      // Check for new ClassName() instantiations in method body.
      if ($method->stmts) {
        $this->findNewInstantiations($method->stmts, $deps);
      }
    }

    $unique = array_unique($deps);

    return $unique;
  }

  /**
   * Extract magic values (array keys, string literals) from class methods.
   *
   * @param \PhpParser\Node\Stmt\Class_ $node
   *   The class node.
   *
   * @return array
   *   Magic values found.
   */
  private function extractMagicValues(Node\Stmt\Class_ $node): array {
    $magic_values = [
      'array_keys' => [],
    ];

    foreach ($node->getMethods() as $method) {
      if ($method->stmts) {
        $this->findMagicValues($method->stmts, $magic_values);
      }
    }

    // Count occurrences and filter.
    $result = [];

    // Common array keys.
    $keys_counts = array_count_values($magic_values['array_keys']);
    arsort($keys_counts);
    if ($keys_counts) {
      $result['array_keys'] = array_slice(array_keys($keys_counts), 0, 10);
    }

    return $result;
  }

  /**
   * Recursively find magic values in statement array.
   *
   * @param array $stmts
   *   Statements to scan.
   * @param array $magic_values
   *   Reference to magic values array.
   */
  private function findMagicValues(array $stmts, array &$magic_values): void {
    foreach ($stmts as $stmt) {
      if ($stmt === NULL) {
        continue;
      }

      $this->scanNodeForMagicValues($stmt, $magic_values);
    }
  }

  /**
   * Scan a node for magic values (array keys, string literals).
   *
   * @param \PhpParser\Node $node
   *   Node to scan.
   * @param array $magic_values
   *   Reference to magic values array.
   */
  private function scanNodeForMagicValues(Node $node, array &$magic_values): void {
    // Array key access.
    if ($node instanceof Node\Expr\ArrayDimFetch) {
      if ($node->dim instanceof Node\Scalar\String_) {
        $magic_values['array_keys'][] = $node->dim->value;
      }
      // Recurse into the array variable.
      if ($node->var instanceof Node) {
        $this->scanNodeForMagicValues($node->var, $magic_values);
      }
    }

    // Array keys in assignments.
    if ($node instanceof Node\Expr\Array_) {
      foreach ($node->items as $item) {
        if ($item && $item->key instanceof Node\Scalar\String_) {
          $magic_values['array_keys'][] = $item->key->value;
        }
        if ($item && $item->value instanceof Node) {
          $this->scanNodeForMagicValues($item->value, $magic_values);
        }
      }
    }

    // Recurse into statement containers only.
    if ($node instanceof Node\Stmt\If_) {
      if ($node->stmts) {
        $this->findMagicValues($node->stmts, $magic_values);
      }
      if ($node->elseifs) {
        foreach ($node->elseifs as $elseif) {
          if ($elseif->stmts) {
            $this->findMagicValues($elseif->stmts, $magic_values);
          }
        }
      }
      if ($node->else && $node->else->stmts) {
        $this->findMagicValues($node->else->stmts, $magic_values);
      }
    }
    elseif ($node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\For_ ||
            $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_) {
      if ($node->stmts) {
        $this->findMagicValues($node->stmts, $magic_values);
      }
    }
    elseif ($node instanceof Node\Stmt\Switch_) {
      if ($node->cases) {
        foreach ($node->cases as $case) {
          if ($case->stmts) {
            $this->findMagicValues($case->stmts, $magic_values);
          }
        }
      }
    }
    elseif ($node instanceof Node\Stmt\TryCatch) {
      if ($node->stmts) {
        $this->findMagicValues($node->stmts, $magic_values);
      }
      if ($node->catches) {
        foreach ($node->catches as $catch) {
          if ($catch->stmts) {
            $this->findMagicValues($catch->stmts, $magic_values);
          }
        }
      }
      if ($node->finally && $node->finally->stmts) {
        $this->findMagicValues($node->finally->stmts, $magic_values);
      }
    }
    elseif ($node instanceof Node\Stmt\Expression) {
      // Scan the expression inside.
      if ($node->expr instanceof Node) {
        $this->scanNodeForMagicValues($node->expr, $magic_values);
      }
    }
    elseif ($node instanceof Node\Stmt\Return_ || $node instanceof Node\Stmt\Throw_) {
      // Scan the expression being returned or thrown.
      if ($node->expr instanceof Node) {
        $this->scanNodeForMagicValues($node->expr, $magic_values);
      }
    }
    elseif ($node instanceof Node\Expr\Assign) {
      // Scan the right side of assignment.
      if ($node->expr instanceof Node) {
        $this->scanNodeForMagicValues($node->expr, $magic_values);
      }
    }
    elseif ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\FuncCall ||
            $node instanceof Node\Expr\StaticCall) {
      // Scan arguments for array keys.
      if ($node->args) {
        foreach ($node->args as $arg) {
          // Skip VariadicPlaceholder nodes (PHP 8.1+ named arguments).
          if ($arg instanceof Node\Arg && $arg->value instanceof Node) {
            $this->scanNodeForMagicValues($arg->value, $magic_values);
          }
        }
      }
    }
  }

  /**
   * Find instanceof expressions in statements.
   *
   * @param array $stmts
   *   Statements to scan.
   * @param array $deps
   *   Reference to dependencies array.
   */
  private function findInstanceOf(array $stmts, array &$deps): void {
    foreach ($stmts as $stmt) {
      if ($stmt === NULL) {
        continue;
      }

      $this->scanNodeForInstanceOf($stmt, $deps);
    }
  }

  /**
   * Scan a node for instanceof expressions.
   *
   * @param \PhpParser\Node $node
   *   Node to scan.
   * @param array $deps
   *   Reference to dependencies array.
   */
  private function scanNodeForInstanceOf(Node $node, array &$deps): void {
    // Check if this is an instanceof expression.
    if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
      // Skip PHP keywords (self, static, parent).
      if (!$this->isPhpKeyword($node->class)) {
        $deps[] = $this->resolveName($node->class);
      }
    }

    // Recurse into statement containers only.
    if ($node instanceof Node\Stmt\If_) {
      if ($node->stmts) {
        $this->findInstanceOf($node->stmts, $deps);
      }
      if ($node->elseifs) {
        foreach ($node->elseifs as $elseif) {
          if ($elseif->stmts) {
            $this->findInstanceOf($elseif->stmts, $deps);
          }
        }
      }
      if ($node->else && $node->else->stmts) {
        $this->findInstanceOf($node->else->stmts, $deps);
      }
      // Check the condition expression as well.
      if ($node->cond instanceof Node) {
        $this->scanNodeForInstanceOf($node->cond, $deps);
      }
    }
    elseif ($node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\For_ ||
            $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_) {
      if ($node->stmts) {
        $this->findInstanceOf($node->stmts, $deps);
      }
    }
    elseif ($node instanceof Node\Stmt\Switch_) {
      if ($node->cases) {
        foreach ($node->cases as $case) {
          if ($case->stmts) {
            $this->findInstanceOf($case->stmts, $deps);
          }
        }
      }
    }
    elseif ($node instanceof Node\Stmt\TryCatch) {
      if ($node->stmts) {
        $this->findInstanceOf($node->stmts, $deps);
      }
      if ($node->catches) {
        foreach ($node->catches as $catch) {
          if ($catch->stmts) {
            $this->findInstanceOf($catch->stmts, $deps);
          }
        }
      }
      if ($node->finally && $node->finally->stmts) {
        $this->findInstanceOf($node->finally->stmts, $deps);
      }
    }
    elseif ($node instanceof Node\Stmt\Expression) {
      // Scan the expression inside.
      if ($node->expr instanceof Node) {
        $this->scanNodeForInstanceOf($node->expr, $deps);
      }
    }
    elseif ($node instanceof Node\Stmt\Return_ || $node instanceof Node\Stmt\Throw_) {
      // Scan the expression being returned or thrown.
      if ($node->expr instanceof Node) {
        $this->scanNodeForInstanceOf($node->expr, $deps);
      }
    }
    elseif ($node instanceof Node\Expr\BinaryOp\BooleanAnd ||
            $node instanceof Node\Expr\BinaryOp\BooleanOr ||
            $node instanceof Node\Expr\BinaryOp\LogicalAnd ||
            $node instanceof Node\Expr\BinaryOp\LogicalOr) {
      // Only recurse into logical binary operations where instanceof might appear.
      if ($node->left instanceof Node) {
        $this->scanNodeForInstanceOf($node->left, $deps);
      }
      if ($node->right instanceof Node) {
        $this->scanNodeForInstanceOf($node->right, $deps);
      }
    }
    elseif ($node instanceof Node\Expr\Ternary) {
      // Check ternary condition and branches.
      if ($node->cond instanceof Node) {
        $this->scanNodeForInstanceOf($node->cond, $deps);
      }
      if ($node->if instanceof Node) {
        $this->scanNodeForInstanceOf($node->if, $deps);
      }
      if ($node->else instanceof Node) {
        $this->scanNodeForInstanceOf($node->else, $deps);
      }
    }
  }

  /**
   * Find new ClassName() instantiations in statements.
   *
   * @param array $stmts
   *   Array of statements.
   * @param array $deps
   *   Reference to dependencies array.
   */
  private function findNewInstantiations(array $stmts, array &$deps): void {
    foreach ($stmts as $stmt) {
      if ($stmt === NULL) {
        continue;
      }

      $this->scanNodeForNewInstantiations($stmt, $deps);
    }
  }

  /**
   * Scan a node for new ClassName() expressions.
   *
   * @param \PhpParser\Node $node
   *   Node to scan.
   * @param array $deps
   *   Reference to dependencies array.
   */
  private function scanNodeForNewInstantiations(Node $node, array &$deps): void {
    // Check if this is a new expression.
    if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
      // Skip PHP keywords (self, static, parent).
      if (!$this->isPhpKeyword($node->class)) {
        $deps[] = $this->resolveName($node->class);
      }
    }

    // Recurse into statement containers.
    if ($node instanceof Node\Stmt\If_) {
      if ($node->stmts) {
        $this->findNewInstantiations($node->stmts, $deps);
      }
      if ($node->elseifs) {
        foreach ($node->elseifs as $elseif) {
          if ($elseif->stmts) {
            $this->findNewInstantiations($elseif->stmts, $deps);
          }
        }
      }
      if ($node->else && $node->else->stmts) {
        $this->findNewInstantiations($node->else->stmts, $deps);
      }
      // Check the condition expression as well.
      if ($node->cond instanceof Node) {
        $this->scanNodeForNewInstantiations($node->cond, $deps);
      }
    }
    elseif ($node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\For_ ||
            $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_) {
      if ($node->stmts) {
        $this->findNewInstantiations($node->stmts, $deps);
      }
    }
    elseif ($node instanceof Node\Stmt\Switch_) {
      if ($node->cases) {
        foreach ($node->cases as $case) {
          if ($case->stmts) {
            $this->findNewInstantiations($case->stmts, $deps);
          }
        }
      }
    }
    elseif ($node instanceof Node\Stmt\TryCatch) {
      if ($node->stmts) {
        $this->findNewInstantiations($node->stmts, $deps);
      }
      if ($node->catches) {
        foreach ($node->catches as $catch) {
          if ($catch->stmts) {
            $this->findNewInstantiations($catch->stmts, $deps);
          }
        }
      }
      if ($node->finally && $node->finally->stmts) {
        $this->findNewInstantiations($node->finally->stmts, $deps);
      }
    }
    elseif ($node instanceof Node\Stmt\Expression) {
      // Scan the expression inside.
      if ($node->expr instanceof Node) {
        $this->scanNodeForNewInstantiations($node->expr, $deps);
      }
    }
    elseif ($node instanceof Node\Stmt\Return_ || $node instanceof Node\Stmt\Throw_) {
      // Scan the expression being returned or thrown.
      if ($node->expr instanceof Node) {
        $this->scanNodeForNewInstantiations($node->expr, $deps);
      }
    }
    elseif ($node instanceof Node\Expr\Assign ||
            $node instanceof Node\Expr\AssignOp) {
      // Check assignment expressions.
      if (property_exists($node, 'expr') && $node->expr instanceof Node) {
        $this->scanNodeForNewInstantiations($node->expr, $deps);
      }
    }
    elseif ($node instanceof Node\Expr\Ternary) {
      // Check ternary condition and branches.
      if ($node->cond instanceof Node) {
        $this->scanNodeForNewInstantiations($node->cond, $deps);
      }
      if ($node->if instanceof Node) {
        $this->scanNodeForNewInstantiations($node->if, $deps);
      }
      if ($node->else instanceof Node) {
        $this->scanNodeForNewInstantiations($node->else, $deps);
      }
    }
  }

  /**
   * Get visibility modifier for a node.
   *
   * @param object $node
   *   The node to check.
   *
   * @return string
   *   Visibility level.
   */
  private function getVisibility($node): string {
    if ($node->isPublic()) {
      return 'public';
    }
    if ($node->isProtected()) {
      return 'protected';
    }
    if ($node->isPrivate()) {
      return 'private';
    }
    return 'public';
  }

  /**
   * Get type string representation.
   *
   * @param object $type
   *   The type node.
   *
   * @return string
   *   Type string.
   */
  private function getTypeString($type): string {
    if ($type instanceof Node\Name) {
      return $this->resolveName($type);
    }
    elseif ($type instanceof Node\Identifier) {
      return $type->toString();
    }
    elseif ($type instanceof Node\UnionType) {
      return implode('|', array_map(fn($t) => $this->getTypeString($t), $type->types));
    }
    elseif ($type instanceof Node\IntersectionType) {
      return implode('&', array_map(fn($t) => $this->getTypeString($t), $type->types));
    }
    elseif ($type instanceof Node\NullableType) {
      return '?' . $this->getTypeString($type->type);
    }
    return 'mixed';
  }

  /**
   * Check if a Name node represents a PHP keyword (self, static, parent).
   *
   * @param \PhpParser\Node\Name $name
   *   The name node to check.
   *
   * @return bool
   *   TRUE if the name is a PHP keyword, FALSE otherwise.
   */
  private function isPhpKeyword(Node\Name $name): bool {
    $nameStr = $name->toString();
    return in_array(strtolower($nameStr), ['self', 'static', 'parent']);
  }

  /**
   * Resolve a name node to string.
   *
   * @param \PhpParser\Node\Name $name
   *   The name node.
   *
   * @return string
   *   Resolved name.
   */
  /**
   * Resolve a Name node to its fully qualified class name.
   *
   * Uses NameResolver attributes or manual resolution via use statements.
   *
   * @param \PhpParser\Node\Name $name
   *   The name node.
   *
   * @return string
   *   Fully qualified class name.
   */
  private function resolveName(Node\Name $name): string {
    // If it's already a FullyQualified name (e.g., from extends/implements),
    // just return it directly without further resolution.
    if ($name instanceof Node\Name\FullyQualified) {
      return $name->toString();
    }

    // NameResolver adds 'namespacedName' attribute with fully resolved name.
    if ($name->hasAttribute('namespacedName')) {
      $fqcn = $name->getAttribute('namespacedName');
      return $fqcn instanceof Node\Name ? $fqcn->toString() : (string) $fqcn;
    }

    // Fallback: resolve manually using current namespace and use statements.
    $shortName = $name->toString();

    // Check if it's already fully qualified (starts with \).
    if ($shortName[0] === '\\') {
      return ltrim($shortName, '\\');
    }

    // Check use statements for alias resolution.
    $parts = explode('\\', $shortName);
    $firstPart = $parts[0];

    if (isset($this->currentUses[$firstPart])) {
      // Replace first part with its full namespace from use statement.
      if (count($parts) === 1) {
        // Simple alias: use Drupal\migrate\Row; → Row
        return $this->currentUses[$firstPart];
      }
      else {
        // Compound name: use Drupal\migrate; → migrate\Row
        $parts[0] = $this->currentUses[$firstPart];
        return implode('\\', $parts);
      }
    }

    // Fallback to current namespace + name.
    return $this->currentNamespace ? $this->currentNamespace . '\\' . $shortName : $shortName;
  }

  /**
   * Get fully qualified class name.
   *
   * @param string $name
   *   The class name.
   *
   * @return string
   *   Fully qualified name.
   */
  private function getFQCN(string $name): string {
    return $this->currentNamespace ? $this->currentNamespace . '\\' . $name : $name;
  }

  /**
   * Get extracted metadata.
   *
   * @return array
   *   Metadata array.
   */
  public function getMetadata(): array {
    return $this->metadata;
  }

  /**
   * Extract docblock information.
   *
   * @param object $doc_comment
   *   The docblock comment.
   *
   * @return array
   *   Extracted docblock data.
   */
  private function extractDocblock($doc_comment): array {
    $text = $doc_comment->getText();

    // Remove comment delimiters and clean up.
    $text = preg_replace('#^\s*/\*+\s*#', '', $text);
    $text = preg_replace('#\s*\*+/\s*$#', '', $text);
    $text = preg_replace('#^\s*\*\s?#m', '', $text);

    $summary = '';
    $annotations = [];
    $lines = explode("\n", $text);

    $current_annotation = NULL;
    $annotation_buffer = '';

    foreach ($lines as $line) {
      $line = trim($line);

      // Check if line starts a new annotation.
      if (preg_match('/^@(\w+)(.*)/', $line, $matches)) {
        // Save previous annotation if exists.
        if ($current_annotation !== NULL) {
          if (!isset($annotations[$current_annotation])) {
            $annotations[$current_annotation] = [];
          }
          $annotations[$current_annotation][] = trim($annotation_buffer);
        }

        // Start new annotation.
        $current_annotation = $matches[1];
        $annotation_buffer = trim($matches[2]);
      }
      elseif ($current_annotation !== NULL) {
        // Continue multi-line annotation.
        $annotation_buffer .= ' ' . $line;
      }
      elseif (!empty($line) && empty($summary)) {
        // First non-empty, non-annotation line is summary.
        $summary = $line;
      }
    }

    // Save last annotation.
    if ($current_annotation !== NULL) {
      if (!isset($annotations[$current_annotation])) {
        $annotations[$current_annotation] = [];
      }
      $annotations[$current_annotation][] = trim($annotation_buffer);
    }

    $result = [];
    if ($summary) {
      $result['summary'] = $summary;
    }
    if ($annotations) {
      $result['annotations'] = $annotations;
    }

    return $result;
  }

  /**
   * Extract attributes from attribute groups.
   *
   * @param array $attr_groups
   *   Attribute groups.
   *
   * @return array
   *   Extracted attributes.
   */
  private function extractAttributes(array $attr_groups): array {
    $attributes = [];

    foreach ($attr_groups as $attr_group) {
      foreach ($attr_group->attrs as $attr) {
        $attr_data = [
          'name' => $attr->name->toString(),
        ];

        if ($attr->args) {
          $args = [];
          foreach ($attr->args as $arg) {
            // Skip VariadicPlaceholder nodes (PHP 8.1+ named arguments).
            if ($arg instanceof Node\Arg) {
              $args[] = $this->getConstantValue($arg->value);
            }
          }
          $attr_data['args'] = $args;
        }

        $attributes[] = $attr_data;
      }
    }

    return $attributes;
  }

  /**
   * Get constant value representation.
   *
   * @param object $node
   *   The value node.
   *
   * @return string
   *   String representation of value.
   */
  private function getConstantValue($node): string {
    if ($node instanceof Node\Scalar\String_) {
      return '"' . addslashes($node->value) . '"';
    }
    elseif ($node instanceof Node\Scalar\Int_) {
      return (string) $node->value;
    }
    elseif ($node instanceof Node\Scalar\Float_) {
      return (string) $node->value;
    }
    elseif ($node instanceof Node\Expr\ConstFetch) {
      return $node->name->toString();
    }
    elseif ($node instanceof Node\Expr\Array_) {
      $items = [];
      foreach ($node->items as $item) {
        if ($item) {
          if ($item->key) {
            $items[] = $this->getConstantValue($item->key) . ' => ' . $this->getConstantValue($item->value);
          }
          else {
            $items[] = $this->getConstantValue($item->value);
          }
        }
      }
      return '[' . implode(', ', array_slice($items, 0, 5)) . (count($items) > 5 ? ', ...' : '') . ']';
    }
    elseif ($node instanceof Node\Expr\ClassConstFetch) {
      return $this->resolveName($node->class) . '::' . $node->name->toString();
    }
    elseif ($node instanceof Node\Scalar\MagicConst\Line) {
      return '__LINE__';
    }
    elseif ($node instanceof Node\Scalar\MagicConst\File) {
      return '__FILE__';
    }
    elseif ($node instanceof Node\Scalar\MagicConst\Dir) {
      return '__DIR__';
    }
    elseif ($node instanceof Node\Expr\UnaryMinus) {
      return '-' . $this->getConstantValue($node->expr);
    }
    elseif ($node === NULL) {
      return 'null';
    }

    return '...';
  }

  /**
   * Extract method body patterns.
   *
   * @param array $stmts
   *   Method statements.
   *
   * @return array
   *   Extracted patterns.
   */
  private function extractMethodBodyPatterns(array $stmts): array {
    $patterns = [];

    // Service method calls.
    $service_calls = [];
    // Variable assignments.
    $assignments = [];
    // Throws.
    $throws = [];
    // Return patterns.
    $returns = [];
    // Loops/conditionals.
    $control_flow = [];

    $this->scanStatements($stmts, $service_calls, $assignments, $throws, $returns, $control_flow);

    if ($service_calls) {
      // Group by service.
      $grouped = [];
      foreach ($service_calls as $call) {
        if (preg_match('/\$this->(\w+)->/', $call, $m)) {
          $service = $m[1];
          if (!isset($grouped[$service])) {
            $grouped[$service] = [];
          }
          $grouped[$service][] = preg_replace('/\$this->\w+->/', '', $call);
        }
      }
      $patterns['service_calls'] = $grouped;
    }

    if ($throws) {
      $patterns['throws'] = array_slice(array_unique($throws), 0, 3);
    }

    if ($returns) {
      $patterns['returns'] = array_slice(array_unique($returns), 0, 3);
    }

    if ($control_flow) {
      $patterns['control_flow'] = array_slice(array_unique($control_flow), 0, 3);
    }

    return $patterns;
  }

  /**
   * Scan statements for patterns.
   *
   * @param array $stmts
   *   Statements to scan.
   * @param array $service_calls
   *   Reference to service calls array.
   * @param array $assignments
   *   Reference to assignments array.
   * @param array $throws
   *   Reference to throws array.
   * @param array $returns
   *   Reference to returns array.
   * @param array $control_flow
   *   Reference to control flow array.
   */
  private function scanStatements(array $stmts, array &$service_calls, array &$assignments, array &$throws, array &$returns, array &$control_flow): void {
    foreach ($stmts as $stmt) {
      if ($stmt === NULL) {
        continue;
      }

      // Throw statements.
      if ($stmt instanceof Node\Stmt\Throw_) {
        if ($stmt->expr instanceof Node\Expr\New_) {
          if ($stmt->expr->class instanceof Node\Name) {
            $throws[] = 'throw new ' . $stmt->expr->class->toString();
          }
        }
      }

      // Return statements.
      if ($stmt instanceof Node\Stmt\Return_) {
        if ($stmt->expr instanceof Node\Expr\Variable && is_string($stmt->expr->name)) {
          $returns[] = 'return $' . $stmt->expr->name;
        }
        elseif ($stmt->expr instanceof Node\Expr\MethodCall) {
          $returns[] = 'return method call';
        }
        elseif ($stmt->expr instanceof Node\Expr\Array_) {
          $returns[] = 'return array';
        }
        elseif ($stmt->expr instanceof Node\Scalar\String_ ||
                $stmt->expr instanceof Node\Scalar\Int_ ||
                $stmt->expr instanceof Node\Expr\ConstFetch) {
          $returns[] = 'return value';
        }
      }

      // Loops.
      if ($stmt instanceof Node\Stmt\Foreach_) {
        $control_flow[] = 'foreach';
        if ($stmt->stmts) {
          $this->scanStatements($stmt->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
        }
      }
      elseif ($stmt instanceof Node\Stmt\For_) {
        $control_flow[] = 'for';
        if ($stmt->stmts) {
          $this->scanStatements($stmt->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
        }
      }
      elseif ($stmt instanceof Node\Stmt\While_) {
        $control_flow[] = 'while';
        if ($stmt->stmts) {
          $this->scanStatements($stmt->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
        }
      }
      elseif ($stmt instanceof Node\Stmt\Do_) {
        $control_flow[] = 'do-while';
        if ($stmt->stmts) {
          $this->scanStatements($stmt->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
        }
      }

      // Conditionals.
      if ($stmt instanceof Node\Stmt\If_) {
        $control_flow[] = 'if';
        if ($stmt->stmts) {
          $this->scanStatements($stmt->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
        }
        if ($stmt->elseifs) {
          foreach ($stmt->elseifs as $elseif) {
            if ($elseif->stmts) {
              $this->scanStatements($elseif->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
            }
          }
        }
        if ($stmt->else && $stmt->else->stmts) {
          $this->scanStatements($stmt->else->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
        }
      }
      elseif ($stmt instanceof Node\Stmt\Switch_) {
        $control_flow[] = 'switch';
        if ($stmt->cases) {
          foreach ($stmt->cases as $case) {
            if ($case->stmts) {
              $this->scanStatements($case->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
            }
          }
        }
      }

      // Try-catch-finally.
      if ($stmt instanceof Node\Stmt\TryCatch) {
        if ($stmt->stmts) {
          $this->scanStatements($stmt->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
        }
        if ($stmt->catches) {
          foreach ($stmt->catches as $catch) {
            if ($catch->stmts) {
              $this->scanStatements($catch->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
            }
          }
        }
        if ($stmt->finally && $stmt->finally->stmts) {
          $this->scanStatements($stmt->finally->stmts, $service_calls, $assignments, $throws, $returns, $control_flow);
        }
      }

      // Expression statements (wraps expressions as statements).
      if ($stmt instanceof Node\Stmt\Expression) {
        $expr = $stmt->expr;

        // Service calls like $this->service->method().
        if ($expr instanceof Node\Expr\MethodCall) {
          if ($expr->var instanceof Node\Expr\PropertyFetch) {
            $prop = $expr->var->name instanceof Node\Identifier ?
                $expr->var->name->toString() : '?';
            $method = $expr->name instanceof Node\Identifier ?
                $expr->name->toString() : '?';
            $service_calls[] = '$this->' . $prop . '->' . $method . '()';
          }
        }

        // Assignments.
        if ($expr instanceof Node\Expr\Assign) {
          if ($expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
            $var_name = $expr->var->name;
            if ($expr->expr instanceof Node\Expr\MethodCall) {
              $assignments[] = '$' . $var_name . ' = ...';
            }
            elseif ($expr->expr instanceof Node\Expr\New_) {
              if ($expr->expr->class instanceof Node\Name) {
                $assignments[] = '$' . $var_name . ' = new ' . $expr->expr->class->toString();
              }
            }
          }
        }
      }
    }
  }

}
