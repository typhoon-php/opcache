<?php

declare(strict_types=1);

namespace Typhoon\OPcache;

final class NonUnserializable
{
    public function __unserialize(array $_data): void
    {
        trigger_error('I cannot unserialize!', E_USER_ERROR);
    }
}
