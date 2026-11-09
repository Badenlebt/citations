<?php

declare(strict_types=1);

namespace Citations\Http;

use DateTimeImmutable;

/**
 * Single source of truth for the HttpOnly session cookie that carries the
 * authentication token (design "Session-Guard", Requirement 6).
 *
 * Both ends of the session lifecycle reference this one place so they can never
 * drift: {@see Request::fromGlobals()} reads the presented token from a cookie
 * of {@see self::NAME} (used by the session guard, 7.1/7.2/7.5), and the login
 * endpoint ({@see AuthHandler}) sets the cookie under the exact same name after
 * a successful login (6.1), while logout clears it (6.6). Keeping the name and
 * the attribute policy here — rather than as string literals spread across the
 * code — guarantees the guard reads precisely the cookie the login flow writes.
 */
final class SessionCookie
{
    /** Name of the HttpOnly cookie holding the session token (6.1, 7.1). */
    public const NAME = 'citations_session';

    private function __construct()
    {
    }

    /**
     * Build the `Set-Cookie` header line that stores the session token on a
     * successful login (6.1).
     *
     * The cookie is HttpOnly (never readable from JavaScript), scoped to the
     * whole app (`Path=/`) and `SameSite=Lax`, and its `Expires`/`Max-Age`
     * match the session's expiry so the browser drops it when the server-side
     * session lapses (6.1/6.4). The value is the token under {@see self::NAME},
     * i.e. the same cookie {@see Request::fromGlobals()} reads the token from,
     * so the guard reads precisely what login wrote. `Max-Age` is never
     * negative: if the derived lifetime is non-positive it is pinned to 0.
     *
     * @param string            $token     The session token to carry.
     * @param DateTimeImmutable $now       The current instant (session creation).
     * @param DateTimeImmutable $expiresAt When the session (and cookie) expire.
     */
    public static function setHeader(string $token, DateTimeImmutable $now, DateTimeImmutable $expiresAt): string
    {
        $maxAge = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());

        return self::headerLine($token, $expiresAt, $maxAge);
    }

    /**
     * Build the `Set-Cookie` header line that clears the session cookie on
     * logout (6.6): an empty value with an already-past expiry and `Max-Age=0`,
     * under the same name and path so the browser removes exactly the cookie
     * login set. After this the token in that cookie grants no further access.
     */
    public static function clearHeader(): string
    {
        return self::headerLine('', new DateTimeImmutable('@0'), 0);
    }

    /**
     * Assemble the raw `Set-Cookie` line with the fixed HttpOnly/Path/SameSite
     * attribute policy shared by the set and clear cases. The expiry is rendered
     * in the RFC 7231 GMT format browsers expect.
     */
    private static function headerLine(string $value, DateTimeImmutable $expiresAt, int $maxAge): string
    {
        $expires = $expiresAt->setTimezone(new \DateTimeZone('GMT'))->format('D, d M Y H:i:s \G\M\T');

        return sprintf(
            'Set-Cookie: %s=%s; Path=/; Expires=%s; Max-Age=%d; HttpOnly; SameSite=Lax',
            self::NAME,
            $value,
            $expires,
            $maxAge,
        );
    }
}
