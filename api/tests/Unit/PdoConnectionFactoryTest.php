<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ConfigException;
use Citations\DatabaseConfig;
use Citations\ErrorCode;
use Citations\PdoConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Example test for {@see PdoConnectionFactory} covering the failure path of
 * Requirements 15.2/15.6/15.7: a connection error must surface as a generic
 * INTERNAL_ERROR without leaking credential values, the DSN, or the underlying
 * driver message/stack trace.
 *
 * The success path (an actual live MariaDB connection) is exercised by the
 * integration tests (task 11.4), not here.
 */
final class PdoConnectionFactoryTest extends TestCase
{
    public function testConnectionFailureIsGenericAndLeaksNothing(): void
    {
        if (!in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql driver not available.');
        }

        // Point at a non-routable address (TEST-NET-1, RFC 5737) with a short
        // connect timeout so the connect fails quickly and deterministically.
        $config = DatabaseConfig::fromArray([
            'host'     => '192.0.2.1',
            'port'     => 3306,
            'name'     => 'secret_db_name',
            'user'     => 'secret_user',
            'password' => 'secret_password',
        ]);

        try {
            (new PdoConnectionFactory())->connect($config, ['connectTimeoutSeconds' => 1]);
            $this->fail('Expected the connection to fail.');
        } catch (ConfigException $e) {
            $this->assertSame(ErrorCode::INTERNAL_ERROR, $e->errorCode());

            // No credential values or DSN fragments in the message...
            $message = $e->getMessage();
            $this->assertStringNotContainsString('secret_db_name', $message);
            $this->assertStringNotContainsString('secret_user', $message);
            $this->assertStringNotContainsString('secret_password', $message);
            $this->assertStringNotContainsString('192.0.2.1', $message);
            $this->assertStringNotContainsString('mysql:', $message);

            // ...and the driver exception is not chained, so it cannot resurface
            // via getPrevious() and leak through a log or stack trace.
            $this->assertNull($e->getPrevious());
        }
    }
}
