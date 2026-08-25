<?php

namespace Tests\Feature;

use App\Support\UploadFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The php.ini limits sit outside the application but decide whether it can
 * honour its own advertised 5 MB. When they bite, the failure has to say so.
 */
class UploadLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('images.disk'));
    }

    public function test_a_body_over_post_max_size_is_a_clean_413(): void
    {
        $this->actingAsApiUser();

        $response = $this->call('POST', '/api/images', server: [
            'CONTENT_LENGTH' => (string) (30 * 1024 * 1024),
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(413);
        $this->assertSame('The uploaded file may not be larger than 5 MB.', $response->json('message'));

        // A stack trace here means the wrong exception class was caught.
        $response->assertJsonMissingPath('exception');
        $response->assertJsonMissingPath('trace');
    }

    public function test_a_server_size_limit_is_reported_as_such_not_as_a_broken_image(): void
    {
        $file = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'ini_'),
            'holiday.png',
            'image/png',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $message = UploadFailure::describe($file);

        $this->assertStringContainsString('upload_max_filesize', $message);
        $this->assertStringContainsString('php.ini', $message);
        $this->assertStringNotContainsString('failed to upload', $message);
    }

    public function test_a_truncated_upload_says_to_retry(): void
    {
        $file = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'part_'),
            'holiday.png',
            'image/png',
            UPLOAD_ERR_PARTIAL,
            true,
        );

        $this->assertStringContainsString('partially uploaded', UploadFailure::describe($file));
    }

    public function test_the_request_surfaces_the_server_limit_through_validation(): void
    {
        $this->actingAsApiUser();

        $file = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'ini_'),
            'holiday.png',
            'image/png',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $this->postJson('/api/images', ['image' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->assertStringContainsString(
            'upload_max_filesize',
            $this->postJson('/api/images', ['image' => $file])->json('errors.image.0'),
        );
    }

    public function test_the_doctor_reports_on_the_running_environment(): void
    {
        $this->artisan('images:doctor')
            ->expectsOutputToContain('upload_max_filesize')
            ->expectsOutputToContain('post_max_size')
            ->expectsOutputToContain('display_errors')
            ->expectsOutputToContain('disk [');
    }
}
