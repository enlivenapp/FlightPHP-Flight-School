<?php

declare(strict_types=1);

namespace Enlivenapp\FlightSchool\Exception;

/**
 * System misconfiguration — missing extension, no theme, no PluginView, etc.
 *
 * HTTP 500 Internal Server Error.
 */
class ConfigurationException extends FlightSchoolException
{
    protected int $httpStatus = 500;
}
