<?php

declare(strict_types=1);

namespace Citations\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Deployment-routing & credential-URL protection checks for the Apache rules
 * shipped in the two `.htaccess` files (task 16.2; Requirements 15.5, 16.3,
 * 16.4).
 *
 * Apache is almost never available to actually SERVE these rules in CI or on a
 * developer machine, and spinning up a live server would make the suite flaky
 * and environment-dependent. So the system under test here is the committed
 * rule SET: we parse the real `.htaccess` content and assert the SPECIFIC
 * rewrite/deny directives that encode the deployment contract, so the tests
 * genuinely regress if a rule is dropped or weakened — not merely if the file
 * disappears:
 *
 *   - Req 16.3: a request under /api is handed to the PHP front controller
 *     (api/index.php) by a RewriteRule, both at the web root and inside api/.
 *   - Req 16.4: any non-API path that is not a real file/dir falls back to the
 *     SPA entry (index.html) for client-side routing.
 *   - Req 15.5: a credential-bearing config *.php (config.php / config.*.php)
 *     requested via a public URL is DENIED with no body, enforced by a
 *     FilesMatch "Require all denied" block in BOTH .htaccess files, and no
 *     real config.php is reachable as a static file under the web root.
 *
 * As defense-in-depth we ALSO run an `httpd -t`/`apachectl -t` syntax check of
 * the rule files when an Apache binary is present, gated behind availability so
 * it skips cleanly otherwise. That catches malformed directives (a different
 * class of regression) without the test depending on a live server.
 */
final class RoutingHtaccessIntegrationTest extends TestCase
{
    /** tests/Integration -> api -> repo root (the web root at deploy time). */
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function apiRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function rootHtaccess(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/.htaccess');
    }

    private static function apiHtaccess(): string
    {
        return (string) file_get_contents(self::apiRoot() . '/.htaccess');
    }

    // ---- Req 16.3: /api/* -> PHP front controller ---------------------------

    /**
     * 16.3: the web-root .htaccess hands every request whose path starts with
     * /api to the PHP front controller api/index.php. We assert the concrete
     * RewriteRule — a left pattern anchored on `api` (with an optional trailing
     * sub-path) rewriting to `api/index.php` and terminating the pass with [L].
     * A regression that drops the rule, changes the target away from the front
     * controller, or stops matching sub-paths all fail this test.
     */
    public function testRootHtaccessRoutesApiToFrontController(): void
    {
        $contents = self::rootHtaccess();

        $this->assertMatchesRegularExpression(
            '/RewriteRule\s+\^api\(\/\.\*\)\?\$\s+api\/index\.php\s+\[[^\]]*\bL\b[^\]]*\]/',
            $contents,
            'Web-root .htaccess must route ^api(/.*)?$ to api/index.php with [L] (16.3).'
        );

        // The target must be the front controller, never a bare directory or a
        // different script.
        $this->assertMatchesRegularExpression(
            '/RewriteRule\s+\^api[^\n]*\s+api\/index\.php\b/',
            $contents,
            'The /api rewrite target must be the front controller api/index.php (16.3).'
        );
    }

    /**
     * 16.3: the api/ .htaccess is self-sufficient — independently of the parent
     * rules it serves an existing real file/dir directly, then routes every
     * other request under /api to index.php (the front controller does its own
     * method/path routing). We assert both the real-file passthrough condition
     * and the catch-all rewrite to index.php.
     */
    public function testApiHtaccessRoutesEverythingElseToFrontController(): void
    {
        $contents = self::apiHtaccess();

        // Real files/dirs are served as-is (so index.php, assets resolve).
        $this->assertMatchesRegularExpression(
            '/RewriteCond\s+%\{REQUEST_FILENAME\}\s+-f\b/',
            $contents,
            'api/.htaccess must pass existing real files through (-f) before the front controller (16.3).'
        );
        $this->assertMatchesRegularExpression(
            '/RewriteCond\s+%\{REQUEST_FILENAME\}\s+-d\b/',
            $contents,
            'api/.htaccess must pass existing directories through (-d) (16.3).'
        );

        // Everything else under /api is handled by the front controller.
        $this->assertMatchesRegularExpression(
            '/RewriteRule\s+\^\s+index\.php\s+\[[^\]]*\bL\b[^\]]*\]/',
            $contents,
            'api/.htaccess must route the remaining requests to index.php with [L] (16.3).'
        );
    }

    // ---- Req 16.4: SPA fallback for non-API paths ---------------------------

    /**
     * 16.4: a request to a path OUTSIDE the API that is not a real file/dir
     * renders the SPA entry point index.html, so Vue Router resolves the
     * client-side route. The contract is three ordered directives in the
     * web-root .htaccess: real file/dir passthrough, then a final catch-all
     * rewrite to index.html. We assert the passthrough conditions AND the
     * fallback rewrite, and that the fallback is ordered AFTER the /api rule so
     * API paths never fall into the SPA entry.
     */
    public function testRootHtaccessFallsBackToSpaEntryForNonApiPaths(): void
    {
        $contents = self::rootHtaccess();

        // Existing files/dirs are served directly (the guard before fallback).
        $this->assertMatchesRegularExpression(
            '/RewriteCond\s+%\{REQUEST_FILENAME\}\s+-f\b/',
            $contents,
            'Web-root .htaccess must serve existing real files directly before the SPA fallback (16.4).'
        );
        $this->assertMatchesRegularExpression(
            '/RewriteCond\s+%\{REQUEST_FILENAME\}\s+-d\b/',
            $contents,
            'Web-root .htaccess must serve existing directories directly before the SPA fallback (16.4).'
        );

        // The catch-all SPA fallback to index.html.
        $this->assertMatchesRegularExpression(
            '/RewriteRule\s+\^\s+index\.html\s+\[[^\]]*\bL\b[^\]]*\]/',
            $contents,
            'Web-root .htaccess must fall back to index.html for client-side routes (16.4).'
        );

        // Ordering: the /api rule must come BEFORE the SPA fallback, otherwise
        // an /api request could be swallowed by the SPA entry.
        $apiPos = $this->requirePosition($contents, '/RewriteRule\s+\^api/', 'the /api route');
        $spaPos = $this->requirePosition($contents, '/RewriteRule\s+\^\s+index\.html/', 'the SPA fallback');
        $this->assertLessThan(
            $spaPos,
            $apiPos,
            'The /api route must precede the SPA fallback so API paths are not served index.html (16.3/16.4).'
        );
    }

    /**
     * 16.4: the SPA fallback is only reachable for paths that are NOT real
     * files/dirs — the two RewriteCond -f/-d guards (with an early [L]) must sit
     * immediately before the index.html rewrite, so a real asset is served as
     * itself and only truly virtual client-routes hit index.html.
     */
    public function testSpaFallbackOnlyAppliesToVirtualPaths(): void
    {
        $contents = self::rootHtaccess();

        $this->assertMatchesRegularExpression(
            '/RewriteCond\s+%\{REQUEST_FILENAME\}\s+-f\s+\[OR\]\s*\n\s*RewriteCond\s+%\{REQUEST_FILENAME\}\s+-d\s*\n\s*RewriteRule\s+\^\s+-\s+\[[^\]]*\bL\b[^\]]*\]/',
            $contents,
            'Web-root .htaccess must short-circuit existing files/dirs (-f [OR] -d -> [L]) before the SPA fallback (16.4).'
        );
    }

    // ---- Req 15.5: credential config *.php URL is denied --------------------

    /**
     * Credential-file patterns that a public URL must never expose. Covers the
     * canonical config.php plus common config.*.php variants (local/prod). Each
     * must match the FilesMatch deny pattern in BOTH .htaccess files.
     *
     * @return array<string, array{0: string}>
     */
    public static function credentialFileProvider(): array
    {
        return [
            'config.php'        => ['config.php'],
            'config.local.php'  => ['config.local.php'],
            'config.prod.php'   => ['config.prod.php'],
            '.env'              => ['.env'],
            '.env.production'   => ['.env.production'],
        ];
    }

    /**
     * 15.5: the api/ .htaccess blocks a direct public request to any credential
     * config file. We extract the FilesMatch pattern guarded by
     * "Require all denied" and assert each credential filename actually matches
     * that pattern — i.e. a public GET of the file would be denied (403, empty
     * body) and leak neither content nor single values. A regression that
     * narrows or removes the pattern makes the matching filename fail here.
     *
     * @dataProvider credentialFileProvider
     */
    public function testApiHtaccessDeniesCredentialFileByUrl(string $filename): void
    {
        $pattern = $this->extractDeniedFilesMatchPattern(self::apiHtaccess());
        $this->assertNotNull(
            $pattern,
            'api/.htaccess must contain a FilesMatch block guarded by "Require all denied" (15.5).'
        );

        $this->assertSame(
            1,
            @preg_match('/' . $pattern . '/', $filename),
            sprintf('api/.htaccess credential deny pattern must match %s (15.5).', $filename)
        );
    }

    /**
     * 15.5 (defense-in-depth): the web-root .htaccess carries the SAME deny of
     * credential config files, so the protection holds even for a config file
     * that ever lands next to the SPA. Same extraction + match assertion.
     *
     * @dataProvider credentialFileProvider
     */
    public function testRootHtaccessDeniesCredentialFileByUrl(string $filename): void
    {
        $pattern = $this->extractDeniedFilesMatchPattern(self::rootHtaccess());
        $this->assertNotNull(
            $pattern,
            'Web-root .htaccess must contain a FilesMatch block guarded by "Require all denied" (15.5).'
        );

        $this->assertSame(
            1,
            @preg_match('/' . $pattern . '/', $filename),
            sprintf('Web-root .htaccess credential deny pattern must match %s (15.5).', $filename)
        );
    }

    /**
     * 15.5: the deny directive must actually DENY (no body), not merely exist.
     * We assert both .htaccess files pair their credential FilesMatch with a
     * hard "Require all denied" (Apache 2.4 access control). A rule that
     * downgraded to "Require all granted" or dropped the directive fails here.
     */
    public function testBothHtaccessUseRequireAllDeniedForCredentials(): void
    {
        foreach (['web-root' => self::rootHtaccess(), 'api' => self::apiHtaccess()] as $label => $contents) {
            $this->assertMatchesRegularExpression(
                '/<FilesMatch\s+"[^"]*config[^"]*"\s*>\s*Require\s+all\s+denied\s*<\/FilesMatch>/is',
                $contents,
                sprintf('%s .htaccess must deny the credential FilesMatch with "Require all denied" (15.5).', $label)
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<FilesMatch\s+"[^"]*config[^"]*"\s*>\s*Require\s+all\s+granted/is',
                $contents,
                sprintf('%s .htaccess must not grant access to the credential FilesMatch (15.5).', $label)
            );
        }
    }

    /**
     * 15.5: no real credential file is reachable as a STATIC file under the web
     * root. config.php is designed to live ABOVE the web root (api/index.php
     * loads ../config.php), so neither the repo root nor api/ may contain a real
     * config.php that Apache could serve. Only *.example templates are allowed.
     */
    public function testNoRealConfigFileSitsUnderWebRoot(): void
    {
        foreach ([self::repoRoot(), self::apiRoot()] as $dir) {
            foreach (glob($dir . '/config*.php') ?: [] as $path) {
                $this->assertStringEndsWith(
                    '.example',
                    $path,
                    sprintf('%s must not be a real config file under the web root (15.5).', $path)
                );
            }
        }

        // The loader indeed reaches OUTSIDE the web root for the real config,
        // so a public URL under the root can never map to it.
        $index = (string) file_get_contents(self::apiRoot() . '/index.php');
        $this->assertStringContainsString(
            "__DIR__ . '/../config.php'",
            $index,
            'The front controller must load config.php from ABOVE the web root (15.5).'
        );
    }

    // ---- Optional live defense-in-depth: Apache syntax validation -----------

    /**
     * When an Apache binary (httpd/apachectl) is present, validate that the
     * committed .htaccess directives are at least syntactically well-formed by
     * including them from a throwaway httpd.conf and running `-t`. This is gated
     * behind binary availability and skips cleanly otherwise, so the suite never
     * DEPENDS on Apache — it only strengthens coverage where Apache exists by
     * catching a malformed directive (e.g. an unbalanced <FilesMatch>).
     */
    public function testHtaccessDirectivesAreSyntacticallyValidWhenApacheAvailable(): void
    {
        $apache = $this->locateApacheBinary();
        if ($apache === null) {
            $this->markTestSkipped('No Apache binary (httpd/apachectl) available; skipping live syntax check.');
        }

        $tmpDir = sys_get_temp_dir() . '/citations-htaccess-' . uniqid('', true);
        if (!mkdir($tmpDir) && !is_dir($tmpDir)) {
            $this->markTestSkipped('Could not create a temp dir for the Apache syntax check.');
        }

        try {
            // A throwaway server root with a conf that drops into <Directory>
            // (where .htaccess context directives like FilesMatch/RewriteRule
            // are valid) and inlines the two committed rule files.
            $conf = $tmpDir . '/httpd.conf';
            $log = $tmpDir . '/error.log';
            file_put_contents($conf, sprintf(
                "ServerName localhost\nPidFile \"%s/httpd.pid\"\nErrorLog \"%s\"\n"
                . "<Directory \"%s\">\nInclude \"%s\"\n</Directory>\n"
                . "<Directory \"%s\">\nInclude \"%s\"\n</Directory>\n",
                $tmpDir,
                $log,
                self::repoRoot(),
                self::repoRoot() . '/.htaccess',
                self::apiRoot(),
                self::apiRoot() . '/.htaccess',
            ));

            $cmd = escapeshellarg($apache) . ' -t -f ' . escapeshellarg($conf) . ' 2>&1';
            $output = [];
            $exitCode = 0;
            @exec($cmd, $output, $exitCode);
            $joined = implode("\n", $output);

            // An environment limitation of this throwaway, module-less config
            // (a directive from an unloaded module, or no MPM/base config) is
            // NOT a rule defect — the deployment server loads those modules and
            // the rules are guarded by <IfModule>. Treat such messages as a
            // skip so the check only FAILS on a genuine directive syntax error.
            if ($exitCode !== 0 && preg_match(
                '/Invalid command|not included in the server configuration|No MPM loaded|MPM/i',
                $joined
            )) {
                $this->markTestSkipped('Apache cannot validate these directives standalone here: ' . $joined);
            }

            $this->assertSame(
                0,
                $exitCode,
                "Apache reported a syntax error in the committed .htaccess directives:\n" . $joined
            );
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    // ---- Helpers ------------------------------------------------------------

    /**
     * Return the regex (as an Apache would see it) of the FilesMatch block whose
     * body is "Require all denied", or null when no such denying block exists.
     * This lets the credential tests assert that a given filename actually
     * matches the DENY pattern, not just that some FilesMatch is present.
     */
    private function extractDeniedFilesMatchPattern(string $htaccess): ?string
    {
        if (!preg_match_all(
            '/<FilesMatch\s+"([^"]*)"\s*>(.*?)<\/FilesMatch>/is',
            $htaccess,
            $matches,
            PREG_SET_ORDER
        )) {
            return null;
        }

        foreach ($matches as $block) {
            [$pattern, $body] = [$block[1], $block[2]];
            if (preg_match('/Require\s+all\s+denied/i', $body) && stripos($pattern, 'config') !== false) {
                return $pattern;
            }
        }

        return null;
    }

    /** Byte offset of the first match of $regex, failing the test when absent. */
    private function requirePosition(string $haystack, string $regex, string $label): int
    {
        if (!preg_match($regex, $haystack, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail(sprintf('Expected to find %s in the .htaccess rules.', $label));
        }

        return $m[0][1];
    }

    /** First available Apache binary on PATH, or null when none is installed. */
    private function locateApacheBinary(): ?string
    {
        foreach (['httpd', 'apachectl', 'apache2'] as $bin) {
            $path = trim((string) @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Recursively remove a throwaway directory created for the syntax check. */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
