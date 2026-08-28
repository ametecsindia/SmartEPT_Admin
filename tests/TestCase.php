<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite must not read the developer's own licence file.
     *
     * 27-Aug-2026: three M9LicenseTest cases failed on Ejaz's PC and passed everywhere else.
     * Not the code — `C:\laragon\www\smartept\license.lic` (a real signed .lic, needed to run
     * his dev server) sits in the app root, and `LicenseFile::path()` defaults to
     * `base_path('license.lic')`. So every test ran against a LICENSED install:
     *
     *     configured             true, where the test asserts an unlicensed install
     *     evaluation_days_left   null, where the test asserts 0
     *     last_error             null, because a file licence never phones home
     *
     * A test whose result depends on an untracked file in the working directory is worse than
     * a failing test: it is green in CI, green on a fresh clone, red only on the machine that
     * actually ships the product — and it reads as "your change broke licensing".
     *
     * Pointed at a path that does not exist, in ONE place, so no individual test has to
     * remember. Nothing here writes a licence file; the tests that need licensed state build
     * it in the database through InstallationLicense (see OnPremLicenceTest), which is what
     * they should be doing anyway. A test that genuinely needs a file on disk sets its own
     * `smartept.license_file` and is then explicit about it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['smartept.license_file' => storage_path('app/tests-no-licence-file.lic')]);
    }

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
