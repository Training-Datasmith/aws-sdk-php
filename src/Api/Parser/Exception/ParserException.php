<?php

declare (strict_types=1);
namespace Aws\Api\Parser\Exception;

use Aws\Has_Monitoring_Events_Trait;
use Aws\Monitoring_Events_Interface;
use Aws\Response_Container_Interface;
use Psr\Http\Message\Response_Interface;
class Parser_Exception extends \RuntimeException implements Monitoring_Events_Interface, Response_Container_Interface
{
    use Has_Monitoring_Events_Trait;
    private $error_code;
    private $request_id;
    private $response;
    public function __construct($message = '', $code = 0, $previous = null, array $context = [])
    {
        $this->error_code = $context['error_code'] ?? null;
        $this->request_id = $context['request_id'] ?? null;
        $this->response = $context['response'] ?? null;
        parent::__construct($message, $code, $previous);
    }
    /**
     * Get the error code, if any.
     *
     * @return string|null
     */
    public function get_error_code()
    {
        return $this->error_code;
    }
    /**
     * Get the request ID, if any.
     *
     * @return string|null
     */
    public function get_request_id()
    {
        return $this->request_id;
    }
    /**
     * Get the received HTTP response if any.
     *
     * @return ResponseInterface|null
     */
    public function get_response()
    {
        return $this->response;
    }
}