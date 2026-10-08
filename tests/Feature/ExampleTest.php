<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;
    /**
     * A basic test example.
     */
    public function test_the_workspace_requires_authentication(): void
    {
        $response = $this->getJson('/api/companies');

        $response->assertUnauthorized();
    }
}
