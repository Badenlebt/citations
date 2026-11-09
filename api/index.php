<?php

declare(strict_types=1);

/**
 * PHP front-controller entry point for the citations API (design "PHP
 * Front-Controller `api/index.php`", Requirements 15.7, 16.3).
 *
 * This file is intentionally thin: it loads the autoloader, builds the request
 * from PHP's globals, wires the session guard around the dispatcher, the JSON
 * responder and the error boundary, and runs one request. All routing,
 * serialization and error-mapping logic lives in testable classes under
 * `src/Http`. The whole run is wrapped in the {@see \Citations\Http\ErrorMapper}
 * via the {@see \Citations\Http\FrontController}, so a config/DB failure or any
 * unexpected throwable becomes a generic 500 that leaks no credentials, SQL,
 * DSN, or stack trace (15.7).
 *
 * Writes are protected before they reach a handler: the dispatcher is wrapped
 * in a {@see \Citations\Http\SessionGuard}, and the front controller runs the
 * guard as its pipeline. The guard rejects any unauthenticated write with a
 * uniform 401 before dispatch (7.1, 7.5) and forwards reads/auth routes
 * untouched (7.3). Its {@see \Citations\TokenAuthenticator} needs a session
 * store backed by PDO; that PDO is resolved LAZILY (see below) so it is only
 * touched for writes and a config/DB failure still surfaces as a generic 500
 * through the error boundary rather than escaping at bootstrap.
 *
 * The per-API handlers (Search/Quote/Source/Language/Auth) are registered by
 * later tasks; until then the handler registry below is empty and any API path
 * resolves to a uniform NOT_FOUND, while unknown paths do the same — never an
 * uncaught error.
 */

use Citations\ConfigLoader;
use Citations\CredentialValidator;
use Citations\Http\AuthHandler;
use Citations\Http\Dispatcher;
use Citations\Http\ErrorMapper;
use Citations\Http\FrontController;
use Citations\Http\JsonResponder;
use Citations\Http\LanguageHandler;
use Citations\Http\PhpResponseEmitter;
use Citations\Http\QuoteHandler;
use Citations\Http\Request;
use Citations\Http\SearchHandler;
use Citations\Http\SeedingPipeline;
use Citations\InitialOperatorConfig;
use Citations\InitialOperatorSeeder;
use Citations\Http\SessionGuard;
use Citations\Http\SourceHandler;
use Citations\Http\SystemClock;
use Citations\LanguageCreator;
use Citations\PdoConnectionFactory;
use Citations\QuoteCreator;
use Citations\RandomQuoteSelector;
use Citations\RandomTokenGenerator;
use Citations\RateLimiter;
use Citations\SourceCreator;
use Citations\SourceDeleter;
use Citations\Repository\LazyLanguageRepository;
use Citations\Repository\LazyLoginAttemptRepository;
use Citations\Repository\LazyOperatorRepository;
use Citations\Repository\LazyQuoteRepository;
use Citations\Repository\LazySessionRepository;
use Citations\Repository\LazySourceRepository;
use Citations\SystemRandomNumberGenerator;
use Citations\TokenAuthenticator;

require __DIR__ . '/vendor/autoload.php';

$request = Request::fromGlobals();

// Single, memoized PDO resolver shared by every repository below, so one
// request opens at most one connection. It is invoked LAZILY — only when a
// handler actually touches the database — and always from inside the error
// boundary of FrontController::run(), so a missing/unreadable config or a
// connection failure maps to a generic 500 that leaks no credentials, DSN, or
// SQL (15.2, 15.6, 15.7). config.php is expected ABOVE the web root; env vars
// are the fallback (15.1).
$pdo = null;
$pdoFactory = static function () use (&$pdo): PDO {
    if ($pdo === null) {
        $config = (new ConfigLoader(__DIR__ . '/../config.php'))->loadDatabaseConfig();
        $pdo = (new PdoConnectionFactory())->connect($config);
    }

    return $pdo;
};

// The Search-API handler serves the public read endpoints GET /search and
// GET /random (both route to the SEARCH handler key; it distinguishes them by
// path segment). Its repositories and random selector resolve the PDO lazily
// through the shared factory, so a DB failure still surfaces as a generic 500
// through the boundary below rather than at bootstrap (15.7).
$quotes = new LazyQuoteRepository($pdoFactory);
$languages = new LazyLanguageRepository($pdoFactory);
$searchHandler = new SearchHandler(
    $quotes,
    $languages,
    new RandomQuoteSelector($quotes, new SystemRandomNumberGenerator()),
);

// The Zitat-API handler serves the public GET /quotes/{id} single view (4) and
// the protected POST/PUT/DELETE mutations (8, 9). Its QuoteCreator needs source
// and language repositories (to resolve/validate the source — including the
// Unbekannt fallback, 13.3 — and the language); all three repositories resolve
// the PDO lazily through the shared factory, so a DB failure still surfaces as a
// generic 500 through the boundary below rather than at bootstrap (15.7). The
// writes are already protected by the SessionGuard before dispatch (7.4), so the
// handler itself does not re-check auth.
$sources = new LazySourceRepository($pdoFactory);
$quoteHandler = new QuoteHandler(
    new QuoteCreator($quotes, $sources, $languages),
    $quotes,
);

// The Autoren-API handler serves the public GET /sources/{id} single view (5)
// and the protected POST/PUT/DELETE mutations (10, 11). Its SourceCreator needs
// the source repository (create/edit validation incl. the reserved-name rule),
// and its SourceDeleter needs both the source and the quote repository (to count
// a source's quotes for the has-quotes conflict and to reassign them to the
// Unbekannt-Quelle on `?strategy=reassign`, 11.3). All resolve the PDO lazily
// through the shared factory, so a DB failure still surfaces as a generic 500
// through the boundary below rather than at bootstrap (15.7). The writes are
// already protected by the SessionGuard before dispatch, so the handler itself
// does not re-check auth.
$sourceHandler = new SourceHandler(
    new SourceCreator($sources),
    new SourceDeleter($sources, $quotes),
    $sources,
);

// The Sprach-API handler serves the public GET /languages list (12.7) and the
// protected POST /languages create (12.3). It composes over the LanguageCreator
// (trim / missing / too-long / case-insensitive duplicate validation and
// persistence, 12.4/12.5/12.6) and the shared lazy language repository's full
// list; the repository resolves the PDO lazily through the shared factory, so a
// DB failure still surfaces as a generic 500 through the boundary below rather
// than at bootstrap (15.7). The POST write is already protected by the
// SessionGuard before dispatch while the GET read is forwarded without a session
// (12.2), so the handler itself does not re-check auth.
$languageHandler = new LanguageHandler(
    new LanguageCreator($languages),
    $languages,
);

// The Auth-API handler serves the public POST /auth/login and POST /auth/logout
// routes (6.1, 6.6). These are NOT guarded by the SessionGuard — login must work
// without a session and logout is a no-op when unauthenticated (Dispatcher
// treats auth as a non-write route) — so the handler establishes/ends the
// session itself. It composes over the pure CredentialValidator (completeness
// 6.3 + validity 6.2/6.10), the RateLimiter (6.5), the operator lookup, the
// session store and the TokenAuthenticator (logout, 6.6), enforcing the login
// order 6.9 (completeness -> rate limit -> validity). All repositories resolve
// the PDO lazily through the shared factory, so a DB failure still surfaces as a
// generic 500 through the boundary below rather than at bootstrap (15.7). The
// shared $sessions / $authenticator are reused by the SessionGuard below, so one
// request opens at most one connection. The session token is generated with a
// cryptographically secure source and the validity duration comes from the
// configured session.durationHours (null => SessionValidator's 24h default,
// 6.4). Multiple operator accounts are supported, initially one (6.8).
$sessions = new LazySessionRepository($pdoFactory);
$authenticator = new TokenAuthenticator($sessions);
// Shared operator repository: the Auth-API looks up operators for login, and the
// SeedingPipeline below uses it to idempotently seed the initial operator from
// the setup configuration (17.2/17.3). Both share one lazy PDO connection.
$operators = new LazyOperatorRepository($pdoFactory);
$configLoader = new ConfigLoader(__DIR__ . '/../config.php');
$sessionDurationHours = $configLoader->loadSessionDurationHours();
$authHandler = new AuthHandler(
    new CredentialValidator(),
    new RateLimiter(new LazyLoginAttemptRepository($pdoFactory)),
    $operators,
    $sessions,
    $authenticator,
    new RandomTokenGenerator(),
    new SystemClock(),
    $sessionDurationHours,
);

$handlers = [
    Dispatcher::SEARCH => $searchHandler,
    Dispatcher::QUOTES => $quoteHandler,
    Dispatcher::SOURCES => $sourceHandler,
    Dispatcher::LANGUAGES => $languageHandler,
    Dispatcher::AUTH => $authHandler,
];

// Wrap the dispatcher in the session guard. The guard's token authenticator is
// the SAME one the Auth-API uses, backed by a session repository whose PDO
// connection is built only on first use — i.e. only for a write route or a
// login/logout, and only from inside the error boundary of
// FrontController::run(), so config/DB failures map to a generic 500 (7.1, 15.7).
$dispatcher = new Dispatcher($handlers);
$pipeline = new SessionGuard($dispatcher, $authenticator, new SystemClock());

// Idempotently seed the initial operator account from the setup configuration
// at the start of request processing (17.2). Wrapping it as the outermost
// pipeline keeps the seed INSIDE FrontController::run()'s error boundary, so a
// config/DB failure maps to a generic 500 (15.7). Once an operator exists the
// seed is a no-op (17.3); an incomplete config seeds nothing (17.5). The config
// is read lazily so it only happens when a request is actually processed.
$pipeline = new SeedingPipeline(
    $pipeline,
    new InitialOperatorSeeder($operators),
    static fn (): InitialOperatorConfig => $configLoader->loadInitialOperator(),
);

$frontController = new FrontController(
    $pipeline,
    new JsonResponder(new PhpResponseEmitter()),
    new ErrorMapper(),
);

$frontController->run($request);
