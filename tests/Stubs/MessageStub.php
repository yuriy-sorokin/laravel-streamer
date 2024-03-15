<?php

namespace Tests\Stubs;

use Prwnr\Streamer\Contracts\Errors\StreamableMessage;

class MessageStub implements StreamableMessage
{
    public function getContent(): array
    {
        return ['foo' => 'bar'];
    }
}
