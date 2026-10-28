<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The tests read the panel in Turkish (phpunit.xml sets the server
        // default to tr) unless they ask for another language. The API
        // follows Accept-Language, and the test client says "en-us".
        $this->withHeader('Accept-Language', 'tr-TR,tr;q=0.9');
    }
}
