<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The application boots and the root responds. Post-install the root is the community home, which
     * permanently redirects to the canonical /forums (RH-8); RootRouteTest covers that contract in full.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('forums.index'));
    }
}
