<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator;

final readonly class Finding implements \JsonSerializable
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public string $stage,
        public ?string $location = null,
        public ?string $test = null,
    ) {
        if (!in_array($severity, ['error', 'warning', 'info'], true)) {
            throw new \InvalidArgumentException('Unknown finding severity');
        }
    }
    public function jsonSerialize(): array { return get_object_vars($this); }
}
