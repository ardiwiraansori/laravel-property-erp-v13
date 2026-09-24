<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_api_status(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'Property ERP API',
            ]);
    }
}
