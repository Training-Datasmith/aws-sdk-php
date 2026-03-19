<?php

declare(strict_types=1);

namespace Aws\Api\Parser;

use Aws\Api\Parser\Exception\ParserException;
use Aws\Api\StructureShape;
use Aws\Exception\EventStreamDataException;
use Iterator;
use Psr\Http\Message\StreamInterface;

/**
 * @internal Implements a decoder for a binary encoded event stream that will
 * decode, validate, and provide individual events from the stream.
 */
class EventParsingIterator implements Iterator
{
    /** @var StreamInterface */
    private $decodingIterator;

    /** @var AbstractParser */
    private $parser;

    public function __construct(
        StreamInterface $stream,
        private readonly StructureShape $shape,
        AbstractParser $parser
    ) {
        $this->decodingIterator = $this->chooseDecodingIterator($stream);
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
    private function chooseDecodingIterator($stream): \Aws\Api\Parser\DecodingEventStreamIterator|\Aws\Api\Parser\NonSeekableStreamDecodingEventStreamIterator
    {
        if ($stream->isSeekable()) {
            return new DecodingEventStreamIterator($stream);
        }
        return new NonSeekableStreamDecodingEventStreamIterator($stream);
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        return $this->parseEvent($this->decodingIterator->current());
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function key()
    {
        return $this->decodingIterator->key();
    }

    #[\ReturnTypeWillChange]
    public function next(): void
    {
        $this->decodingIterator->next();
    }

    #[\ReturnTypeWillChange]
    public function rewind(): void
    {
        $this->decodingIterator->rewind();
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function valid()
    {
        return $this->decodingIterator->valid();
    }

    private function parseEvent(array $event)
    {
        if (!empty($event['headers'][':message-type'])) {
            if ($event['headers'][':message-type'] === 'error') {
                return $this->parseError($event);
            }

            if ($event['headers'][':message-type'] === 'exception') {
                return $this->parseException($event);
            }

            if ($event['headers'][':message-type'] !== 'event') {
                throw new ParserException('Failed to parse unknown message type.');
            }
        }

        $eventType = $event['headers'][':event-type'] ?? null;
        if (empty($eventType)) {
            throw new ParserException('Failed to parse without event type.');
        }

        $eventPayload = $event['payload'];
        if ($eventType === 'initial-response') {
            return $this->parseInitialResponseEvent($eventPayload);
        }

        $eventShape = $this->shape->getMember($eventType);

        return [
            $eventType => array_merge(
                $this->parseEventHeaders($event['headers'], $eventShape),
                $this->parseEventPayload($eventPayload, $eventShape)
            ),
        ];
    }

    /**
     * @param $headers
     * @param $eventShape
     */
    private function parseEventHeaders(array $headers, $eventShape): array
    {
        $parsedHeaders = [];
        foreach ($eventShape->getMembers() as $memberName => $memberProps) {
            if (isset($memberProps['eventheader'])) {
                $parsedHeaders[$memberName] = $headers[$memberName];
            }
        }

        return $parsedHeaders;
    }

    /**
     * @param $payload
     * @param $eventShape
     */
    private function parseEventPayload($payload, \Aws\Api\StructureShape $eventShape): array
    {
        $parsedPayload = [];
        foreach ($eventShape->getMembers() as $memberName => $memberProps) {
            $memberShape = $eventShape->getMember($memberName);
            if (isset($memberProps['eventpayload'])) {
                if ($memberShape->getType() === 'blob') {
                    $parsedPayload[$memberName] = $payload;
                } else {
                    $parsedPayload[$memberName] = $this->parser->parseMemberFromStream(
                        $payload,
                        $memberShape,
                        null
                    );
                }

                break;
            }
        }

        if (empty($parsedPayload) && !empty($payload->getContents())) {
            /**
             * If we did not find a member with an eventpayload trait, then we should deserialize the payload
             * using the event's shape.
             */
            return $this->parser->parseMemberFromStream($payload, $eventShape, null);
        }

        return $parsedPayload;
    }

    private function parseError(array $event): never
    {
        throw new EventStreamDataException(
            $event['headers'][':error-code'],
            $event['headers'][':error-message']
        );
    }

    private function parseException(array $event): never
    {
        $payload = $event['payload']?->getContents();
        $parsedPayload = json_decode((string) $payload, true);

        throw new EventStreamDataException(
            $event['headers'][':exception-type'] ?? 'Unknown',
            $parsedPayload['message'] ?? $payload,
        );
    }

    private function parseInitialResponseEvent($payload): array
    {
        return ['initial-response' => json_decode((string) $payload, true)];
    }
}
