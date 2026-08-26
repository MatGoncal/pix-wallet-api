<?php

use Tests\ConcurrencyTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature');

pest()->extend(ConcurrencyTestCase::class)
    ->in('Concurrency');
