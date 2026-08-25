<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_the_swagger_ui_page_is_served(): void
    {
        $response = $this->get('/docs')->assertOk();

        $response->assertSee('swagger-ui', false);
        $response->assertSee(config('docs.swagger_ui_version'), false);

        // The spec URL is JSON encoded into the page, so slashes are escaped.
        $this->assertStringContainsString(
            trim(json_encode(route('docs.spec')), '"'),
            $response->getContent(),
        );
    }

    public function test_the_spec_is_served_as_yaml(): void
    {
        $response = $this->get('/docs/openapi.yaml')->assertOk();

        $response->assertHeader('content-type', 'application/yaml; charset=UTF-8');
        $this->assertStringStartsWith('openapi: 3.1.0', $response->getContent());
    }

    public function test_the_spec_describes_every_route_the_api_exposes(): void
    {
        $spec = $this->get('/docs/openapi.yaml')->getContent();

        foreach ([
            '/auth/register', '/auth/login', '/auth/logout', '/auth/me',
            '/images:', '/images/{image}:', '/images/{image}/content:',
        ] as $path) {
            $this->assertStringContainsString($path, $spec);
        }
    }

    /**
     * The committed spec names localhost:8000. Serving it through the app
     * rewrites that to whoever answered the request, which is what keeps
     * "Try it out" working behind a container port map or a tunnel.
     */
    public function test_the_server_url_follows_the_host_that_served_it(): void
    {
        $spec = $this->get('http://images.example.test/docs/openapi.yaml')->getContent();

        $this->assertStringContainsString('url: http://images.example.test/api', $spec);
        $this->assertStringNotContainsString('url: http://localhost:8000/api', $spec);
    }

    public function test_the_root_route_points_at_the_documentation(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJsonPath('documentation_ui', url('docs'))
            ->assertJsonStructure(['name', 'documentation', 'health', 'endpoints']);
    }
}
