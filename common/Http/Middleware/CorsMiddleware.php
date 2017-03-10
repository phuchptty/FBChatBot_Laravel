<?php namespace Common\Http\Middleware;

use Closure;

class CorsMiddleware
{

    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (method_exists($response, 'header')) {
            $response->header('Access-Control-Allow-Methods', 'HEAD, GET, POST, PUT, PATCH, DELETE');
            $response->header('Access-Control-Allow-Headers', $request->header('Access-Control-Request-Headers'));
            $response->header('Access-Control-Allow-Origin', '*');
        }

        return $response;
    }
}