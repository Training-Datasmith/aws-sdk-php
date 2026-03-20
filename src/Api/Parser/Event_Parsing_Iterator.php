<?php

declare (strict_types=1);
namespace Aws\Api\Parser;

use Aws\Api\Parser\Exception\Parser_Exception;
use Aws\Api\Structure_Shape;
use Aws\Exception\Event_Stream_Data_Exception;
use Iterator;
use Psr\Http\Message\Stream_Interface;
/**
 * @internal Implements a decoder for a binary encoded event stream that will
 * decode, validate, and provide individual events from the stream.
 */
class Event_Parsing_Iterator implements Iterator
{
    /** @var StreamInterface */
    private $decoding_iterator;
    /** @var AbstractParser */
    private $parser;
    public function __construct(Stream_Interface $stream, private readonly Structure_Shape $shape, Abstract_Parser $parser)
    {
        $this->decoding_iterator = $this->choose_decoding_iterator($stream);
        $this->parser = $parser;
    }
    /**
     * This method choose a decoding iterator implementation based on if the stream
     * is seekable or not.
     *
     * @param $stream
     *
     * @return Iterator
     */
    private function choose_decoding_iterator($stream): \Aws\Api\Parser\Decoding_Event_Stream_Iterator|\Aws\Api\Parser\Non_Seekable_Stream_Decoding_Event_Stream_Iterator
    {
        if ($stream->is_seekable()) {
            return new Decoding_Event_Stream_Iterator($stream);
        }
        return new Non_Seekable_Stream_Decoding_Event_Stream_Iterator($stream);
    }
    /**
     * @return mixed
     */
    #[\Return_Type_Will_Change]
    public function current()
    {
        return $this->parse_event($this->decoding_iterator->current());
    }
    /**
     * @return mixed
     */
    #[\Return_Type_Will_Change]
    public function key()
    {
        return $this->decoding_iterator->key();
    }
    #[\Return_Type_Will_Change]
    public function next(): void
    {
        $this->decoding_iterator->next();
    }
    #[\Return_Type_Will_Change]
    public function rewind(): void
    {
        $this->decoding_iterator->rewind();
    }
    /**
     * @return bool
     */
    #[\Return_Type_Will_Change]
    public function valid()
    {
        return $this->decoding_iterator->valid();
    }
    private function parse_event(array $event)
    {
        if (!empty($event['headers'][':message-type'])) {
            if ($event['headers'][':message-type'] === 'error') {
                return $this->parse_error($event);
            }
            if ($event['headers'][':message-type'] === 'exception') {
                return $this->parse_exception($event);
            }
            if ($event['headers'][':message-type'] !== 'event') {
                throw new Parser_Exception('Failed to parse unknown message type.');
            }
        }
        $event_type = $event['headers'][':event-type'] ?? null;
        if (empty($event_type)) {
            throw new Parser_Exception('Failed to parse without event type.');
        }
        $event_payload = $event['payload'];
        if ($event_type === 'initial-response') {
            return $this->parse_initial_response_event($event_payload);
        }
        $event_shape = $this->shape->get_member($event_type);
        return [$event_type => array_merge($this->parse_event_headers($event['headers'], $event_shape), $this->parse_event_payload($event_payload, $event_shape))];
    }
    /**
     * @param $headers
     * @param $eventShape
     */
    private function parse_event_headers(array $headers, $event_shape): array
    {
        $parsed_headers = [];
        foreach ($event_shape->get_members() as $member_name => $member_props) {
            if (isset($member_props['eventheader'])) {
                $parsed_headers[$member_name] = $headers[$member_name];
            }
        }
        return $parsed_headers;
    }
    /**
     * @param $payload
     * @param $eventShape
     */
    private function parse_event_payload($payload, \Aws\Api\Structure_Shape $event_shape): array
    {
        $parsed_payload = [];
        foreach ($event_shape->get_members() as $member_name => $member_props) {
            $member_shape = $event_shape->get_member($member_name);
            if (isset($member_props['eventpayload'])) {
                if ($member_shape->get_type() === 'blob') {
                    $parsed_payload[$member_name] = $payload;
                } else {
                    $parsed_payload[$member_name] = $this->parser->parse_member_from_stream($payload, $member_shape, null);
                }
                break;
            }
        }
        if (empty($parsed_payload) && !empty($payload->get_contents())) {
            /**
             * If we did not find a member with an eventpayload trait, then we should deserialize the payload
             * using the event's shape.
             */
            return $this->parser->parse_member_from_stream($payload, $event_shape, null);
        }
        return $parsed_payload;
    }
    private function parse_error(array $event): never
    {
        throw new Event_Stream_Data_Exception($event['headers'][':error-code'], $event['headers'][':error-message']);
    }
    private function parse_exception(array $event): never
    {
        $payload = $event['payload']?->get_contents();
        $parsed_payload = json_decode((string) $payload, true);
        throw new Event_Stream_Data_Exception($event['headers'][':exception-type'] ?? 'Unknown', $parsed_payload['message'] ?? $payload);
    }
    private function parse_initial_response_event($payload): array
    {
        return ['initial-response' => json_decode((string) $payload, true)];
    }
}