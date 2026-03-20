<?php

declare (strict_types=1);
namespace Aws\Api\Error_Parser;

use Aws\Api\Parser\Metadata_Parser_Trait;
use Aws\Api\Parser\Payload_Parser_Trait;
use Aws\Api\Service;
use Aws\Api\Structure_Shape;
use Aws\Command_Interface;
use Psr\Http\Message\Response_Interface;
abstract class Abstract_Error_Parser
{
    use Metadata_Parser_Trait;
    use Payload_Parser_Trait;
    /**
     * @param Service $api
     */
    public function __construct(protected ?\Aws\Api\Service $api = null)
    {
    }
    abstract protected function payload(Response_Interface $response, Structure_Shape $member);
    protected function populate_shape(array &$data, Response_Interface $response, ?Command_Interface $command = null)
    {
        $data['body'] = [];
        if ($command instanceof \Aws\Command_Interface && !empty($this->api)) {
            // If modeled error code is indicated, check for known error shape
            if (!empty($data['code'])) {
                $errors = $this->api->get_operation($command->get_name())->get_errors();
                foreach ($errors as $error) {
                    // If error code matches a known error shape, populate the body
                    if ($this->error_code_matches($data, $error)) {
                        $data['body'] = $this->payload($response, $error);
                        $data['error_shape'] = $error;
                        foreach ($error->get_members() as $name => $member) {
                            switch ($member['location']) {
                                case 'header':
                                    $this->extract_header($name, $member, $response, $data['body']);
                                    break;
                                case 'headers':
                                    $this->extract_headers($name, $member, $response, $data['body']);
                                    break;
                                case 'statusCode':
                                    $this->extract_status($name, $response, $data['body']);
                                    break;
                            }
                        }
                        break;
                    }
                }
            }
        }
        return $data;
    }
    private function error_code_matches(array $data, array $error): bool
    {
        return $data['code'] == $error['name'] || isset($error['error']['code']) && $data['code'] === $error['error']['code'];
    }
}