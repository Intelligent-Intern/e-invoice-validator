<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator\Rules;

final readonly class RuleSet
{
    public function __construct(public array $package, public array $profile, public string $root, public string $manifestSha256) {}
    public function path(string $relative): string
    {
        if (!isset($this->package['files'][$relative])) throw new \RuntimeException('Rule resource is not in the pinned manifest: ' . $relative);
        $real = realpath($this->root . '/' . $relative);
        if ($real === false || !str_starts_with($real, $this->root . DIRECTORY_SEPARATOR)) throw new \RuntimeException('Rule resource escapes its package');
        return $real;
    }
    public function metadata(): array
    {
        return ['id' => $this->package['id'], 'version' => $this->package['version'],
            'source' => $this->package['source'], 'manifestSha256' => $this->manifestSha256,
            'profile' => $this->profile['id'], 'resources' => $this->package['files']];
    }
}
