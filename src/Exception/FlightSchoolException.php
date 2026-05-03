<?php

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Exception;

/**
 * Base exception for all FlightSchool / Pubvana application errors.
 *
 * Carries an HTTP status code so the global error handler can translate
 * any uncaught application exception into the correct HTTP response
 * without guessing.
 */
class FlightSchoolException extends \RuntimeException
{
    protected int $httpStatus = 500;

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
