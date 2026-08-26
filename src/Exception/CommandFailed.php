<?php

declare(strict_types=1);

namespace Pam\Browser\Exception;

final class CommandFailed extends ProtocolException
{
    public function __construct(
        public readonly int $commandId,
        public readonly int $protocolCode,
        public readonly string $protocolMessage,
    ) {
        parent::__construct(sprintf(
            'CDP command %d failed with error %d: %s',
            $commandId,
            $protocolCode,
            $protocolMessage,
        ));
    }
}
