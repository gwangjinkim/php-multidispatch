# php-multidispatch: Multiple Dispatch (with CLOS Extensions) for PHP

[![Packagist](https://img.shields.io/packagist/v/gwangjinkim/php-multidispatch)](https://packagist.org/packages/gwangjinkim/php-multidispatch)
[![Tests](https://github.com/gwangjinkim/php-multidispatch/actions/workflows/tests.yml/badge.svg)](https://github.com/gwangjinkim/php-multidispatch/actions)

A modern, readable, CLOS-inspired multiple dispatch library for PHP—now with support for :primary, :before, :after, :around, and call-next-method! Open-Closed Principle in action, no fuss.

- **GitHub:** [gwangjinkim/php-multidispatch](https://github.com/gwangjinkim/php-multidispatch)
- **Packagist:** [gwangjinkim/php-multidispatch](https://packagist.org/packages/gwangjinkim/php-multidispatch)

---

## What is Multiple Dispatch? (And Why Should You Care?)

Multiple dispatch lets you write functions whose behavior depends on the *runtime types* of *all* their arguments—not just the first one (like in classic OOP). Imagine polymorphism that’s not boxed into inheritance hierarchies, with the freedom to extend methods for new types, even across package boundaries.

**Why is this awesome?**
- Write cleaner, more declarative code (no tangled if-else or switch statements).
- Add new behaviors from *outside* existing packages—no patching or hacking core code.
- Follow the Open-Closed Principle: your code is open for extension, closed for modification.
- Real power: this is what makes Julia, CLOS (Common Lisp), and R so extensible.

**Multiple Dispatch allows you to import a package (which uses Multiple Dispatch) and extend it without ever having to touch or change its internal code (tests, definitions), but just add more classes and more methods/functions. It allows you to follow open-closed principle which would be impossible with classical Java/C++ OOP systems.**

---

## Features

- **True Multiple Dispatch** (not just single dispatch)
- **CLOS-style Method Combinations:** :primary, :before, :after, :around, and `$callNext`
- **Inheritance & Interface Awareness:** Works with classes, interfaces, built-in types
- **Fallbacks:** Register wildcard methods for default cases
- **Simple API:** Intuitive, modern PHP code

---

## Table of Contents

1. [Installation](#installation)
2. [Classic Usage (No CLOS Extensions)](#classic-usage-multiple-dispatch-the-simple-way-no-clos-extensions)
3. [CLOS Extensions: :primary, :before, :after, :around, and call-next-method](#clos-style-method-combination)
4. [Extended Example: CLOS in PHP](#extended-clos-example)
5. [Testing](#testing)
6. [Troubleshooting](#troubleshooting)
7. [Links & References](#links--references)

---

## Installation

### With Composer

```bash
composer require gwangjinkim/php-multidispatch
```

Or add to your `composer.json`:

```json
"require": {
    "gwangjinkim/php-multidispatch": "^1.0"
}
```

### Requirements

- PHP 8.1 or newer recommended

### PHP Installation Shortcuts

- **MacOS:** See [this detailed guide](#) (insert your own article link here)
- **Linux (Ubuntu):**
    ```bash
    sudo apt update
    sudo apt install -y php php-cli php-xml php-zip php-curl phpunit composer
    ```
- **Windows (with Scoop):**
    ```powershell
    scoop install php composer
    ```

---

## Classic Usage: Multiple Dispatch the Simple Way (No CLOS Extensions)

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

**How it works:**
- You register handlers for each type signature (single or multiple arguments, e.g. ['int', 'string']).
- On call, the dispatcher figures out the most specific match (based on type, class, interface, and inheritance).
- The handler runs.
- No CLOS extensions means there’s one winner, no call_next, and no :before/:after/:around stacking.
- This style is familiar if you know Python’s functools.singledispatch or Julia’s basic dispatch.

**When to use:**
- You want simple, one-winner dispatch logic.
- You’re porting code from a previous version of this package.
- You don’t need CLOS-style hooks or method chaining (yet).

---

## CLOS-Style Method Combination

Inspired by the Common Lisp Object System (CLOS), you can now combine multiple method types:

- **:primary** – The main methods, as before
- **:before** – Run before the primaries (in specificity order)
- **:after** – Run after the primaries (in reverse specificity)
- **:around** – Can wrap everything (get `$callNext` as first argument; call it to proceed)
- **call-next-method** – Provided to :around methods as `$callNext` (you must call it to continue the chain)

**Registration Syntax:**

```php
$fn[ [Type1, ...], ':before' ] = function ($a, ...) { /* ... */ };
$fn[ [Type1, ...], ':primary' ] = function ($a, ...) { /* ... */ };
$fn[ [Type1, ...], ':after'  ] = function ($a, ...) { /* ... */ };
$fn[ [Type1, ...], ':around' ] = function ($callNext, $a, ...) { /* ... */ };
```

**Example:**

```php
require "vendor/autoload.php";
use function Multidispatch\multidispatch;

interface IA {}
class A implements IA {}
class B extends A {}

$fn = multidispatch();

$fn[ [A::class], ':primary' ] = fn($a) => "Primary A";
$fn[ [A::class], ':before'  ] = fn($a) => print("Before A\n");
$fn[ [A::class], ':after'   ] = fn($a) => print("After A\n");
$fn[ [A::class], ':around'  ] = function($callNext, $a) {
    print("Around A (before)\n");
    $result = $callNext($a);
    print("Around A (after)\n");
    return $result;
};

echo $fn(new A());
```

**Output:**
```
Before A
Around A (before)
Primary A
Around A (after)
After A
Primary A
```

**How the Method Combination Works:**
1. All matching :before methods are called, from most specific to least.
2. All :around methods wrap the chain (most specific is outermost).
3. The most specific :primary method is called.
4. All matching :after methods are called, from least specific to most.

You can combine :before, :after, :primary, and :around on any type signature.

---

## Extended CLOS Example

Here is a full, extended example to clarify usage and show best practices—great for both CLOS and non-CLOS users.

```php
require "vendor/autoload.php";
use function Multidispatch\multidispatch;

// Interfaces & classes
interface Animal {}
class Dog implements Animal {}
class Cat implements Animal {}

// Create a dispatcher
$battle = multidispatch();

// Register primary methods
$battle[[Dog::class, Dog::class], ':primary']   = fn($a, $b) => "Dog vs Dog: Bark!";
$battle[[Animal::class, Animal::class], ':primary'] = fn($a, $b) => "Generic animal fight";

// Before and after hooks
$battle[[Dog::class, Dog::class], ':before'] = fn($a, $b) => print("Sniffing each other\n");
$battle[[Dog::class, Dog::class], ':after']  = fn($a, $b) => print("Wagging tails\n");

// Around (wrapping the dispatch, controlling the flow)
$battle[[Animal::class, Animal::class], ':around'] = function($callNext, $a, $b) {
    print("Arena lights up\n");
    $result = $callNext($a, $b);
    print("Audience cheers\n");
    return $result;
};

// Run dispatch!
echo $battle(new Dog(), new Dog());
echo $battle(new Cat(), new Dog());
```

**What you'll see:**
```
Sniffing each other
Arena lights up
Dog vs Dog: Bark!
Audience cheers
Wagging tails
Arena lights up
Generic animal fight
Audience cheers
```

**How to Think About Each Method Type:**

- **:primary:** The "main event"—your usual handler, one per match.
- **:before:** Side-effects you want to happen *before* the main event, e.g., logging, setup, animation, etc.
- **:after:** Side-effects *after* the main event, e.g., cleanup, logging, summary.
- **:around:** Like a decorator or middleware: can do stuff before/after and even decide not to call the rest at all (by not calling `$callNext`).
- **call-next-method:** Only available inside :around. Lets you control the chain, e.g., by running or skipping underlying methods.

---

## Testing

Run your tests with PHPUnit:

```bash
./vendor/bin/phpunit tests
```

You’ll see all your dispatch rules and method combinations in action. Both classic and CLOS-style tests are included.

---

## Troubleshooting

- **Function not found errors?** Make sure you've run `composer dump-autoload` and are loading via `vendor/autoload.php`.
- **Order of registration:** If you register multiple methods for the same types, the *most specific* (class, subclass, interface) wins.
- **:around method argument order:** `$callNext` must come first.

If you get stuck, check out [the examples](examples/example.php) or open an issue.

---

## Links & References

- [Official Packagist Package](https://packagist.org/packages/gwangjinkim/php-multidispatch)
- [Project on GitHub](https://github.com/gwangjinkim/php-multidispatch)
- [Installing PHP with phpenv (detailed guide)](https://medium.com/devops-dev/installing-php-8-with-phpenv-the-hard-way-but-right-920a0a8ea1e5?sk=7aa96bfe9bd8b5e3896833d91fb0eaa8)
- [Beyond if-else: Smarter function dispatch in PHP (Medium)](https://medium.com/data-science-collective/beyond-if-else-smarter-function-dispatch-in-php-814dadaf3600?sk=41c34e6c95134f653a494a0173f30026)
- [CLOS on Wikipedia](https://en.wikipedia.org/wiki/Common_Lisp_Object_System)
- [Julia Multiple Dispatch](https://docs.julialang.org/en/v1/manual/methods/)

---

## Call to Action

**Ready to transform your PHP codebase?**

- ⭐ Star the [GitHub project](https://github.com/gwangjinkim/php-multidispatch)
- 🚀 Try it out in your next side project
- 🐞 File issues or share feedback for improvements

Welcome to a more elegant, powerful PHP. Enjoy dispatching!

