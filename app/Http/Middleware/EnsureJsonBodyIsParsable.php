<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A body that claims to be JSON but does not parse becomes an empty request,
 * and validation then reports every field as missing -- which sends people
 * hunting for a bug in their field names rather than in their syntax. One smart
 * quote pasted from a chat client is enough to trigger it. Say what actually
 * went wrong instead.
 */
class EnsureJsonBodyIsParsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $content = $request->getContent();

        if ($request->isJson() && $content !== '') {
            try {
                json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                return response()->json([
                    'message' => 'The request body is not valid JSON: '.$e->getMessage().'.',
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        return $next($request);
    }
}
