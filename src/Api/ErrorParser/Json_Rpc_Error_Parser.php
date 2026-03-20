<?php

declare (strict_types=1);
namespace Aws\Api\Error_Parser;

use Aws\Api\Parser\Abstract_Parser;
use Aws\Api\Parser\Json_Parser;
use Aws\Api\Service;
use Aws\Command_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * Parsers JSON-RPC errors.
 */
class Json_Rpc_Error_Parser extends Abstract_Error_Parser
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
        // Make the casing consistent across services.
        if ($data['parsed']) {
            $data['parsed'] = array_change_key_case($data['parsed']);
        }
        if (isset($data['parsed']['__type'])) {
            if (!isset($data['code'])) {
                $parts = explode('#', $data['parsed']['__type']);
                $data['code'] = $parts[1] ?? $parts[0];
            }
            $data['message'] = $data['parsed']['message'] ?? null;
        }
        $this->populate_shape($data, $response, $command);
        return $data;
    }
}