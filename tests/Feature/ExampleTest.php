<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The application boots and the root responds. Post-install the root IS the community home — it serves the
     * forum index directly at the mount root (RH-4.1b, ADR-0071); RootRouteTest covers that contract in full.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->seed();

        $response = $this->get('/');

        $response->assertOk();
    }
}
