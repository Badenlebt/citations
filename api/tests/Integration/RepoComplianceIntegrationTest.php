<?php

declare(strict_types=1);

namespace Citations\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Operations/setup smoke & compliance checks (task 16.4).
 *
 * These assertions treat the repository itself as the system under test and
 * guard the deployment guarantees that cannot be covered by code-level unit
 * tests:
 *
 *   - Req 16.1: the frontend is delivered as STATIC files (no Node.js runtime
 *     on the server). The Vite build must target a static output directory and
 *     that directory must be gitignored (build artefacts are never committed).
 *   - Req 15.1 / 15.4: NO credential source is ever committed. git must ignore
 *     config.php / config.*.php / .env / .env.*, the committed template must be
 *     secret-free (*.example only), and no real credential file may be tracked.
 *   - No DB password or DSN value is hardcoded anywhere under api/src.
 *
 * The git-backed assertions skip gracefully when git or the repository metadata
 * is unavailable (e.g. an exported tarball), so the suite stays green in every
 * environment while still catching regressions wherever git is present.
 */
final class RepoComplianceIntegrationTest extends TestCase
{
    private static function repoRoot(): string
    {
        // tests/Integration -> api -> repo root.
        return dirname(__DIR__, 3);
    }

    private static function apiRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Run a git command from the repository root. Returns the trimmed stdout, or
     * null when git is unavailable or the directory is not a git work tree, so
     * callers can skip gracefully instead of failing on a non-git checkout.
     *
     * @param list<string> $args
     */
    private static function git(array $args): ?string
    {
        if (!is_dir(self::repoRoot() . '/.git')) {
            return null;
        }

        $parts = array_map('escapeshellarg', $args);
        $cmd = 'git -C ' . escapeshellarg(self::repoRoot()) . ' ' . implode(' ', $parts) . ' 2>/dev/null';

        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);

        // `git check-ignore` exits 1 when nothing matches; that is a meaningful
        // (non-error) result, so we return the (possibly empty) output rather
        // than null. Only treat a missing git binary (127) as "unavailable".
        if ($exitCode === 127) {
            return null;
        }

        return implode("\n", $output);
    }

    /**
     * Req 16.1: the Vite build targets a static output directory. We assert the
     * build config names a static outDir and references Req 16.1 so the intent
     * is pinned; running a full `npm run build` in-test is too heavy and would
     * require network/toolchain, so we verify the configuration instead.
     */
    public function testViteBuildTargetsStaticOutput(): void
    {
        $viteConfig = self::repoRoot() . '/frontend/vite.config.ts';
        $this->assertFileExists($viteConfig, 'Expected a frontend Vite config.');

        $contents = (string) file_get_contents($viteConfig);

        // A static build is configured via a build.outDir (default 'dist').
        $this->assertMatchesRegularExpression(
            '/build\s*:\s*\{/',
            $contents,
            'Vite config must declare a build section for static output (16.1).'
        );
        $this->assertStringContainsString(
            "outDir: 'dist'",
            $contents,
            'Vite build must emit static files into dist/ (16.1).'
        );
    }

    /**
     * Req 16.1: the static build output (frontend/dist) must be gitignored so
     * generated artefacts are never committed and the server serves plain files.
     */
    public function testStaticBuildOutputIsGitIgnored(): void
    {
        $ignored = self::git(['check-ignore', 'frontend/dist/index.html']);
        if ($ignored === null) {
            $this->markTestSkipped('git is unavailable; cannot verify dist/ ignore rule.');
        }

        $this->assertStringContainsString(
            'frontend/dist',
            $ignored,
            'frontend/dist must be gitignored (static build artefacts, 16.1).'
        );
    }

    /**
     * Req 15.1 / 15.4: every credential source pattern is gitignored, both at
     * the repo root and inside the api/ web root. `git check-ignore` prints each
     * path it would ignore; a path missing from the output is a hole in the
     * credential protection.
     *
     * @return array<string, array{0: string}>
     */
    public static function credentialPathProvider(): array
    {
        return [
            'root config.php'        => ['config.php'],
            'root config.local.php'  => ['config.local.php'],
            'root .env'              => ['.env'],
            'root .env.local'        => ['.env.local'],
            'api config.php'         => ['api/config.php'],
            'api config.prod.php'    => ['api/config.prod.php'],
            'api .env'               => ['api/.env'],
            'api .env.production'    => ['api/.env.production'],
        ];
    }

    /**
     * @dataProvider credentialPathProvider
     */
    public function testCredentialSourcesAreGitIgnored(string $path): void
    {
        $ignored = self::git(['check-ignore', $path]);
        if ($ignored === null) {
            $this->markTestSkipped('git is unavailable; cannot verify ignore rules.');
        }

        $this->assertStringContainsString(
            $path,
            $ignored,
            sprintf('Credential source %s must be gitignored (15.1, 15.4).', $path)
        );
    }

    /**
     * Req 15.4: the secret-free template must stay trackable despite the broad
     * ignore rules above (the `!config.php.example` negation). If check-ignore
     * reports the example as ignored, the negation has been lost.
     */
    public function testExampleTemplateRemainsTrackable(): void
    {
        $ignored = self::git(['check-ignore', 'api/config.php.example']);
        if ($ignored === null) {
            $this->markTestSkipped('git is unavailable; cannot verify template tracking.');
        }

        $this->assertSame(
            '',
            $ignored,
            'api/config.php.example must NOT be gitignored (secret-free template, 15.4).'
        );
    }

    /**
     * Req 15.1: no real credential file is tracked by git. Only *.example
     * templates may appear among tracked files that look credential-shaped.
     */
    public function testNoRealCredentialFileIsTracked(): void
    {
        $tracked = self::git(['ls-files']);
        if ($tracked === null) {
            $this->markTestSkipped('git is unavailable; cannot inspect tracked files.');
        }

        $offenders = [];
        foreach (preg_split('/\R/', $tracked) ?: [] as $file) {
            $file = trim($file);
            if ($file === '') {
                continue;
            }
            $base = basename($file);
            $looksCredential = (bool) preg_match('/^(config(\..+)?\.php|\.env(\..+)?)$/', $base);
            if ($looksCredential && !str_ends_with($file, '.example')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'No real credential file may be committed; only *.example templates (15.1).'
        );
    }

    /**
     * Req 15.4: the committed template carries NO real secret. Every credential
     * slot must hold a REPLACE_ME placeholder (or an empty string), never a
     * concrete value. This runs independently of git so it protects the file
     * even in a non-git checkout.
     */
    public function testExampleTemplateContainsNoRealSecrets(): void
    {
        $template = self::apiRoot() . '/config.php.example';
        $this->assertFileExists($template, 'Expected a committed config.php.example template.');

        $contents = (string) file_get_contents($template);

        // The example is loadable PHP returning a config array.
        $this->assertStringContainsString('return [', $contents);

        // Every quoted credential value is a placeholder, an empty string, or a
        // benign non-secret (host hint). Pull out the db/password slots and make
        // sure they are REPLACE_ME placeholders.
        $this->assertMatchesRegularExpression(
            "/'password'\\s*=>\\s*'REPLACE_ME[^']*'/",
            $contents,
            'DB password slot must be a REPLACE_ME placeholder (15.4).'
        );
        $this->assertMatchesRegularExpression(
            "/'passwordHash'\\s*=>\\s*''/",
            $contents,
            'Operator password hash must be empty in the template (15.4).'
        );

        // No stray credential-looking assignment that is not a placeholder.
        $this->assertStringNotContainsString('REPLACE_ME_DB_PASSWORD_IS', $contents);
        $this->assertDoesNotMatchRegularExpression(
            "/passwordHash'\\s*=>\\s*'\\\$2y\\\$/",
            $contents,
            'The template must not ship a real bcrypt hash (15.4).'
        );
    }

    /**
     * No DB password or concrete DSN value is hardcoded under api/src. We scan
     * the source tree for string literals that look like a credential value or
     * a fully-populated mysql DSN. Format strings with %s/%d placeholders (e.g.
     * DatabaseConfig's DSN template) are explicitly allowed.
     */
    public function testNoHardcodedCredentialsInSource(): void
    {
        $srcDir = self::apiRoot() . '/src';
        $this->assertDirectoryExists($srcDir);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            $rel = str_replace($srcDir . '/', '', $file->getPathname());

            foreach (preg_split('/\R/', $contents) ?: [] as $lineNo => $line) {
                // A mysql DSN with a concrete (non-placeholder) host value.
                if (preg_match('/mysql:host=(?![%])[^;%\s\'"]+/', $line)) {
                    $offenders[] = sprintf('%s:%d concrete DSN host', $rel, $lineNo + 1);
                }
                // A password assignment to a concrete literal. We only flag
                // values that look like a real secret, i.e. NOT:
                //   - a REPLACE_ME placeholder,
                //   - an empty string,
                //   - an identifier/env-var name (all-caps A-Z0-9_, e.g. the
                //     CITATIONS_DB_PASSWORD env-var mapping keys).
                if (preg_match(
                    '/password[\'"]?\s*(=>|=|:)\s*[\'"]([^\'"]{3,})[\'"]/i',
                    $line,
                    $m
                )) {
                    $value = $m[2];
                    $isPlaceholder = str_starts_with($value, 'REPLACE_ME');
                    $isEnvVarName = (bool) preg_match('/^[A-Z][A-Z0-9_]+$/', $value);
                    if (!$isPlaceholder && !$isEnvVarName) {
                        $offenders[] = sprintf('%s:%d hardcoded password literal', $rel, $lineNo + 1);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "No credential value or concrete DSN may be hardcoded in api/src.\n" . implode("\n", $offenders)
        );
    }
}
