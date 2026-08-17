<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a lifecycle move is not permitted by the transition map.
 *
 * Surfaces as HTTP 409 Conflict: the request was well-formed, but the record is
 * not in a state where the change makes sense.
 */
class InvalidTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $allowed = [],
    ) {
        $message = $allowed === []
            ? sprintf('"%s" is a final status; no further transitions are allowed.', $this->humanise($from))
            : sprintf(
                'Cannot move from "%s" to "%s". Allowed next steps: %s.',
                $this->humanise($from),
                $this->humanise($to),
                implode(', ', array_map($this->humanise(...), $allowed))
            );

        parent::__construct($message, 409);
    }

    private function humanise(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}
