<?php

namespace Tests\Unit;

use App\Services\Images\BlobPathResolver;
use Tests\TestCase;

class BlobPathResolverTest extends TestCase
{
    private BlobPathResolver $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paths = new BlobPathResolver;
    }

    public function test_it_fans_a_digest_out_over_two_directory_levels(): void
    {
        $hash = str_repeat('ab', 32);

        $this->assertSame(
            "images/blobs/ab/ab/{$hash}.webp",
            $this->paths->optimized($hash, 'webp'),
        );
    }

    public function test_originals_and_optimized_files_never_collide(): void
    {
        $hash = hash('sha256', 'a picture');

        $this->assertNotSame(
            $this->paths->original($hash, 'png'),
            $this->paths->optimized($hash, 'png'),
        );
    }

    public function test_the_same_content_always_lands_on_the_same_path(): void
    {
        $hash = hash('sha256', 'a picture');

        $this->assertSame(
            $this->paths->optimized($hash, 'webp'),
            $this->paths->optimized($hash, 'webp'),
        );
    }

    public function test_a_leading_dot_on_the_extension_is_tolerated(): void
    {
        $hash = hash('sha256', 'a picture');

        $this->assertSame(
            $this->paths->optimized($hash, 'webp'),
            $this->paths->optimized($hash, '.webp'),
        );
    }

    public function test_the_prefix_is_configurable_without_breaking_the_shape(): void
    {
        config()->set('images.paths.optimized', 'custom/place/');
        $hash = hash('sha256', 'a picture');

        $this->assertSame(
            sprintf('custom/place/%s/%s/%s.avif', substr($hash, 0, 2), substr($hash, 2, 2), $hash),
            $this->paths->optimized($hash, 'avif'),
        );
    }
}
