<?php

declare (strict_types=1);
namespace Aws\Api\Error_Parser;

use Aws\Api\Parser\Abstract_Parser;
use Aws\Api\Parser\Json_Parser;
use Aws\Api\Service;
use Aws\Command_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * Parses JSON-REST errors.
 */
class Rest_Json_Error_Parser extends Abstract_Error_Parser
{
    use Json_Parser_Trait;
    private \Aws\Api\Parser\Json_Parser $parser;
    public function __construct(?Service $api = null, ?Json_Parser $parser = null)
    {
        parent::__construct($api);
        $this->parser = $parser ?: new Json_Parser();
    }
    /**
     * @return mixed[]
     */
    public function __invoke(Response_Interface $response, ?Command_Interface $command = null): array
    {
        $response = Abstract_Parser::get_response_with_caching_stream($response);
        $data = $this->generic_handler($response);
        // Merge in error data from the JSON body
        if ($json = $data['parsed']) {
            $data = array_replace($json, $data);
        }
        // Correct error type from services like Amazon Glacier
        if (!empty($data['type'])) {
            $data['type'] = strtolower((string) $data['type']);
        }
        // Retrieve error message directly
        $data['message'] = $data['parsed']['message'] ?? $data['parsed']['Message'] ?? $data['parsed']['error_description'] ?? null;
        $this->populate_shape($data, $response, $command);
        return $data;
    }
}