<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Forget cached auth guards before every simulated request so each request
     * authenticates fresh from its own Authorization header — matching real HTTP
     * semantics. Without this, a test that logs in and then uses a second
     * (device) token keeps acting as the FIRST token's session, which breaks
     * every agent-flow assertion with bogus 403s.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
