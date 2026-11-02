<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Runs before setUpTraits(), i.e. before RefreshDatabase drops every table. */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $db = (string) config('database.connections.' . config('database.default') . '.database');
        if (!str_ends_with($db, '_test')) {
            fwrite(STDERR, "Refusing to run tests against database '{$db}' (expected *_test)." . PHP_EOL);
            exit(1);
        }
    }
}
