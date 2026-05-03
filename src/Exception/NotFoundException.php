<?php

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Exception;

/**
 * Requested resource or template not found.
 *
 * HTTP 404 Not Found.
 */
class NotFoundException extends FlightSchoolException
{
    protected int $httpStatus = 404;
}
