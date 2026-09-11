<?php

namespace RaceLab\LaravelRaceLab\Results;

final readonly class ThrowableSnapshot
{
    public function __construct(
        public string $class,
        public string $message,
        public int|string $code,
        public string $file,
        public int $line,
        public string $trace,
    ) {
    }

    public static function fromThrowable(\Throwable $e): self
    {
        return new self(
            class: $e::class,
            message: $e->getMessage(),
            code: $e->getCode(),
            file: $e->getFile(),
            line: $e->getLine(),
            trace: $e->getTraceAsString(),
        );
    }

    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'message' => $this->message,
            'code' => $this->code,
            'file' => $this->file,
            'line' => $this->line,
            'trace' => $this->trace,
        ];
    }

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) return null;
        return new self(
            class: (string) ($data['class'] ?? \RuntimeException::class),
            message: (string) ($data['message'] ?? ''),
            code: $data['code'] ?? 0,
            file: (string) ($data['file'] ?? ''),
            line: (int) ($data['line'] ?? 0),
            trace: (string) ($data['trace'] ?? ''),
        );
    }
}
