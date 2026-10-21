<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiHealthTest extends TestCase
{
    public function test_versioned_api_health_endpoint_returns_json(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'version' => 'v1',
            ]);
    }

    public function test_api_allows_the_configured_local_frontend_origin(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_database_errors_do_not_expose_sql_or_connection_details(): void
    {
        Route::get('/api/test-database-error', function () {
            throw new QueryException(
                'pgsql',
                'select * from products where secret_column = ?',
                [],
                new \PDOException('Could not connect to secret-database-host')
            );
        });

        $response = $this->getJson('/api/test-database-error')
            ->assertServiceUnavailable()
            ->assertJsonPath(
                'message',
                'No se pudo consultar la base de datos. Verifica la conexión PostgreSQL y que las migraciones estén aplicadas.'
            );

        $this->assertStringNotContainsString('secret_column', $response->getContent());
        $this->assertStringNotContainsString('secret-database-host', $response->getContent());
    }
}
