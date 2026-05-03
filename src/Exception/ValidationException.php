<?php

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Exception;

/**
 * Bad user input — file too large, invalid format, failed captcha, etc.
 *
 * HTTP 422 Unprocessable Entity.
 */
class ValidationException extends FlightSchoolException
{
    protected int $httpStatus = 422;
}
