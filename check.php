<?php

require __DIR__ . '/vendor/autoload.php';

use function Multidispatch\multidispatch;

$fn = multidispatch();
var_dump($fn);
