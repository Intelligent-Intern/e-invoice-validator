<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator;

/** Version floor for CVE-2026-109618 / CVE-2026-109619; review new PHP branches explicitly. */
final class RuntimeRequirements
{
    public const CONSTRAINT = '~8.2.35 || ~8.3.36 || ~8.4.27 || ~8.5.12';
    private const MINIMUM = ['8.2'=>'8.2.35', '8.3'=>'8.3.36', '8.4'=>'8.4.27', '8.5'=>'8.5.12'];

    public static function supports(string $version): bool
    {
        $version = explode('+', $version, 2)[0]; // Build metadata does not change the upstream patch version.
        if (!preg_match('/^([0-9]+)\.([0-9]+)\./', $version, $parts)) return false;
        $minimum = self::MINIMUM[$parts[1].'.'.$parts[2]] ?? null;
        return $minimum !== null && version_compare($version, $minimum, '>=');
    }

    public static function assertSupported(): void
    {
        if (!self::supports(PHP_VERSION)) {
            throw new \RuntimeException('Unsupported PHP runtime '.PHP_VERSION.'. Security updates required: '.self::CONSTRAINT.'. See SECURITY.md.');
        }
    }
}
