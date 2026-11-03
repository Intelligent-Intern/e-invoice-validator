<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator\Engine;

final class KingEngine implements TransformEngine
{
    public function status(): array
    {
        if (!class_exists('King\\XSLT\\Processor')) {
            return ['available' => false, 'engine' => 'king-saxonc', 'error' => 'King XSLT extension is not installed or this build lacks XSLT support'];
        }
        try { return (new \King\XSLT\Processor())->engineStatus(); }
        catch (\Throwable $exception) { return ['available' => false, 'engine' => 'king-saxonc', 'error' => $exception->getMessage()]; }
    }
    public function transform(string $xmlPath, string $stylesheetPath): string
    {
        $status = $this->status();
        if (!($status['available'] ?? false)) throw new \RuntimeException($status['error'] ?? 'King/SaxonC unavailable');
        $processor = new \King\XSLT\Processor();
        $result = $processor->transformFile($xmlPath, $stylesheetPath);
        if (!($result['ok'] ?? false) || !is_string($result['result'] ?? null)) {
            throw new \RuntimeException($result['error'] ?? 'King transformation failed');
        }
        return $result['result'];
    }
}
