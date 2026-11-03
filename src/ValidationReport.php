<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator;

final readonly class ValidationReport implements \JsonSerializable
{
    /** @param list<Finding> $findings @param list<array<string,mixed>> $stages */
    public function __construct(
        public string $status,
        public string $inputSha256,
        public ?string $syntax,
        public ?string $profile,
        public ?array $ruleSet,
        public array $findings,
        public array $stages,
        public string $scope = 'xml-invoice',
        public ?string $embeddedXmlSha256 = null,
        public array $svrlReports = [],
    ) {
        if (!in_array($status, ['valid', 'invalid', 'unsupported', 'error'], true)) {
            throw new \InvalidArgumentException('Unknown validation status');
        }
    }
    public function isValid(): bool { return $this->status === 'valid'; }
    public function jsonSerialize(): array { return ['reportVersion' => 1] + get_object_vars($this); }
    public function toJson(): string {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
