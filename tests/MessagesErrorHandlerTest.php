<?php

namespace Tests;

    use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Testing\Concerns\InteractsWithRedis;
use Prwnr\Streamer\Concerns\ConnectsWithRedis;
use Prwnr\Streamer\Errors\FailedMessage;
use Prwnr\Streamer\Errors\MessagesErrorHandler;
use Prwnr\Streamer\Errors\MessagesRepository;
use Prwnr\Streamer\EventDispatcher\ReceivedMessage;
use Tests\Stubs\LocalListener;

class MessagesErrorHandlerTest extends TestCase
{
    use InteractsWithRedis;
    use ConnectsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRedis();
        $this->redis['phpredis']->connection()->flushall();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->redis['phpredis']->connection()->flushall();
        $this->tearDownRedis();
    }

    public function test_stores_failed_message_information(): void
    {
        Carbon::setTestNow('2021-12-12 12:12:12');
        /** @var MessagesErrorHandler $handler */
        $handler = $this->app->make(MessagesErrorHandler::class);
        $message = new ReceivedMessage('123', [
            'name' => 'foo.bar',
            'data' => json_encode('payload')
        ]);
        $listener = new LocalListener();
        $e = new Exception('error');
        $handler->handle($message, $listener, $e);
        $failed = $this->redis()->sMembers(MessagesRepository::ERRORS_SET);

        $this->assertNotEmpty($failed);
        $this->assertCount(1, $failed);

        $actual = json_decode($failed[0], true);
        $this->assertEquals([
            'id' => $message->getId(),
            'stream' => 'foo.bar',
            'receiver' => LocalListener::class,
            'error' => 'error',
            'date' => '2021-12-12 12:12:12'
        ], $actual);

        Carbon::setTestNow();
    }

    public function test_retries_failed_message(): void
    {
        $message = $this->failFakeMessage('foo.bar', '123', ['payload' => 123]);

        $listener = $this->mock(LocalListener::class);
        $listener->shouldReceive('handle')
            ->withArgs(static function ($arg) use ($message) {
                return $arg instanceof ReceivedMessage
                    && $arg->getId() && $message->getId()
                    && $arg->getContent() && $message->getContent();
            })
            ->once()
            ->andReturn();

        /** @var MessagesErrorHandler $handler */
        $handler = $this->app->make(MessagesErrorHandler::class);
        $handler->retry(new FailedMessage('123', 'foo.bar', LocalListener::class, 'error'));
    }

    public function test_retries_multiple_failed_messages(): void
    {
        $firstMessage = $this->failFakeMessage('foo.bar', '123', ['payload' => 123]);
        $secondMessage = $this->failFakeMessage('foo.bar', '345', ['payload' => 'foobar']);

        $this->assertEquals(2, $this->redis()->sCard(MessagesRepository::ERRORS_SET));

        $listener = $this->mock(LocalListener::class);
        $listener->shouldReceive('handle')
            ->withArgs(static function ($arg) use ($firstMessage) {
                return $arg instanceof ReceivedMessage
                    && $arg->getId() && $firstMessage->getId()
                    && $arg->getContent() && $firstMessage->getContent();
            })
            ->once()
            ->andReturn();

        $listener->shouldReceive('handle')
            ->withArgs(static function ($arg) use ($secondMessage) {
                return $arg instanceof ReceivedMessage
                    && $arg->getId() && $secondMessage->getId()
                    && $arg->getContent() && $secondMessage->getContent();
            })
            ->once()
            ->andReturn();

        /** @var MessagesErrorHandler $handler */
        $handler = $this->app->make(MessagesErrorHandler::class);
        $handler->retryAll();

        $this->assertEquals(0, $this->redis()->sCard(MessagesRepository::ERRORS_SET));
    }

    public function test_wont_retry_message_when_receiver_doest_not_exists(): void
    {
        $listener = $this->mock(LocalListener::class);
        $listener->shouldNotHaveBeenCalled();

        /** @var MessagesErrorHandler $handler */
        $handler = $this->app->make(MessagesErrorHandler::class);
        $handler->retry(new FailedMessage('123', 'foo.bar', 'not a class', 'error'));
    }

    public function test_wont_retry_message_when_it_doest_not_exists(): void
    {
        $listener = $this->mock(LocalListener::class);
        $listener->shouldNotHaveBeenCalled();

        /** @var MessagesErrorHandler $handler */
        $handler = $this->app->make(MessagesErrorHandler::class);
        $handler->retry(new FailedMessage('123', 'foo.bar', LocalListener::class, 'error'));
    }

    public function test_handles_failed_message_and_puts_it_back_when_it_fails_again(): void
    {
        $message = $this->failFakeMessage('foo.bar', '123', ['payload' => 123]);

        $listener = $this->mock(LocalListener::class);
        $listener->shouldReceive('handle')
            ->withArgs(static function ($arg) use ($message) {
                return $arg instanceof ReceivedMessage
                    && $arg->getId() && $message->getId()
                    && $arg->getContent() && $message->getContent();
            })
            ->once()
            ->andThrow(Exception::class);

        /** @var MessagesErrorHandler $handler */
        $handler = $this->app->make(MessagesErrorHandler::class);
        $handler->retryAll();

        $this->assertEquals(1, $this->redis()->sCard(MessagesRepository::ERRORS_SET));
    }
}