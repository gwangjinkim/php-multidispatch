<?php

require "vendor/autoload.php";
use function Multidispatch\multidispatch;

// ---- INTERFACE & CLASS SETUP (for demonstration) ----

// Define two interfaces
interface IA {}
interface IB {}

// Define two classes that implement the interfaces (both do both for demo)
class CA implements IA, IB {}
class CB implements IA, IB {}

// ---- CLASSIC USAGE: SIMPLE MULTIPLE DISPATCH (NO CLOS EXTENSIONS) ----
// This is how you use php-multidispatch if you want only simple dispatch (one winner, like Python's singledispatch).
// No need to know about CLOS, :before/:after/:around, etc. This is fully backwards-compatible.

echo "--- CLASSIC USAGE: Multiple Dispatch Without CLOS Extensions ---\n";

$fn = multidispatch();

// Register handlers for types. Only one will ever run: the "most specific" for the argument.
$fn[['IA']] = fn($a) => "Classic: This is IA";
$fn[['IB']] = fn($a) => "Classic: This is IB";
$fn[['*']]  = fn($a) => "Classic: Fallback/default handler";

// Try dispatching for CA and CB (which both implement both interfaces)
echo $fn(new CA()) . "\n"; // "Classic: This is IA" or "Classic: This is IB" (first-registered interface wins)
echo $fn(new CB()) . "\n";

// Works for built-in types too!
$scalarDispatch = multidispatch();
$scalarDispatch[['int', 'string']] = fn($x, $y) => "Int: $x, String: $y";
echo $scalarDispatch(1, "hello") . "\n"; // Int: 1, String: hello

// Register a general handler as fallback
$fn[['*']] = fn($a) => "Classic: Fallback/default handler";
echo $fn([]) . "\n";

// ---------------------------------------------------------------------
// ---- ADVANCED USAGE: CLOS-STYLE METHOD COMBINATIONS -----------------
// ---------------------------------------------------------------------
// Now let's show off CLOS-style features: :primary, :before, :after, :around, and $callNext.
// You can combine these for powerful, layered dispatch logic!

echo "\n--- ADVANCED USAGE: CLOS-STYLE EXTENSIONS (:primary, :before, :after, :around) ---\n";

$closFn = multidispatch();

// 1. Register a :before method (runs before primary/around)
$closFn[['IA'], ':before'] = function ($a) {
    echo "[BEFORE] About to handle something implementing IA\n";
};

// 2. Register an :after method (runs after primary/around)
$closFn[['IA'], ':after'] = function ($a) {
    echo "[AFTER] Just handled something implementing IA\n";
};

// 3. Register a :primary method (the main handler)
$closFn[['IA'], ':primary'] = function ($a) {
    echo "[PRIMARY] Handling IA instance\n";
    return "Primary result for IA";
};

// 4. Register an :around method (wraps the call, must take $callNext as FIRST param)
// :around can do things BEFORE and AFTER, and must call $callNext() to continue the chain.
$closFn[['IA'], ':around'] = function ($callNext, $a) {
    echo "[AROUND] (before) Wrapping IA\n";
    $result = $callNext($a); // callNext triggers :before, :primary, :after as a group!
    echo "[AROUND] (after) Wrapping IA\n";
    return "[Around result] " . $result;
};

// Let's run it!
echo "\nCalling CLOS-dispatcher on CA:\n";
$output = $closFn(new CA());
echo "Returned: $output\n";

// Example output:
// [AROUND] (before) Wrapping IA
// [BEFORE] About to handle something implementing IA
// [PRIMARY] Handling IA instance
// [AFTER] Just handled something implementing IA
// [AROUND] (after) Wrapping IA
// Returned: [Around result] Primary result for IA

// ---- Experiment: Omitting :around, what happens? ----
echo "\nCalling CLOS-dispatcher on CB (no :around for IB):\n";
$closFn[['IB'], ':primary'] = function($a) {
    echo "[PRIMARY] Handling IB instance\n";
    return "Primary for IB";
};
$closFn[['IB'], ':before'] = function($a) {
    echo "[BEFORE] For IB\n";
};
$closFn[['IB'], ':after'] = function($a) {
    echo "[AFTER] For IB\n";
};
echo "Returned: " . $closFn(new CB()) . "\n";

// ---- Multiple argument dispatch! ----
echo "\n--- Multiple argument dispatch with CLOS ---\n";
$multi = multidispatch();
$multi[['int', 'string'], ':primary'] = fn($x, $y) => "Got int $x and string $y";
$multi[['int', 'string'], ':before'] = fn($x, $y) => print("[BEFORE] int/string\n");
$multi[['int', 'string'], ':after'] = fn($x, $y) => print("[AFTER] int/string\n");

echo $multi(5, "five") . "\n";

// ---- More complex :around chaining example ----
// You can have multiple :around methods stacked! Outermost runs first, innermost last.
echo "\n--- Chained :around Example ---\n";
$chain = multidispatch();
$chain[['int'], ':primary'] = fn($x) => "Primary $x";
$chain[['int'], ':around'] = function($callNext, $x) {
    echo "[AROUND1 before]\n";
    $r = $callNext($x);
    echo "[AROUND1 after]\n";
    return "AROUND1<$r>";
};
$chain[['int'], ':around'] = function($callNext, $x) {
    echo "[AROUND2 before]\n";
    $r = $callNext($x);
    echo "[AROUND2 after]\n";
    return "AROUND2<$r>";
};
echo $chain(10) . "\n";

// Output order shows wrapping: AROUND2 before → AROUND1 before → Primary → AROUND1 after → AROUND2 after

// ---- Summary for users ----
/*
Classic usage (one-winner dispatch):
- Register a handler for each type signature.
- The most specific handler is called (based on inheritance/interfaces).
- Only one handler runs.

CLOS-style usage:
- You can add :before and :after hooks for side-effects/logging/validation.
- You can wrap handlers with :around for full control (e.g., timing, error handling, custom chains).
- $callNext lets you explicitly continue the chain, or interrupt/replace results.

Mix and match: Only add complexity when you need it!

For more details, see the README.md and test suite.
*/
