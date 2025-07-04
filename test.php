<?php

// A single file to test the class in complete isolation.

namespace Multidispatch;

use ArrayAccess;
use Exception;

// --- 1. The Final Multidispatch Class ---

class Multidispatch implements ArrayAccess
{
    private array $methods = [];

    public function __invoke(...$args)
    {
        [$candidates, $resolvedTypes] = $this->resolve($args);
        $core = function (...$a) use ($candidates) {
            foreach ($candidates['before'] as $before) { $before(...$a); }
            $result = $candidates['primary'](...$a);
            foreach ($candidates['after'] as $after) { $after(...$a); }
            return $result;
        };
        $aroundStack = $candidates['around'];
        $wrapped = array_reduce($aroundStack, function ($next, $around) {
            return function (...$args) use ($around, $next) {
                return $around($next, ...$args);
            };
        }, $core);
        return $wrapped(...$args);
    }

    public function offsetSet($offset, $value): void
    {
        if (is_array($offset) && count($offset) >= 1 && is_string($offset[count($offset)-1]) && str_starts_with($offset[count($offset)-1], ':')) {
            $tag = array_pop($offset);
        } else {
            $tag = ':primary';
        }
        $key = $this->keyFromTypes($offset);
        if (!isset($this->methods[$key]) || !is_array($this->methods[$key])) {
            $this->methods[$key] = [];
        }
        if ($tag === ':primary') {
            $this->methods[$key][$tag] = $value;
        } else {
            if (!isset($this->methods[$key][$tag]) || !is_array($this->methods[$key][$tag])) {
                $this->methods[$key][$tag] = [];
            }
            array_unshift($this->methods[$key][$tag], $value);
        }
    }

    public function offsetExists($offset): bool { $key = $this->keyFromTypes($offset); return isset($this->methods[$key]); }
    public function offsetGet($offset): callable { $key = $this->keyFromTypes($offset); return $this->methods[$key][':primary'] ?? throw new Exception("No :primary method for $key"); }
    public function offsetUnset($offset): void { $key = $this->keyFromTypes($offset); unset($this->methods[$key]); }

    private function getTypeName($arg): string
    {
        if (is_object($arg)) return ltrim(get_class($arg), '\\');
        $type = gettype($arg);
        return match ($type) {
            'integer' => 'int', 'double'  => 'float', 'boolean' => 'bool',
            'string', 'array', 'resource', 'NULL' => $type, default => '*'
        };
    }

    private function allTypesForArg($arg): array
    {
        if (is_object($arg)) {
            $types = [ltrim(get_class($arg), '\\')];
            $types = array_merge($types, class_implements($arg), class_parents($arg));
            $types[] = '*';
            $types = array_map(fn($x) => ltrim($x, '\\'), $types);
            return array_unique($types);
        }
        $type = $this->getTypeName($arg);
        return [$type, '*'];
    }

    private function keyFromTypes($types)
    {
        $flat = [];
        foreach ($types as $t) {
            if (is_array($t)) $flat = array_merge($flat, $t); else $flat[] = $t;
        }
        return implode(',', $flat);
    }

    private function resolve(array $args): array
    {
        $typeChains = array_map([$this, 'allTypesForArg'], $args);
        $combos = $this->cartesian($typeChains);
        $candidates = [
            'before' => [], 'primary' => null, 'after' => [], 'around' => [],
        ];
        $matchedTypes = null;
        foreach ($combos as $combo) {
            $key = $this->keyFromTypes($combo);
            if (isset($this->methods[$key])) {
                $methods = $this->methods[$key];
                if (isset($methods[':before'])) { $candidates['before'] = array_merge($candidates['before'], $methods[':before']); }
                if (isset($methods[':primary']) && !$candidates['primary']) { $candidates['primary'] = $methods[':primary']; $matchedTypes = $combo; }
                if (isset($methods[':after'])) { $candidates['after'] = array_merge($candidates['after'], $methods[':after']); }
                if (isset($methods[':around'])) { $candidates['around'] = array_merge($candidates['around'], $methods[':around']); }
            }
        }
        if (!$candidates['primary']) {
            throw new Exception("No :primary method for types: " . implode(', ', array_map([$this, 'getTypeName'], $args)));
        }
        $candidates['before'] = array_reverse($candidates['before']);
        $candidates['around'] = array_reverse($candidates['around']);
        return [$candidates, $matchedTypes];
    }

    private function cartesian($arrays)
    {
        if (count($arrays) === 0) return [[]];
        $result = [[]];
        foreach ($arrays as $property => $property_values) {
            $tmp = [];
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    $tmp[] = array_merge($result_item, [$property_value]);
                }
            }
            $result = $tmp;
        }
        return $result;
    }
}

// --- 2. Test Dependencies ---

interface IA {}
interface IB {}
class CA implements IA, IB {}

// --- 3. The Test Case ---

echo "Running standalone test...\n";

$order = [];
$fn = new Multidispatch();

// These are the calls that were failing in PHPUnit
$fn[[[CA::class], ':before']] = function($a) use (&$order) { $order[] = "before"; };
$fn[[[CA::class], ':primary']] = function($a) use (&$order) { $order[] = "primary"; };
$fn[[[CA::class], ':after']]  = function($a) use (&$order) { $order[] = "after"; };

$fn(new CA());

assert($order === ['before', 'primary', 'after'], "Test Failed!");

echo "Test Passed!\n";
