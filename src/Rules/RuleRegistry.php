<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator\Rules;
use IntelligentIntern\EInvoiceValidator\Xml;

final class RuleRegistry
{
    private string $root;
    public function __construct(string $root) {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) throw new \InvalidArgumentException('Rules directory not found');
        $this->root = $real;
    }
    public function select(string $syntax, string $guideline, ?string $requested = null): ?RuleSet
    {
        $raw = file_get_contents($this->root . '/manifest.json');
        if ($raw === false) throw new \RuntimeException('Rule manifest missing');
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? null) !== 1) throw new \RuntimeException('Unsupported rule manifest version');
        foreach ($manifest['packages'] ?? [] as $package) {
            foreach ($package['profiles'] ?? [] as $profile) {
                if (($profile['syntax'] ?? null) !== $syntax || !in_array($guideline, $profile['guidelineIds'] ?? [], true)
                    || ($requested !== null && $requested !== $profile['id'])) continue;
                $set = new RuleSet($package, $profile, $this->root, hash('sha256', $raw));
                $this->verify($set);
                return $set;
            }
        }
        return null;
    }
    private function verify(RuleSet $set): void
    {
        if (empty($set->package['files']) || empty($set->profile['schema']) || empty($set->profile['schematron'])) {
            throw new \RuntimeException('Incomplete validation rule package');
        }
        foreach ($set->package['files'] as $relative => $expected) {
            if (!is_string($relative) || str_contains($relative, chr(92)) || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) throw new \RuntimeException('Unsafe resource path');
            $path = $set->path($relative);
            if (!is_file($path) || !is_readable($path) || !hash_equals($expected, hash_file('sha256', $path))) {
                throw new \RuntimeException('Rule resource hash mismatch: ' . $relative);
            }
            if (preg_match('/\.(?:xsd|xslt|xsl)$/i', $path)) $this->auditReferences($set, $path);
        }
        $set->path($set->profile['schema']);
        foreach ($set->profile['schematron'] as $file) $set->path($file);
    }
    private function auditReferences(RuleSet $set, string $path): void
    {
        // UBL's pinned XML-signature XSD has an internal-only DTD. Unlike invoice input,
        // audited package files may contain that declaration, never external identifiers.
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $doc->resolveExternals = false; $doc->substituteEntities = false;
            if (!$doc->loadXML(file_get_contents($path), LIBXML_NONET)) throw new \RuntimeException('Malformed rule resource');
            if ($doc->doctype !== null) {
                if (!empty($doc->doctype->systemId) || !empty($doc->doctype->publicId)) throw new \RuntimeException('External rule DTD prohibited');
                foreach ($doc->doctype->entities as $entity) {
                    if (!empty($entity->systemId) || !empty($entity->publicId)) throw new \RuntimeException('External rule entity prohibited');
                }
            }
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $xp->registerNamespace('xsl', 'http://www.w3.org/1999/XSL/Transform');
        if ($xp->query('//xsl:result-document | //xsl:evaluate')->length > 0) throw new \RuntimeException('Rule may write files or evaluate dynamic code');
        // Static document() lookups (Factur-X code lists) are executable rule dependencies too.
        foreach ($xp->query('//xsl:*/@*') as $attribute) {
            if (preg_match_all('/\\b(?:doc|document|unparsed-text|unparsed-text-lines|collection)\\s*\\(\\s*([\'"])(.*?)\\1/s', $attribute->value, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) $this->auditLocalReference($set, $path, $match[2]);
            }
        }
        foreach ($xp->query('//xs:include/@schemaLocation | //xs:import/@schemaLocation | //xs:redefine/@schemaLocation | //xsl:include/@href | //xsl:import/@href') as $ref) {
            $this->auditLocalReference($set, $path, $ref->value);
        }
    }

    private function auditLocalReference(RuleSet $set, string $path, string $value): void
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $value) || str_starts_with($value, '/') || str_contains($value, chr(92))) throw new \RuntimeException('External rule reference prohibited');
        $resolved = $value === '' ? $path : realpath(dirname($path) . '/' . $value);
        if ($resolved === false || !str_starts_with($resolved, $set->root . DIRECTORY_SEPARATOR)) throw new \RuntimeException('Missing or unsafe rule dependency: ' . $value);
        $relative = substr($resolved, strlen($set->root) + 1);
        if (!isset($set->package['files'][$relative])) throw new \RuntimeException('Unpinned rule dependency: ' . $relative);
    }
}
