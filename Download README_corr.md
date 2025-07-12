# php-multidispatch

**Multiple Dispatch for PHP, now with CLOS-style Method Combination**  
[![Packagist](https://img.shields.io/packagist/v/gwangjinkim/php-multidispatch)](https://packagist.org/packages/gwangjinkim/php-multidispatch)

A robust, extensible, and developer-friendly way to bring multiple dispatch to PHP. This package enables classic "single winner" dispatch *and* full-blown CLOS-style combinations (`:primary`, `:before`, `:after`, `:around`), letting you write elegant, open-closed code in a language that doesn’t natively support it.

---

## Why Multiple Dispatch?

- **What:** Multiple Dispatch lets you write functions (or methods) that automatically select the most specific implementation based on the types (and class hierarchy) of *all* arguments—not just the first (as with PHP’s inheritance or static type hints).
- **Why:** It makes your code more flexible, readable, and truly open-closed.  
  You can extend or specialize behavior for *new types* without ever touching or risking the original codebase.

**Multiple Dispatch allows you to import a package and extend it without ever having to touch or change its internal code (tests, definitions)—you just add more classes or methods. This enables the Open-Closed Principle in a way that’s impossible with classical Java/C++ OOP.**

---

## ️ Installation

**With Composer:**

```bash
composer require gwangjinkim/php-multidispatch
```

**PHP Requirements:** PHP 8.0 or newer recommended.

---

## Quick Start: Classic Multiple Dispatch

This is the *simple*, “one winner” style—familiar if you’ve used Python’s `functools.singledispatch` or Julia’s basic multiple dispatch.

```php
require "vendor/autoload.php";
use function GwangJinKim\Multidispatch\multidispatch;

interface IA {}
interface IB {}
class CA implements IA, IB {}
class CB implements IA, IB {}

// Create a dispatcher
$fn = multidispatch();

// Register handlers for each type (signature is an array of types)
$fn[['IA']] = fn($a) => "This is IA";
$fn[['IB']] = fn($a) => "This is IB";
$fn[['*']]  = fn($a) => "Default!";

// Dispatch picks the first matching handler by specificity/order
echo $fn(new CA()); // Output: "This is IA" (IA was registered first)
echo $fn(new CB()); // Output: "This is IA" (same reason)
echo $fn([]);       // Output: "Default!"

// Scalars work too:
$scalarDispatch = multidispatch();
$scalarDispatch[['int', 'string']] = fn($x, $y) => "Int: $x, String: $y";
echo $scalarDispatch(1, "hello"); // Int: 1, String: hello

// Most specific signature always wins (class > interface > built-in > default)
```

- Register for as many arguments as you like:  
  `$fn[['int', 'string']] = fn($a, $b) => ...;`
- You can always register a fallback handler with `'*'`.
- The dispatcher will always pick the most specific match, based on class hierarchy and registration order.

---

## Advanced: CLOS-Style Method Combination

Want more power?  
You can combine not just a *single* implementation, but also run code *before*, *after*, or *around* the main function—just like in Common Lisp’s CLOS.

### **Kinds of Methods:**
- `:primary` — the main function (always required!)
- `:before` — runs before any `:primary`
- `:after`  — runs after `:primary`  
- `:around` — can wrap the whole call, and optionally short-circuit/override the rest  
  (`$callNext` must be the first argument in your function signature!)

### **Example: Full CLOS-Style Dispatch**

```php
require "vendor/autoload.php";
use function GwangJinKim\Multidispatch\multidispatch;

class Animal {}
class Dog extends Animal {}

$events = [];
$fn = multidispatch();

// :around wraps everything (can nest if multiple registered)
$fn[[Dog::class], ':around'] = function($callNext, $a) use (&$events) {
    $events[] = "around-before";
    $result = $callNext($a);  // Call the next-most-specific method chain
    $events[] = "around-after";
    return $result . " (wrapped)";
};

// :before runs before :primary
$fn[[Dog::class], ':before'] = function($a) use (&$events) {
    $events[] = "before";
};

// :primary is the main implementation (must exist!)
$fn[[Dog::class], ':primary'] = function($a) use (&$events) {
    $events[] = "primary";
    return "Dog logic";
};

// :after runs after :primary
$fn[[Dog::class], ':after'] = function($a) use (&$events) {
    $events[] = "after";
};

$result = $fn(new Dog());

print_r($events); // ['around-before', 'before', 'primary', 'after', 'around-after']
echo $result;     // Dog logic (wrapped)
```

---

### **What does each type do?**

- `:primary` — The main method for this type combination (required).
- `:before`  — Runs in registration order, before `:primary`.
- `:after`   — Runs after `:primary`, in reverse registration order.
- `:around`  — *Wraps* the rest, can run code before/after the entire chain, or block it.
    - The `$callNext` callback (first arg) runs the “next method”—you *must* call it if you want the chain to continue.
    - You can have multiple `:around` methods—each wraps the next-most-specific.

#### **Short-circuiting**

You can “short-circuit” a call in `:around` by simply *not* calling `$callNext`:
```php
$fn[[Dog::class], ':around'] = function($callNext, $a) {
    return "Intercepted!"; // Never runs primary/before/after
};
```

---

## Extended Example

Suppose you want to track the order in which each kind of method is called.

```php
class CA {}
$order = [];
$fn = multidispatch();

// Register all types
$fn[[CA::class], ':before']  = function($a) use (&$order) { $order[] = "before"; };
$fn[[CA::class], ':primary'] = function($a) use (&$order) { $order[] = "primary"; return "main"; };
$fn[[CA::class], ':after']   = function($a) use (&$order) { $order[] = "after"; };
$fn[[CA::class], ':around']  = function($callNext, $a) use (&$order) {
    $order[] = "around-before";
    $result = $callNext($a);
    $order[] = "around-after";
    return "wrapped-$result";
};

echo $fn(new CA());      // wrapped-main
print_r($order);         // ['around-before', 'before', 'primary', 'after', 'around-after']
```

#### **Stacked `:around`**

```php
$fn[[CA::class], ':around'] = function($callNext, $a) use (&$order) {
    $order[] = "outer-around-before";
    $result = $callNext($a);
    $order[] = "outer-around-after";
    return "O:$result";
};
```

- `:around` methods nest, with the *outermost* registered one wrapping the next, etc.

---

## Testing

- This package comes with PHPUnit tests for both *classic* and *CLOS-style* usage.
- To run the tests:

```bash
composer install
./vendor/bin/phpunit tests
```

- If you see errors about `$callNext` or method registration, double-check your function signatures and type arrays.

---

## When to Use Classic vs. CLOS Style?

- **Classic** (primary only):  
    - You want simple, one-winner dispatch.  
    - Porting from an older version.  
    - Don’t need hooks or method chaining.
- **CLOS-style** (primary, before, after, around):  
    - You want full method combination.
    - Need pre/post/around hooks.
    - Want to implement behaviors like logging, validation, interception.

---

## FAQ / Troubleshooting

- **What if I forget `:primary`?**  
    - The dispatcher will error. Every dispatch chain *must* have a primary method.

- **Why does my `:around` not run?**  
    - Ensure it is registered for the correct type signature and you are calling `$callNext` as the first argument.

- **Can I change dispatch priority (first-wins vs. last-wins)?**  
    - Not currently, but see code comments for details—open an issue if you need it!

- **How do I use with built-in types?**  
    - Register with `'int'`, `'string'`, etc. Example: `$fn[['int', 'string']] = fn($x, $y) => ...;`

---

## Links & Further Reading

- **Packagist:** [gwangjinkim/php-multidispatch](https://packagist.org/packages/gwangjinkim/php-multidispatch)
- **GitHub:** [github.com/gwangjinkim/php-multidispatch](https://github.com/gwangjinkim/php-multidispatch)
- **Medium:**
    - [Installing PHP 8 with phpenv—the hard way, but right](https://medium.com/devops-dev/installing-php-8-with-phpenv-the-hard-way-but-right-920a0a8ea1e5?sk=7aa96bfe9bd8b5e3896833d91fb0eaa8)
    - [Beyond If-Else: Smarter Function Dispatch in PHP](https://medium.com/data-science-collective/beyond-if-else-smarter-function-dispatch-in-php-814dadaf3600?sk=41c34e6c95134f653a494a0173f30026)
- **Inspiration:**  
    - [Common Lisp Object System (CLOS)](https://lispcookbook.github.io/cl-cookbook/clos.html)
    - [Julia Multiple Dispatch](https://docs.julialang.org/en/v1/manual/methods/)

---

## Call to Action

Star this repo, try it in your next project, and let us know what wild things you build!  
Contributions and issues are warmly welcomed.

If you love a smarter, more extensible PHP, this package is for you. Now go and dispatch with power!
