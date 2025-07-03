<?php

namespace Multidispatch;

use Exception;

class Multidispatch {
    private array $primaries = [];
    private array $befores = [];
    private array $afters = [];
    private array $arounds = [];

    // Register a method
    public function register($types, $fn, $kind = 'primary') {
        $key = $this->key($types);
        match ($kind) {
            'primary' => $this->primaries[$key][] = $fn,
            'before'  => $this->befores[$key][] = $fn,
            'after'   => $this->afters[$key][] = $fn,
            'around'  => $this->arounds[$key][] = $fn,
            default   => throw new Exception("Unknown method kind $kind")
        };
    }

    // Sugar for user
    public function __invoke(...$args) {
        return $this->call($args);
    }

    // Magic setter, supports $fn[[$types], ':before'] = $f
    public function offsetSet($offset, $value): void {
        [$types, $kind] = is_array($offset) && is_string(end($offset)) && in_array(end($offset), [':primary', ':before', ':after', ':around'])
            ? [array_slice($offset, 0, -1), substr(array_pop($offset), 1)]
            : [$offset, 'primary'];
        $this->register($types, $value, $kind);
    }

    private function key($types) {
        return implode(',', $types);
    }

    // Main dispatch logic, CLOS style
    public function call($args) {
        $types = array_map([$this, 'getType'], $args);
        $specificities = $this->specificities($types);

        // Build effective method chain (most specific first)
        $chain = [];
        foreach ($specificities as $spec) {
            $key = $this->key($spec);
            if (!empty($this->arounds[$key])) foreach ($this->arounds[$key] as $fn) $chain['around'][] = $fn;
            if (!empty($this->befores[$key])) foreach ($this->befores[$key] as $fn) $chain['before'][] = $fn;
            if (!empty($this->primaries[$key])) foreach ($this->primaries[$key] as $fn) $chain['primary'][] = $fn;
            if (!empty($this->afters[$key])) foreach ($this->afters[$key] as $fn) $chain['after'][] = $fn;
        }
        if (empty($chain['primary'])) throw new Exception("No applicable primary method for: ".implode(',',$types));

        // Execute the CLOS method protocol
        $before = $chain['before'] ?? [];
        $after  = array_reverse($chain['after'] ?? []);
        $primaries = $chain['primary'];
        $arounds = $chain['around'] ?? [];

        // CLOS: around wrappers outermost-first, innermost wraps primaries
        $callPrimaries = function(...$args) use ($primaries) {
            $i = 0;
            $call_next = function(...$args) use (&$i, $primaries, &$call_next) {
                if ($i < count($primaries)) {
                    $fn = $primaries[$i++];
                    return $fn(...$args, $call_next);
                }
                throw new Exception("No next primary method available");
            };
            $i = 0;
            return $call_next(...$args);
        };

        $main = $callPrimaries;
        // Compose arounds: outermost to innermost
        foreach (array_reverse($arounds) as $around) {
            $next = $main;
            $main = function(...$args) use ($around, $next) {
                return $around(...$args, $next);
            };
        }

        // Run befores
        foreach ($before as $b) $b(...$args);
        // Call primaries (with arounds if any)
        $result = $main(...$args);
        // Run afters
        foreach ($after as $a) $a(...$args);

        return $result;
    }

    // Get all possible type specializations from most to least specific
    private function specificities($types) {
        $chains = array_map([$this, 'typeHierarchy'], $types);
        return $this->cartesian($chains);
    }

    // Get PHP type name or class/interface
    private function getType($arg) {
        if (is_object($arg)) return get_class($arg);
        $type = gettype($arg);
        return match($type) {
            'integer' => 'int',
            'double'  => 'float',
            'boolean' => 'bool',
            default   => $type,
        };
    }

    // Build hierarchy chain for specificity (most to least)
    private function typeHierarchy($type) {
        if (class_exists($type)) {
            $hier = [$type];
            foreach (class_parents($type) as $p) $hier[] = $p;
            foreach (class_implements($type) as $i) $hier[] = $i;
            $hier[] = '*';
            return $hier;
        }
        return [$type, '*'];
    }

    // Cartesian product, most-specific combinations first
    private function cartesian($arrays, $prefix = []) {
        if (empty($arrays)) return [$prefix];
        $result = [];
        foreach ($arrays[0] as $v) {
            $result = array_merge($result, $this->cartesian(array_slice($arrays, 1), array_merge($prefix, [$v])));
        }
        return $result;
    }
}

// Helper function
function multidispatch() {
    return new Multidispatch();
}
