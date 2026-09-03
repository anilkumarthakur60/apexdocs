<?php

declare(strict_types=1);

use ApexDocs\Tests\TestCase;

/*
 * The Laravel-flavoured TestCase (Orchestra Testbench) is only required for
 * tests that touch the Laravel bridge. Feature tests for the framework-agnostic
 * core run on plain Pest - keeps them fast and reproducible.
 */
uses(TestCase::class)->in('Feature/Laravel', 'Unit/Bridge/Laravel');
