<?php

declare(strict_types=1);

namespace Citations;

use PDO;
use PDOException;

/**
 * Builds the PDO connection to MariaDB from a validated {@see DatabaseConfig}
 * (Requirements 15.1, 15.2, 15.6; design "Datenbank – MariaDB, erreicht über
 * PDO mit vorbereiteten Anweisungen").
 *
 * The factory does not know how credentials are sourced — it receives an
 * already-validated config, so by the time {@see self::connect()} runs the
 * required fields are guaranteed present and the port resolved (incl. the
 * MariaDB default, 15.3). It focuses on one concern: turning that config into a
 * PDO instance with safe defaults, and ensuring a connection failure can never
 * leak credential values (host, user, password, db name) or the DSN.
 *
 * On any PDO connection error the original PDOException — whose message can
 * contain the DSN, host, and user — is swallowed and replaced by a generic
 * {@see ConfigException} (INTERNAL_ERROR). The original is NOT chained, so it
 * cannot resurface through getPrevious() and leak into a log or stack trace
 * (15.2, 15.6, 15.7).
 */
final class PdoConnectionFactory
{
    /**
     * Open a PDO connection for the given validated configuration.
     *
     * Uses exception error mode, real (non-emulated) prepared statements, and
     * associative-array fetches by default, which the PDO repositories rely on.
     *
     * @param array{connectTimeoutSeconds?: int} $options Optional tuning. A
     *        positive `connectTimeoutSeconds` caps how long a TCP connect may
     *        block before failing (useful so an unreachable host fails fast).
     * @throws ConfigException on any connection failure, generically and without
     *         exposing credential values, the DSN, or the underlying driver
     *         message.
     */
    public function connect(DatabaseConfig $config, array $options = []): PDO
    {
        $driverOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $timeout = $options['connectTimeoutSeconds'] ?? null;
        if (is_int($timeout) && $timeout > 0) {
            $driverOptions[PDO::ATTR_TIMEOUT] = $timeout;
        }

        try {
            return new PDO(
                $config->dsn(),
                $config->user,
                $config->password,
                $driverOptions,
            );
        } catch (PDOException) {
            // Deliberately drop the driver exception: its message may carry the
            // DSN, host, or user. Surface only the generic failure, with no
            // chained previous, so nothing sensitive escapes (15.2, 15.6, 15.7).
            throw ConfigException::unreadableSource();
        }
    }
}
