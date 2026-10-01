<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Force test database BEFORE Laravel bootstraps
        putenv('DB_DATABASE=crm_print_test');
        $_ENV['DB_DATABASE'] = 'crm_print_test';
        $_SERVER['DB_DATABASE'] = 'crm_print_test';

        parent::setUp();
    }
}

