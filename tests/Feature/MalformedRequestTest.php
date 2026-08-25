<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MalformedRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A smart quote pasted from a chat client is the usual culprit. Without
     * this the body parses to nothing and validation blames the fields.
     */
    public function test_a_json_body_that_does_not_parse_is_a_400_not_a_confusing_422(): void
    {
        $response = $this->call(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{"email":"ada@example.com","password":"secret","device_name":"phone'."\u{201D}".'}',
        );

        $response->assertStatus(400);
        $this->assertStringContainsString('not valid JSON', $response->json('message'));
        $response->assertJsonMissingPath('errors');
    }

    public function test_a_valid_body_still_reaches_validation(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_an_empty_body_is_left_to_validation(): void
    {
        $this->call(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '',
        )->assertStatus(422);
    }

    public function test_multipart_uploads_are_not_treated_as_json(): void
    {
        $this->actingAsApiUser();

        // Binary form bodies must pass straight through the JSON guard.
        $this->postJson('/api/images', [])->assertStatus(422)->assertJsonValidationErrors('image');
    }
}
