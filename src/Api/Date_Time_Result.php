<?php

declare (strict_types=1);
namespace Aws\Api;

use Aws\Api\Parser\Exception\Parser_Exception;
use DateTime;
use DateTimeZone;
use Exception;
/**
 * DateTime overrides that make DateTime work more seamlessly as a string,
 * with JSON documents, and with JMESPath.
 */
class Date_Time_Result extends \DateTime implements \JsonSerializable, \Stringable
{
    private const ISO8601_NANOSECOND_REGEX = '/^(.*\.\d{6})(\d{1,3})(Z|[+-]\d{2}:\d{2})?$/';
    /**
     * Create a new DateTimeResult from a unix timestamp.
     * The Unix epoch (or Unix time or POSIX time or Unix
     * timestamp) is the number of seconds that have elapsed since
     * January 1, 1970 (midnight UTC/GMT).
     *
     * @throws Exception
     */
    public static function from_epoch($unix_timestamp): self
    {
        if (!is_numeric($unix_timestamp)) {
            throw new Parser_Exception('Invalid timestamp value passed to DateTimeResult::fromEpoch');
        }
        $decimal_separator = localeconv()['decimal_point'] ?? '.';
        $format_string = 'U' . $decimal_separator . 'u';
        $date_time = DateTime::create_from_format($format_string, sprintf('%0.6f', $unix_timestamp), new DateTimeZone('UTC'));
        if (false === $date_time) {
            throw new Parser_Exception('Invalid timestamp value passed to DateTimeResult::fromEpoch');
        }
        return new self($date_time->format('Y-m-d H:i:s.u'), new DateTimeZone('UTC'));
    }
    public static function from_iso8601($iso8601Timestamp): \Aws\Api\Date_Time_Result
    {
        if (is_numeric($iso8601Timestamp) || !is_string($iso8601Timestamp)) {
            throw new Parser_Exception('Invalid timestamp value passed to DateTimeResult::fromISO8601');
        }
        // Prior to 8.0.10, nanosecond precision is not supported
        // Reduces to microsecond precision if nanosecond precision is detected
        if (PHP_VERSION_ID < 80010 && preg_match(self::ISO8601_NANOSECOND_REGEX, $iso8601Timestamp, $matches)) {
            $iso8601Timestamp = $matches[1] . ($matches[3] ?? '');
        }
        return new Date_Time_Result($iso8601Timestamp);
    }
    /**
     * Create a new DateTimeResult from an unknown timestamp.
     *
     * @return DateTimeResult
     * @throws Exception
     */
    public static function from_timestamp($timestamp, $expected_format = null)
    {
        if (empty($timestamp)) {
            return self::from_epoch(0);
        }
        if (!(is_string($timestamp) || is_numeric($timestamp))) {
            throw new Parser_Exception('Invalid timestamp value passed to DateTimeResult::fromTimestamp');
        }
        try {
            if ($expected_format == 'iso8601') {
                try {
                    return self::from_iso8601($timestamp);
                } catch (Exception) {
                    return self::from_epoch($timestamp);
                }
            } elseif ($expected_format == 'unixTimestamp') {
                try {
                    return self::from_epoch($timestamp);
                } catch (Exception) {
                    return self::from_iso8601($timestamp);
                }
            } elseif (\Aws\is_valid_epoch($timestamp)) {
                return self::from_epoch($timestamp);
            }
            return self::from_iso8601($timestamp);
        } catch (Exception) {
            throw new Parser_Exception('Invalid timestamp value passed to DateTimeResult::fromTimestamp');
        }
    }
    /**
     * Serialize the DateTimeResult as an ISO 8601 date string.
     */
    public function __toString(): string
    {
        return $this->format('c');
    }
    /**
     * Serialize the date as an ISO 8601 date when serializing as JSON.
     *
     * @return string
     */
    #[\Return_Type_Will_Change]
    public function jsonSerialize()
    {
        return (string) $this;
    }
}