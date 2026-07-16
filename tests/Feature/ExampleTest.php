<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // SmartEPT redirects the root URL to the admin console by design.
        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}
