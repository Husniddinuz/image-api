<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImageListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_only_sees_their_own_images(): void
    {
        $ada = User::factory()->create();
        $grace = User::factory()->create();

        $mine = Image::factory()->count(3)->create(['user_id' => $ada->id]);
        Image::factory()->count(5)->create(['user_id' => $grace->id]);

        $this->actingAsApiUser($ada);

        $response = $this->getJson('/api/images')->assertOk();

        $this->assertCount(3, $response->json('data'));
        $this->assertEqualsCanonicalizing(
            $mine->pluck('id')->all(),
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_an_empty_library_returns_an_empty_list(): void
    {
        $this->actingAsApiUser();

        $this->getJson('/api/images')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_images_are_returned_newest_first(): void
    {
        $user = User::factory()->create();

        $old = Image::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDay()]);
        $new = Image::factory()->create(['user_id' => $user->id, 'created_at' => now()]);

        $this->actingAsApiUser($user);

        $ids = collect($this->getJson('/api/images')->json('data'))->pluck('id')->all();

        $this->assertSame([$new->id, $old->id], $ids);
    }

    public function test_the_list_is_cursor_paginated(): void
    {
        $user = User::factory()->create();
        Image::factory()->count(7)->create(['user_id' => $user->id]);

        $this->actingAsApiUser($user);

        $first = $this->getJson('/api/images?per_page=3')->assertOk();
        $first->assertJsonCount(3, 'data')->assertJsonStructure(['links' => ['next'], 'meta' => ['next_cursor']]);

        $second = $this->getJson('/api/images?per_page=3&cursor='.$first->json('meta.next_cursor'))->assertOk();
        $second->assertJsonCount(3, 'data');

        $this->assertEmpty(array_intersect(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        ));
    }

    public function test_the_page_size_is_capped(): void
    {
        $user = User::factory()->create();
        Image::factory()->count(3)->create(['user_id' => $user->id]);
        config()->set('images.max_per_page', 2);

        $this->actingAsApiUser($user);

        $this->getJson('/api/images?per_page=1000')->assertOk()->assertJsonCount(2, 'data');
    }
}
