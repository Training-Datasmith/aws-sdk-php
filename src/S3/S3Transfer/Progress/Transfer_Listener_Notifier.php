<?php

namespace Aws\S3\S3Transfer\Progress;

final class TransferListenerNotifier extends AbstractTransferListener
{
    /** @var AbstractTransferListener[] */
    private array $listeners;

    public function __construct(array $listeners = [])
    {
        foreach ($listeners as $listener) {
            if (!$listener instanceof AbstractTransferListener) {
                throw new \InvalidArgumentException(
                    "Listener must implement " . AbstractTransferListener::class . "."
                );
            }
        }
        $this->listeners = $listeners;
    }

    public function addListener(AbstractTransferListener $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * @inheritDoc
     */
    public function transferInitiated(array $context): void
    {
        foreach ($this->listeners as $listener) {
            $listener->transferInitiated($context);
        }
    }

    /**
     * @inheritDoc
     */
    public function bytesTransferred(array $context): bool
    {
        foreach ($this->listeners as $listener) {
            $listener->bytesTransferred($context);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function transferComplete(array $context): void
    {
        foreach ($this->listeners as $listener) {
            $listener->transferComplete($context);
        }
    }

    /**
     * @inheritDoc
     */
    public function transferFail(array $context): void
    {
        foreach ($this->listeners as $listener) {
            $listener->transferFail($context);
        }
    }
}
