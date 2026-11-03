<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator;

final class SvrlParser
{
    /** @return list<Finding> */
    public function parse(string $xml, string $stage, array $severityOverrides = []): array
    {
        $document = Xml::parse($xml);
        if ($document->documentElement->namespaceURI !== 'http://purl.oclc.org/dsdl/svrl'
            || $document->documentElement->localName !== 'schematron-output') {
            throw new \RuntimeException('Transformation did not return an SVRL schematron-output document');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('svrl', 'http://purl.oclc.org/dsdl/svrl');
        if ($xpath->query('//svrl:fired-rule')->length === 0) {
            throw new \RuntimeException('SVRL contains no executed rules; validation coverage cannot be established');
        }
        $findings = [];
        foreach ($xpath->query('//svrl:failed-assert | //svrl:successful-report') as $node) {
            $flag = strtolower(trim($node->getAttribute('flag') ?: $node->getAttribute('role')));
            // Unspecified and unrecognised failed assertions fail closed. Successful reports are informational by default.
            $severity = match ($flag) {
                'warning', 'warn' => 'warning',
                'information', 'info', 'notice' => 'info',
                'fatal', 'error' => 'error',
                default => $node->localName === 'failed-assert' ? 'error' : 'info',
            };
            $texts = [];
            foreach ($xpath->query('svrl:text | svrl:diagnostic-reference', $node) as $text) {
                $value = preg_replace('/\s+/u', ' ', trim($text->textContent));
                if ($value !== '') $texts[] = $value;
            }
            $override = $severityOverrides[$node->getAttribute('id')] ?? null;
            if ($override !== null) {
                $severity = match ($override) { 'information' => 'info', 'error', 'warning', 'info' => $override,
                    default => throw new \RuntimeException('Unknown configured severity override') };
            }
            $findings[] = new Finding($severity, $node->getAttribute('id') ?: 'SCHEMATRON',
                implode(' ', $texts), $stage, $node->getAttribute('location') ?: null,
                $node->getAttribute('test') ?: null);
        }
        return $findings;
    }
}
