<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A Meta Marketing API failure that has already been translated into plain
 * English for the wizard, while keeping the raw Graph message for support.
 */
class MetaPublishException extends RuntimeException
{
    public ?int $publishLogId = null;

    /**
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public readonly string $step,
        public readonly string $userTitle,
        public readonly string $userMessage,
        public readonly string $rawMessage,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorSubcode = null,
        public readonly ?string $errorType = null,
        public readonly ?array $response = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    public function traceSummary(): string
    {
        $parts = ["step={$this->step}"];

        if ($this->errorCode) {
            $parts[] = "code={$this->errorCode}";
        }

        if ($this->errorSubcode) {
            $parts[] = "subcode={$this->errorSubcode}";
        }

        if ($this->errorType) {
            $parts[] = "type={$this->errorType}";
        }

        $parts[] = 'message='.$this->rawMessage;

        return implode(' | ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => false,
            'step' => $this->step,
            'title' => $this->userTitle,
            'message' => $this->userMessage,
            'raw' => $this->rawMessage,
            'code' => $this->errorCode,
            'subcode' => $this->errorSubcode,
            'type' => $this->errorType,
            'log_id' => $this->publishLogId,
        ];
    }
}
