<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The test client says "en-us" by default; the tests read the
        // panel in Turkish unless they ask for another language.
        $this->withHeader('Accept-Language', 'tr-TR,tr;q=0.9');
    }
}
