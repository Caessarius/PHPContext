<?php

namespace ContextExtractor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class MetadataVisitor extends NodeVisitorAbstract
{
    private array $metadata = [
        'classes' => [],
        'interfaces' => [],
        'traits' => [],
        'enums' => [],
    ];

    private ?string $currentFile = null;
    private ?string $currentNamespace = null;
    private array $currentUses = [];

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->currentNamespace = null;
        $this->currentUses = [];
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name ? $node->name->toString() : null;
        } elseif ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $alias = $use->alias ? $use->alias->toString() : $use->name->getLast();
                $this->currentUses[$alias] = $use->name->toString();
            }
        } elseif ($node instanceof Node\Stmt\Class_) {
            $this->extractClass($node);
        } elseif ($node instanceof Node\Stmt\Interface_) {
            $this->extractInterface($node);
        } elseif ($node instanceof Node\Stmt\Trait_) {
            $this->extractTrait($node);
        } elseif ($node instanceof Node\Stmt\Enum_) {
            $this->extractEnum($node);
        }

        return null;
    }

    private function extractClass(Node\Stmt\Class_ $node): void
    {
        $name = $node->name->toString();
        $fqcn = $this->getFQCN($name);

        $classData = [
            'name' => $name,
            'fqcn' => $fqcn,
            'namespace' => $this->currentNamespace,
            'file' => $this->currentFile,
            'abstract' => $node->isAbstract(),
            'final' => $node->isFinal(),
            'uses' => array_values($this->currentUses),
        ];

        // Extract docblock
        if ($node->getDocComment()) {
            $classData['docblock'] = $this->extractDocblock($node->getDocComment());
        }

        // Extract attributes (PHP 8+)
        if ($node->attrGroups) {
            $classData['attributes'] = $this->extractAttributes($node->attrGroups);
        }

        // Extends
        if ($node->extends) {
            $classData['extends'] = [$this->resolveName($node->extends)];
        }

        // Implements
        if ($node->implements) {
            $classData['implements'] = array_map(
                fn($i) => $this->resolveName($i),
                $node->implements
            );
        }

        // Traits
        $traits = [];
        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $traits[] = $this->resolveName($trait);
            }
        }
        if ($traits) {
            $classData['traits_used'] = $traits;
        }

        // Methods with full defaults
        $methods = [];
        $publicMethods = [];
        foreach ($node->getMethods() as $method) {
            $methodData = $this->extractMethod($method);
            $methods[] = $methodData;
            
            // Track public methods for API surface
            if ($methodData['visibility'] === 'public' && 
                !in_array($methodData['name'], ['__construct', '__destruct', '__clone'])) {
                $publicMethods[] = $methodData['name'];
            }
        }
        if ($methods) {
            $classData['methods'] = $methods;
        }
        if ($publicMethods) {
            $classData['public_api'] = $publicMethods;
        }

        // Extract constructor injection order
        foreach ($methods as $method) {
            if ($method['name'] === '__construct' && !empty($method['params'])) {
                $injectionOrder = [];
                foreach ($method['params'] as $param) {
                    if (isset($param['type']) && !in_array($param['type'], ['array', 'string', 'int', 'bool', 'float', 'mixed'])) {
                        $injectionOrder[] = $param['type'];
                    }
                }
                if ($injectionOrder) {
                    $classData['constructor_injection'] = $injectionOrder;
                }
                break;
            }
        }

        // Properties
        $properties = [];
        foreach ($node->getProperties() as $prop) {
            $properties[] = $this->extractProperty($prop);
        }
        if ($properties) {
            $classData['properties'] = $properties;
        }

        // Constants with values
        $constants = [];
        foreach ($node->getConstants() as $const) {
            foreach ($const->consts as $c) {
                $constantData = [
                    'name' => $c->name->toString(),
                    'value' => $this->getConstantValue($c->value),
                ];
                if ($const->getDocComment()) {
                    $constantData['docblock'] = $this->extractDocblock($const->getDocComment());
                }
                $constants[] = $constantData;
            }
        }
        if ($constants) {
            $classData['constants'] = $constants;
        }

        // Type hints and dependencies from method signatures
        $classData['type_dependencies'] = $this->extractTypeDependencies($node);
        
        // Extract magic values (string/numeric literals)
        $classData['magic_values'] = $this->extractMagicValues($node);

        $this->metadata['classes'][] = $classData;
    }

    private function extractInterface(Node\Stmt\Interface_ $node): void
    {
        $name = $node->name->toString();
        $fqcn = $this->getFQCN($name);

        $data = [
            'name' => $name,
            'fqcn' => $fqcn,
            'namespace' => $this->currentNamespace,
            'file' => $this->currentFile,
            'uses' => array_values($this->currentUses),
        ];

        // Extract docblock
        if ($node->getDocComment()) {
            $data['docblock'] = $this->extractDocblock($node->getDocComment());
        }

        // Extract attributes
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

    private function extractTrait(Node\Stmt\Trait_ $node): void
    {
        $name = $node->name->toString();
        $fqcn = $this->getFQCN($name);

        $data = [
            'name' => $name,
            'fqcn' => $fqcn,
            'namespace' => $this->currentNamespace,
            'file' => $this->currentFile,
            'uses' => array_values($this->currentUses),
        ];

        // Extract docblock
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

    private function extractEnum(Node\Stmt\Enum_ $node): void
    {
        $name = $node->name->toString();
        $fqcn = $this->getFQCN($name);

        $data = [
            'name' => $name,
            'fqcn' => $fqcn,
            'namespace' => $this->currentNamespace,
            'file' => $this->currentFile,
            'type' => $node->scalarType ? $node->scalarType->toString() : null,
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

    private function extractMethod(Node\Stmt\ClassMethod $method): array
    {
        $data = [
            'name' => $method->name->toString(),
            'visibility' => $this->getVisibility($method),
            'static' => $method->isStatic(),
            'abstract' => $method->isAbstract(),
            'final' => $method->isFinal(),
        ];

        // Extract docblock
        if ($method->getDocComment()) {
            $data['docblock'] = $this->extractDocblock($method->getDocComment());
        }

        // Extract attributes
        if ($method->attrGroups) {
            $data['attributes'] = $this->extractAttributes($method->attrGroups);
        }

        // Parameters with full defaults
        $params = [];
        foreach ($method->params as $param) {
            $paramData = ['name' => '$' . $param->var->name];
            
            if ($param->type) {
                $paramData['type'] = $this->getTypeString($param->type);
            }
            
            if ($param->default) {
                $paramData['default'] = $this->getConstantValue($param->default);
            }
            
            if ($param->variadic) {
                $paramData['variadic'] = true;
            }

            $params[] = $paramData;
        }
        if ($params) {
            $data['params'] = $params;
        }

        // Return type
        if ($method->returnType) {
            $data['return'] = $this->getTypeString($method->returnType);
        }

        // Extract method body patterns
        if ($method->stmts) {
            $data['body_patterns'] = $this->extractMethodBodyPatterns($method->stmts);
        }

        return $data;
    }

    private function extractProperty(Node\Stmt\Property $property): array
    {
        $prop = $property->props[0];
        
        $data = [
            'name' => '$' . $prop->name->toString(),
            'visibility' => $this->getVisibility($property),
            'static' => $property->isStatic(),
        ];

        if ($property->type) {
            $data['type'] = $this->getTypeString($property->type);
        }

        // Extract default value
        if ($prop->default) {
            $data['default'] = $this->getConstantValue($prop->default);
        }

        // Extract docblock
        if ($property->getDocComment()) {
            $data['docblock'] = $this->extractDocblock($property->getDocComment());
        }

        return $data;
    }

    private function extractTypeDependencies(Node\Stmt\Class_ $node): array
    {
        $deps = [];

        foreach ($node->getMethods() as $method) {
            // Check parameters
            foreach ($method->params as $param) {
                if ($param->type instanceof Node\Name) {
                    $deps[] = $this->resolveName($param->type);
                }
            }

            // Check return type
            if ($method->returnType instanceof Node\Name) {
                $deps[] = $this->resolveName($method->returnType);
            }

            // Check for instanceof in method body
            if ($method->stmts) {
                $this->findInstanceOf($method->stmts, $deps);
            }
        }

        return array_unique($deps);
    }

    private function extractMagicValues(Node\Stmt\Class_ $node): array
    {
        $magicValues = [
            'strings' => [],
            'numbers' => [],
            'array_keys' => [],
        ];
        
        foreach ($node->getMethods() as $method) {
            if ($method->stmts) {
                $this->findMagicValues($method->stmts, $magicValues);
            }
        }
        
        // Count occurrences and filter
        $result = [];
        
        // Strings used more than once
        $stringCounts = array_count_values($magicValues['strings']);
        arsort($stringCounts);
        $frequentStrings = array_filter($stringCounts, fn($c) => $c > 1);
        if ($frequentStrings) {
            $result['strings'] = array_slice(array_keys($frequentStrings), 0, 10);
        }
        
        // Significant numbers (not 0, 1, -1)
        $numbers = array_unique($magicValues['numbers']);
        $numbers = array_filter($numbers, fn($n) => !in_array($n, [0, 1, -1, '0', '1', '-1']));
        if ($numbers) {
            $result['numbers'] = array_slice($numbers, 0, 10);
        }
        
        // Common array keys
        $keysCounts = array_count_values($magicValues['array_keys']);
        arsort($keysCounts);
        if ($keysCounts) {
            $result['array_keys'] = array_slice(array_keys($keysCounts), 0, 10);
        }
        
        return $result;
    }

    private function findMagicValues(array $stmts, array &$magicValues): void
    {
        foreach ($stmts as $stmt) {
            // String literals in various contexts
            if ($stmt instanceof Node\Scalar\String_) {
                $value = $stmt->value;
                // Filter out very long strings and URLs
                if (strlen($value) < 50 && !preg_match('#^https?://#', $value)) {
                    $magicValues['strings'][] = $value;
                }
            }
            
            // Numeric literals (excluding common ones)
            if ($stmt instanceof Node\Scalar\Int_ || $stmt instanceof Node\Scalar\Float_) {
                $magicValues['numbers'][] = (string)$stmt->value;
            }
            
            // Array key access
            if ($stmt instanceof Node\Expr\ArrayDimFetch) {
                if ($stmt->dim instanceof Node\Scalar\String_) {
                    $magicValues['array_keys'][] = $stmt->dim->value;
                }
            }
            
            // Array keys in assignments
            if ($stmt instanceof Node\Expr\Array_) {
                foreach ($stmt->items as $item) {
                    if ($item && $item->key instanceof Node\Scalar\String_) {
                        $magicValues['array_keys'][] = $item->key->value;
                    }
                }
            }
            
            // Recursively scan nested nodes
            $properties = get_object_vars($stmt);
            foreach ($properties as $prop) {
                if (is_array($prop)) {
                    $this->findMagicValues($prop, $magicValues);
                } elseif ($prop instanceof Node) {
                    $this->findMagicValues([$prop], $magicValues);
                }
            }
        }
    }

    private function findInstanceOf(array $stmts, array &$deps): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Expr\Instanceof_ && $stmt->class instanceof Node\Name) {
                $deps[] = $this->resolveName($stmt->class);
            }

            // Recursively check nested statements
            $properties = get_object_vars($stmt);
            foreach ($properties as $prop) {
                if (is_array($prop)) {
                    $this->findInstanceOf($prop, $deps);
                } elseif ($prop instanceof Node) {
                    $this->findInstanceOf([$prop], $deps);
                }
            }
        }
    }

    private function getVisibility($node): string
    {
        if ($node->isPublic()) return 'public';
        if ($node->isProtected()) return 'protected';
        if ($node->isPrivate()) return 'private';
        return 'public';
    }

    private function getTypeString($type): string
    {
        if ($type instanceof Node\Name) {
            return $this->resolveName($type);
        } elseif ($type instanceof Node\Identifier) {
            return $type->toString();
        } elseif ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->getTypeString($t), $type->types));
        } elseif ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn($t) => $this->getTypeString($t), $type->types));
        } elseif ($type instanceof Node\NullableType) {
            return '?' . $this->getTypeString($type->type);
        }
        return 'mixed';
    }

    private function resolveName(Node\Name $name): string
    {
        return $name->toString();
    }

    private function getFQCN(string $name): string
    {
        return $this->currentNamespace ? $this->currentNamespace . '\\' . $name : $name;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    private function extractDocblock($docComment): array
    {
        $text = $docComment->getText();
        
        // Remove comment delimiters and clean up
        $text = preg_replace('#^\s*/\*+\s*#', '', $text);
        $text = preg_replace('#\s*\*+/\s*$#', '', $text);
        $text = preg_replace('#^\s*\*\s?#m', '', $text);
        
        $summary = '';
        $annotations = [];
        $lines = explode("\n", $text);
        
        $currentAnnotation = null;
        $annotationBuffer = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Check if line starts a new annotation
            if (preg_match('/^@(\w+)(.*)/', $line, $matches)) {
                // Save previous annotation if exists
                if ($currentAnnotation !== null) {
                    if (!isset($annotations[$currentAnnotation])) {
                        $annotations[$currentAnnotation] = [];
                    }
                    $annotations[$currentAnnotation][] = trim($annotationBuffer);
                }
                
                // Start new annotation
                $currentAnnotation = $matches[1];
                $annotationBuffer = trim($matches[2]);
            } elseif ($currentAnnotation !== null) {
                // Continue multi-line annotation
                $annotationBuffer .= ' ' . $line;
            } elseif (!empty($line) && empty($summary)) {
                // First non-empty, non-annotation line is summary
                $summary = $line;
            }
        }
        
        // Save last annotation
        if ($currentAnnotation !== null) {
            if (!isset($annotations[$currentAnnotation])) {
                $annotations[$currentAnnotation] = [];
            }
            $annotations[$currentAnnotation][] = trim($annotationBuffer);
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

    private function extractAttributes(array $attrGroups): array
    {
        $attributes = [];
        
        foreach ($attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attrData = [
                    'name' => $attr->name->toString(),
                ];
                
                if ($attr->args) {
                    $args = [];
                    foreach ($attr->args as $arg) {
                        $args[] = $this->getConstantValue($arg->value);
                    }
                    $attrData['args'] = $args;
                }
                
                $attributes[] = $attrData;
            }
        }
        
        return $attributes;
    }

    private function getConstantValue($node): string
    {
        if ($node instanceof Node\Scalar\String_) {
            return '"' . addslashes($node->value) . '"';
        } elseif ($node instanceof Node\Scalar\Int_) {
            return (string)$node->value;
        } elseif ($node instanceof Node\Scalar\Float_) {
            return (string)$node->value;
        } elseif ($node instanceof Node\Expr\ConstFetch) {
            return $node->name->toString();
        } elseif ($node instanceof Node\Expr\Array_) {
            $items = [];
            foreach ($node->items as $item) {
                if ($item) {
                    if ($item->key) {
                        $items[] = $this->getConstantValue($item->key) . ' => ' . $this->getConstantValue($item->value);
                    } else {
                        $items[] = $this->getConstantValue($item->value);
                    }
                }
            }
            return '[' . implode(', ', array_slice($items, 0, 5)) . (count($items) > 5 ? ', ...' : '') . ']';
        } elseif ($node instanceof Node\Expr\ClassConstFetch) {
            return $this->resolveName($node->class) . '::' . $node->name->toString();
        } elseif ($node instanceof Node\Scalar\MagicConst\Line) {
            return '__LINE__';
        } elseif ($node instanceof Node\Scalar\MagicConst\File) {
            return '__FILE__';
        } elseif ($node instanceof Node\Scalar\MagicConst\Dir) {
            return '__DIR__';
        } elseif ($node instanceof Node\Expr\UnaryMinus) {
            return '-' . $this->getConstantValue($node->expr);
        } elseif ($node === null) {
            return 'null';
        }
        
        return '...';
    }

    private function extractMethodBodyPatterns(array $stmts): array
    {
        $patterns = [];
        
        // Service method calls
        $serviceCalls = [];
        // Variable assignments
        $assignments = [];
        // Throws
        $throws = [];
        // Return patterns
        $returns = [];
        // Loops/conditionals
        $controlFlow = [];
        
        $this->scanStatements($stmts, $serviceCalls, $assignments, $throws, $returns, $controlFlow);
        
        if ($serviceCalls) {
            // Group by service
            $grouped = [];
            foreach ($serviceCalls as $call) {
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
        
        if ($controlFlow) {
            $patterns['control_flow'] = array_slice(array_unique($controlFlow), 0, 3);
        }
        
        return $patterns;
    }

    private function scanStatements(array $stmts, array &$serviceCalls, array &$assignments, array &$throws, array &$returns, array &$controlFlow): void
    {
        foreach ($stmts as $stmt) {
            // Throw statements
            if ($stmt instanceof Node\Stmt\Throw_) {
                if ($stmt->expr instanceof Node\Expr\New_) {
                    if ($stmt->expr->class instanceof Node\Name) {
                        $throws[] = 'throw new ' . $stmt->expr->class->toString();
                    }
                }
            }
            
            // Return statements
            if ($stmt instanceof Node\Stmt\Return_) {
                if ($stmt->expr instanceof Node\Expr\Variable && is_string($stmt->expr->name)) {
                    $returns[] = 'return $' . $stmt->expr->name;
                } elseif ($stmt->expr instanceof Node\Expr\MethodCall) {
                    $returns[] = 'return method call';
                } elseif ($stmt->expr instanceof Node\Expr\Array_) {
                    $returns[] = 'return array';
                } elseif ($stmt->expr instanceof Node\Scalar\String_ || 
                          $stmt->expr instanceof Node\Scalar\Int_ ||
                          $stmt->expr instanceof Node\Expr\ConstFetch) {
                    $returns[] = 'return value';
                }
            }
            
            // Loops
            if ($stmt instanceof Node\Stmt\Foreach_) {
                $controlFlow[] = 'foreach';
            } elseif ($stmt instanceof Node\Stmt\For_) {
                $controlFlow[] = 'for';
            } elseif ($stmt instanceof Node\Stmt\While_) {
                $controlFlow[] = 'while';
            }
            
            // Conditionals
            if ($stmt instanceof Node\Stmt\If_) {
                $controlFlow[] = 'if';
            } elseif ($stmt instanceof Node\Stmt\Switch_) {
                $controlFlow[] = 'switch';
            }
            
            // Service calls like $this->service->method()
            if ($stmt instanceof Node\Expr\MethodCall) {
                if ($stmt->var instanceof Node\Expr\PropertyFetch) {
                    $prop = $stmt->var->name instanceof Node\Identifier ? 
                        $stmt->var->name->toString() : '?';
                    $method = $stmt->name instanceof Node\Identifier ? 
                        $stmt->name->toString() : '?';
                    $serviceCalls[] = '$this->' . $prop . '->' . $method . '()';
                }
            }
            
            // Assignments
            if ($stmt instanceof Node\Expr\Assign) {
                if ($stmt->var instanceof Node\Expr\Variable && is_string($stmt->var->name)) {
                    $varName = $stmt->var->name;
                    if ($stmt->expr instanceof Node\Expr\MethodCall) {
                        $assignments[] = '$' . $varName . ' = ...';
                    } elseif ($stmt->expr instanceof Node\Expr\New_) {
                        if ($stmt->expr->class instanceof Node\Name) {
                            $assignments[] = '$' . $varName . ' = new ' . $stmt->expr->class->toString();
                        }
                    }
                }
            }
            
            // Recursively scan nested statements
            $properties = get_object_vars($stmt);
            foreach ($properties as $prop) {
                if (is_array($prop)) {
                    $this->scanStatements($prop, $serviceCalls, $assignments, $throws, $returns, $controlFlow);
                } elseif ($prop instanceof Node) {
                    $this->scanStatements([$prop], $serviceCalls, $assignments, $throws, $returns, $controlFlow);
                }
            }
        }
    }
}
