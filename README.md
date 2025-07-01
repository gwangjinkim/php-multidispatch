# php-multidispatch

> Multiple dispatch for PHP — scalar-aware, class hierarchy-sensitive, and elegant. Inspired by Julia and Common Lisp (CLOS).

## Features

- ✅ Multiple dispatch on all arguments
- ✅ Supports scalar types (`int`, `string`, etc.)
- ✅ Resolves using class hierarchy
- ✅ Wildcard fallback (`'*'`)
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

## License

MIT © Gwang-Jin Kim

---

