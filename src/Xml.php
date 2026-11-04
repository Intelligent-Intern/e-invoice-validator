<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator;

final class Xml
{
    public static function parse(string $xml): \DOMDocument
    {
        // Reject prohibited encodings and declarations before entering libxml.
        // A post-parse doctype check is too late for native DTD parser defects.
        if (str_contains($xml, "\0") || preg_match('//u', $xml) !== 1) {
            throw new \InvalidArgumentException('XML must be UTF-8 without NUL bytes');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw new \InvalidArgumentException('DTD and entity declarations are prohibited');
        }
        $previous = libxml_use_internal_errors(true);
        $previousLoader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static fn () => null);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            try {
                $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            } catch (\ValueError $exception) {
                // DOM treats an empty source as an argument error; for invoice validation it is malformed input.
                throw new \InvalidArgumentException('Malformed XML: ' . $exception->getMessage(), 0, $exception);
            }
            if (!$loaded) {
                $errors = libxml_get_errors();
                throw new \InvalidArgumentException('Malformed XML: ' . trim($errors[0]->message ?? 'parsing failed'));
            }
            if ($document->doctype !== null) {
                throw new \InvalidArgumentException('DTD and entity declarations are prohibited');
            }
            if ($document->documentElement === null) {
                throw new \InvalidArgumentException('XML has no document element');
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            libxml_set_external_entity_loader($previousLoader);
        }
    }

    /** @return array{string, string} */
    public static function identify(\DOMDocument $document): array
    {
        $root = $document->documentElement;
        $syntax = match ([$root->namespaceURI, $root->localName]) {
            ['urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice'] => 'ubl-invoice',
            ['urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2', 'CreditNote'] => 'ubl-creditnote',
            ['urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100', 'CrossIndustryInvoice'] => 'cii',
            default => 'unknown',
        };
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $expression = $syntax === 'cii'
            ? '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'
            : '/*/cbc:CustomizationID';
        $nodes = $xpath->query($expression);
        if ($nodes === false || $nodes->length !== 1) {
            return [$syntax, ''];
        }
        return [$syntax, trim($nodes->item(0)->textContent)];
    }
}
