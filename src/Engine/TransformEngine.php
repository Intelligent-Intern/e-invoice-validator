<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator\Engine;

interface TransformEngine
{
    /** @return array<string,mixed> */
    public function status(): array;
    public function transform(string $xmlPath, string $stylesheetPath): string;
}
