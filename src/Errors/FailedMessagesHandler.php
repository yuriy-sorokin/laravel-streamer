<?php

namespace Prwnr\Streamer\Errors;

use Exception;
use Prwnr\Streamer\Contracts\Errors\MessagesFailer;
use Prwnr\Streamer\Contracts\Errors\Repository;
use Prwnr\Streamer\Contracts\MessageReceiver;
use Prwnr\Streamer\EventDispatcher\ReceivedMessage;
use Prwnr\Streamer\Exceptions\MessageRetryFailedException;
use Prwnr\Streamer\Stream\Range;
use Throwable;

class FailedMessagesHandler implements MessagesFailer
{
    /**
     * @var Repository
     */
    private $repository;

    /**
     * MessagesErrorHandler constructor.
     *
     * @param  Repository  $repository
     */
    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @inheritDoc
     */
    public function store(ReceivedMessage $message, MessageReceiver $receiver, Exception $e): void
    {
        $this->repository->add(new FailedMessage(...[
            $message->getId(),
            $message->getContent()['name'] ?? '',
            get_class($receiver),
            $e->getMessage(),
        ]));
    }

    /**
     * @inheritDoc
     * @throws MessageRetryFailedException
     * @throws Throwable
     */
    public function retry(FailedMessage $message): void
    {
        $listener = $this->makeReceiver($message);

        $range = new Range($message->getId(), $message->getId());
        $messages = $message->getStream()->readRange($range, 1);
        if (!$messages || count($messages) !== 1) {
            throw new MessageRetryFailedException($message, 'No matching messages found on a Stream to retry');
        }

        $streamMessage = array_pop($messages);
        $receivedMessage = null;
        try {
            $receivedMessage = new ReceivedMessage($message->getId(), $streamMessage);
            $listener->handle($receivedMessage);
        } catch (Throwable $e) {
            if (!$receivedMessage) {
                throw $e;
            }

            $this->store($receivedMessage, $listener, $e);

            throw new MessageRetryFailedException($message, $e->getMessage());
        } finally {
            $this->repository->remove($message);
        }
    }

    /**
     * @param  FailedMessage  $message
     * @return MessageReceiver
     * @throws MessageRetryFailedException
     */
    private function makeReceiver(FailedMessage $message): MessageReceiver
    {
        if (!class_exists($message->getReceiver())) {
            throw new MessageRetryFailedException($message, 'Receiver class does not exists');
        }

        $listener = app($message->getReceiver());
        if (!$listener instanceof MessageReceiver) {
            throw new MessageRetryFailedException($message,
                'Receiver class is not an instance of MessageReceiver contract');
        }

        return $listener;
    }
}