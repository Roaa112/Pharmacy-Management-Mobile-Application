<?php

namespace Tests;

use App\Models\Product;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Discount rules are cached per store-clock second; each test starts with a fresh database.
        Product::flushActiveDiscountCache();
        \App\Models\Country::flushDefault();
    }
}
