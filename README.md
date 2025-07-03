# php-multidispatch

> Powerful CLOS-style Multiple Dispatch for PHP — with class hierarchy, scalar types, method combination, and open extension. Inspired by Common Lisp (CLOS) and Julia.

## Features

- ✅ True multiple dispatch on all arguments
- ✅ Supports scalar types (`int`, `string`, etc.)
- ✅ Class hierarchy-sensitive resolution
- ✅ Wildcard fallback (`'*'`)
- ✅ Method combinations: :primary, :before, :after, :around
- ✅ Supports $call_n
- ✅ Short syntax: `$fn[['int', 'string']] = fn(...)`
- ✅ Named functions via helper

---

## Installation

```bash
composer require gwangjin/php-multidispatch
```

---

## Usage

```php
use function Multidispatch\multidispatch;

$fn = multidispatch();

$fn[['int', 'string']] = fn($a, $b) => "$a and $b";
$fn[['*', '*']] = fn($a, $b) => "default";

echo $fn(1, "hello");       // 1 and hello
echo $fn([], new stdClass); // default
```

---

## Named Generics (Optional)

```php
function greet() {
    static $g = null;
    return $g ??= multidispatch();
}

greet()[['string']] = fn($x) => "Hello $x!";
echo greet()("world"); // Hello world!
```

---

## Run Tests

```bash
composer install
composer test
```

---

# PHP-Multidispatch: Multiple Dispatch with CLOS-Style Combinations

## Overview

`php-multidispatch` brings multiple dispatch—à la Common Lisp Object System (CLOS)—to PHP, with full support for method combinations: `:primary`, `:before`, `:after`, `:around`, and call-next-method. You can now write truly extensible, open-closed code that’s composable and plugin-friendly, without ever touching the package internals.

* **Packagist:** [https://packagist.org/packages/gwangjinkim/php-multidispatch](https://packagist.org/packages/gwangjinkim/php-multidispatch)
* **GitHub:** [https://github.com/gwangjinkim/php-multidispatch](https://github.com/gwangjinkim/php-multidispatch)

---

## What is Multiple Dispatch, and Why Should You Care?

Multiple dispatch allows a function to have several implementations, picked dynamically based on the *types of all its arguments* (not just the first, as with single dispatch in OOP).

This means:

* **Extend behavior from outside the package**: You can add new type-methods without changing the original code.
* **Follow the Open/Closed Principle**: You extend, never modify.
* **Simplify complex conditionals**: Replace messy `if-else` chains with clean, declarative dispatch.
* **Compose logic safely**: Plugins can hook in safely, adding features before, after, or around any method.

> Multiple Dispatch allows you to import a package (which uses Multiple Dispatch) and extend it without ever having to touch or change its internal code (tests, definitions) but just add more classes and more methods/functions. It allows you to follow open-closed principle which would be impossible with classical Java/C++ OOP systems.

---

## Quick Install

Add to your project with Composer:

```bash
composer require gwangjinkim/php-multidispatch
```

Or, for latest dev version:

```bash
git clone https://github.com/gwangjinkim/php-multidispatch.git
cd php-multidispatch
composer install
```

## Requirements

* PHP 8.1 or later (tested up to 8.4)
* Composer

---

## Getting Started Example: A Taste of Power

```php
use Multidispatch\Multidispatch;

interface Animal {}
class Dog implements Animal {}
class Cat implements Animal {}

$feed = new Multidispatch(['methodCombination' => 'clos']);

$feed->register(['Animal'], function($a, $call_next) {
    echo get_class($a) . " munches generic food.\n";
}, 'primary');

$feed->register(['Dog'], function($a, $call_next) {
    echo "Dog chomps a juicy bone.\n";
    $call_next(); // fallback to next method
}, 'primary');

$feed->register(['Animal'], function($a, $call_next) {
    echo "Preparing bowl for " . get_class($a) . ".\n";
}, 'before');

$feed->register(['Animal'], function($a, $call_next) {
    echo "Cleanup after feeding " . get_class($a) . ".\n";
}, 'after');

$feed->register(['Dog'], function($a, $call_next) {
    echo "[AROUND] Checking dog allergies...\n";
    $call_next();
    echo "[AROUND] Dog gets a treat after eating!\n";
}, 'around');

$feed(new Dog());
$feed(new Cat());
```

Output:

```
Preparing bowl for Dog.
[AROUND] Checking dog allergies...
Dog chomps a juicy bone.
Dog munches generic food.
[AROUND] Dog gets a treat after eating!
Cleanup after feeding Dog.
Preparing bowl for Cat.
Cat munches generic food.
Cleanup after feeding Cat.
```

---

## The Anatomy of CLOS-style Method Combinations

### What Are These?

* **`:primary`**: The main logic for a type-combo (as many as needed, most to least specific).
* **`:before`**: Runs before primaries (for setup, logging, etc). Ordered least→most specific.
* **`:after`**: Runs after primaries (cleanup, chaining, etc). Ordered most→least specific.
* **`:around`**: Wraps the entire call. Can skip, alter, or control with `$call_next()`.
* **`$call_next`**: Lets you invoke the next most-specific method (or not!).

### Full Example: Chaining Everything

```php
// The magic: Compose all 4 combinations
$dispatch = new Multidispatch(['methodCombination' => 'clos']);

$dispatch->register(['A'], function($a, $call_next) {
    echo "BEFORE\n";
}, 'before');

$dispatch->register(['A'], function($a, $call_next) {
    echo "AFTER\n";
}, 'after');

$dispatch->register(['A'], function($a, $call_next) {
    echo "[AROUND start]\n";
    $call_next();
    echo "[AROUND end]\n";
}, 'around');

$dispatch->register(['A'], function($a, $call_next) {
    echo "PRIMARY\n";
}, 'primary');

$dispatch(new class implements A {});

// Output:
// BEFORE
// [AROUND start]
// PRIMARY
// [AROUND end]
// AFTER
```

### Scenarios & Guidance

| Method   | Purpose                      | Order            | \$call\_next()            |
| -------- | ---------------------------- | ---------------- | ------------------------- |
| :before  | Setup/logging/pre-checks     | Least→Most Spec. | N/A                       |
| :primary | Main logic                   | Most→Least Spec. | Calls next most-specific  |
| :after   | Cleanup/logging/post-actions | Most→Least Spec. | N/A                       |
| :around  | Wrap/override/intercept      | Outer→Inner      | Controls whole call chain |

#### When to Use What

* **`:primary`** — Your main action. Call `$call_next()` if you want to chain to less-specific logic.
* **`:before`** — For any code that should *always* happen first (setup, logging).
* **`:after`** — For anything that must *always* happen after (cleanup, stats).
* **`:around`** — For intercepting or wrapping the whole operation, optionally deciding to run (or skip) the rest.

#### Multiple Plugins/Extensions?

Just register more hooks—they’ll all run, ordered by specificity. Perfect for plugins, cross-cutting logic, and safe extension.

---

## Advanced: Handling Interface Order and Policy

If your class implements multiple interfaces (e.g. `class C implements IA, IB {}`), dispatch order respects specificity, but sometimes interface registration order matters. The system follows CLOS-like rules (most-specific first), but you can build your own method combination or ordering policy if you need total control.

---

Absolutely! Here’s an additional section for your README.md (ready for the canvas if you want it) that shows classic usage of php-multidispatch without CLOS extensions—i.e., using only :primary (the default, old-school style). This helps both existing users and newcomers who want simple multiple dispatch, without the extra method combination features.

---

# Classic Usage: Multiple Dispatch the Simple Way (No CLOS Extensions)

If you just want plain, classic multiple dispatch—no :before, :after, or :around—your code stays as simple and clean as before. Everything you knew still works, and it’s all backwards-compatible!

Here’s how you use the package the “old-school” way (like before CLOS extensions):

```php
require "vendor/autoload.php";
use function Multidispatch\multidispatch;

interface IA {}
interface IB {}
class CA implements IA, IB {}
class CB implements IA, IB {}

$fn = multidispatch();

// Register different handlers for types
$fn[['IA']] = fn($a) => "This is IA";
$fn[['IB']] = fn($a) => "This is IB";
$fn[['*']]  = fn($a) => "Default!";

// Usage: dispatch based on type
echo $fn(new CA()); // Depending on registration/interface, returns "This is IA" or "This is IB"
echo $fn(new CB()); // Ditto

// Also works for built-in types:
$scalarDispatch = multidispatch();
$scalarDispatch[['int', 'string']] = fn($x, $y) => "Int: $x, String: $y";
echo $scalarDispatch(1, "hello"); // Int: 1, String: hello

// You can always register a general handler:
$fn[['*']] = fn($a) => "Fallback/default handler";
echo $fn([]); // Fallback/default handler
```

---

How it works:
- You register handlers for each type signature (single or multiple arguments, e.g. [‘int’, ‘string’]).
- On call, the dispatcher figures out the most specific match (based on type, class, interface, and inheritance).
- The handler runs.
- No CLOS extensions means there’s one winner, no call_next, and no :before/:after/:around stacking.
- This style is familiar if you know Python’s functools.singledispatch or Julia’s basic dispatch.

When to use:
- You want simple, one-winner dispatch logic.
- You’re porting code from a previous version of this package.
- You don’t need CLOS-style hooks or method chaining (yet).

---

## Installation on Linux (Ubuntu) and Windows (Scoop)

### Linux (Ubuntu)

```bash
sudo apt update
sudo apt install -y php php-cli php-xml php-zip php-curl phpunit composer
```

### Windows (with Scoop)

Install Scoop (if not already):

```powershell
irm get.scoop.sh | iex
scoop install php composer
```

*For MacOS: See the detailed installation article (link see below!).*

---

## Further Reading

- [Installing PHP 8 with phpenv: The Hard Way (But Right)](https://medium.com/devops-dev/installing-php-8-with-phpenv-the-hard-way-but-right-920a0a8ea1e5?sk=7aa96bfe9bd8b5e3896833d91fb0eaa8)  
  A hands-on, step-by-step guide to installing PHP 8 on MacOS using `phpenv`—great for anyone who wants full control over their PHP version.

- [Beyond If-Else: Smarter Function Dispatch in PHP](https://medium.com/data-science-collective/beyond-if-else-smarter-function-dispatch-in-php-814dadaf3600?sk=41c34e6c95134f653a494a0173f30026)  
  Dive into function dispatching strategies in PHP—understand why multiple dispatch makes your code smarter and cleaner.

---

## API Reference (Coming Soon)

---

## Contributing

Pull requests are welcome—especially for other method combinations, optimizations, and new tests.

---

## License

MIT







---

## License

MIT © Gwang-Jin Kim

---

