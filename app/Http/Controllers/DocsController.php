<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Serves the OpenAPI contract and the Swagger UI that reads it. The spec is the
 * file in the repository root, so the browsable docs and the committed contract
 * can never disagree.
 */
class DocsController extends Controller
{
    public function index(): HttpResponse
    {
        return response()->view('docs', [
            'specUrl' => route('docs.spec'),
            // Built here rather than in the template: Blade reads `@{{` as an
            // escape sequence, so the version cannot be interpolated after an
            // `@` in the markup.
            'assets' => 'https://unpkg.com/swagger-ui-dist@'.config('docs.swagger_ui_version'),
        ]);
    }

    public function spec(): Response
    {
        $path = (string) config('docs.spec');

        abort_unless(is_file($path), 404, 'The API specification is missing.');

        // The committed spec names localhost:8000. Rewriting it to whatever host
        // actually served this request is what makes "Try it out" work behind a
        // tunnel, a container port mapping or a deployed domain.
        $yaml = str_replace(
            '  - url: http://localhost:8000/api',
            '  - url: '.url('/api'),
            (string) file_get_contents($path),
        );

        return response($yaml, 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
        ]);
    }
}
