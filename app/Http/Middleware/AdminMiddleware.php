<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;

class AdminMiddleware
{
    protected $auth;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */

    public function __construct(Auth $auth)
    {
        $this->auth = $auth;
    }
    public function handle($request, Closure $next, $guard = null)
    {
        if (!$this->auth->user()->admin) {
            $response = [
                "status" => "401 Unauthorized",
                "data" => null,
                "message" => "Access denied"
            ];
            return response($response);
        }
        return $next($request);
    }
}
