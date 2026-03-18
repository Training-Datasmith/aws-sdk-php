<?php
namespace Aws\Exception;

/**
 * Represents an exception that was supplied via an EventStream.
 */
class EventStreamDataException extends \RuntimeException
{
    public function __construct(private $errorCode, private $errorMessage)
    {
        parent::__construct($this->errorMessage);
    }

    /**
     * Get the AWS error code.
     *
     * @return string|null Returns null if no response was received
     */
    public function getAwsErrorCode()
    {
        return $this->errorCode;
    }

    /**
     * Get the concise error message if any.
     *
     * @return string|null
     */
    public function getAwsErrorMessage()
    {
        return $this->errorMessage;
    }
}
