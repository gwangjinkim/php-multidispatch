<?php

declare(strict_types=1);

namespace GwangJinKim\Multidispatch;

class DispatchPolicy {
    public const FIRST_WINS = 'first-wins';
    public const LAST_WINS = 'last-wins';
}