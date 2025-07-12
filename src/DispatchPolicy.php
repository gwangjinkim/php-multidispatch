<?php

declare(strict_types=1);

namespace GwangJinKim\Multidispatch;

enum DispatchPolicy {

    case FirstWins;
    case LastWins;
}