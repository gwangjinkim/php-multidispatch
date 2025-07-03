<?php
require "vendor/autoload.php";
use function Multidispatch\multidispatch;

// ------------------------------
// Classic Multiple Dispatch: No CLOS Extensions
// ------------------------------
echo "=== Classic Multiple Dispatch (No CLOS Extensions) ===\n";

/**
 * Define two interfaces and two classes.
 * Both CA and CB implement IA and IB, so dispatching will demonstrate how handlers are selected.
 */
interface IA {}
interface IB {}
class CA implements IA, IB {}
class CB implements IA, IB {}

/**
 * Create a new dispatcher using multidispatch().
 * Register a handler for IA, IB, and a default ('*') catch-all.
 */
$classic = multidispatch();

$classic[['IA']] = fn($a) => "Classic: This is IA";
$classic[['IB']] = fn($a) => "Classic: This is IB";
$classic[['*']]  = fn($a) => "Classic: Default!";

// Try dispatching with both classes and an array:
echo $classic(new CA()) . "\n"; // Output depends on dispatch rules (order or specificity)
echo $classic(new CB()) . "\n";
echo $classic([]) . "\n";       // Not matching IA or IB, so uses default

/**
 * You can also dispatch on *scalar types* (not just classes!)
 */
$scalar = multidispatch();
$scalar[['int', 'string']] = fn($x, $y) => "Classic: Int: $x, String: $y";
echo $scalar(1, "hello") . "\n"; // Will match (int, string) and call the registered handler

// ----------------------------------------------
// Advanced Multiple Dispatch: CLOS-style Extensions
// ----------------------------------------------
echo "\n=== Advanced Multiple Dispatch (CLOS-style Extensions) ===\n";

/**
 * Now let's show the real superpowers: method combinations like CLOS.
 * You can register handlers as :before, :after, :around, and the regular :primary (default).
 * This allows pre- and post-processing, wrapping, and chaining logic.
 */

$fn = multidispatch();

/**
 * Register a :before method (runs before :primary and :around).
 * Good for logging, validation, side-effects, or setup.
 */
$fn->before([IA::class], function($a) {
    echo "[Before IA] About to handle an IA object\n";
});

/**
 * Register a :primary handler (the main method).
 * If multiple handlers are registered, the most specific one (matching the argument types) wins.
 */
$fn->primary([IA::class], function($a) {
    echo "[Primary IA] Actually handling IA object: ";
    return "Advanced: This is IA";
});

/**
 * Register an :after method (runs after :primary and :around).
 * Good for cleanup, logging, or chaining side-effects.
 */
$fn->after([IA::class], function($a) {
    echo "[After IA] Done handling an IA object\n";
});

/**
 * Register an :around method. 
 * :around can wrap the entire dispatch, and can choose to call the next method (often :primary) or not.
 * Think of it like a decorator or middleware layer.
 */
$fn->around([IA::class], function($a, $call_next) {
    echo "[Around Start] Wrapping call for IA\n";
    $result = $call_next($a); // Calls the next method in line (usually :primary)
    echo "[Around End] Done wrapping IA\n";
    return $result;
});

/**
 * Let's see all the magic happen:
 * This call will trigger :before, :around, :primary, :after (in that order!).
 * Watch the output order carefully.
 */
echo $fn(new CA()) . "\n"; // See console output for the sequence

/**
 * Register a general fallback handler for *any* type.
 * :primary(['*']) acts as a safety net when no specific match exists.
 */
$fn->primary(['*'], function($a) {
    return "Advanced: Fallback/default handler";
});
echo $fn([]) . "\n"; // Triggers fallback

/**
 * Now, another demonstration: 
 * What if you want to *change* the result, or modify how the stack is called? 
 * Here's how :around can transform results and even skip or wrap the underlying primary.
 */
$fn->around([IB::class], function($a, $call_next) {
    echo "[IB Around] Start\n";
    $result = strtoupper($call_next($a)); // Converts the result of :primary to uppercase
    echo "[IB Around] End\n";
    return $result;
});
$fn->primary([IB::class], function($a) {
    return "Advanced: This is IB";
});

echo $fn(new CB()) . "\n"; // Triggers the new IB :around + :primary combo

echo "\n=== End of Examples ===\n";

// ----------------------------------------------
// Summary of what was shown
// ----------------------------------------------

/**
 * Classic usage: 
 *   - Register a handler per type signature (no stacking, just "winner takes all").
 *   - Best if you want simple, easy-to-understand logic (like Python's singledispatch).
 *
 * Advanced (CLOS-style) usage:
 *   - Register :before, :after, :around, :primary for any type or combination.
 *   - You can stack behaviors, run setup/teardown, or wrap/override main logic.
 *   - Use $call_next inside :around to pass control to the next-most-specific method (or skip it!).
 *
 * Choose the style that matches your project's complexity and needs!
 */
