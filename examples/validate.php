<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use IntelligentIntern\EInvoiceValidator\Validator;

$report = Validator::fromBundledRules()->validateFile($argv[1] ?? throw new InvalidArgumentException('Provide an invoice XML path'));
echo $report->toJson(), "\n";
exit($report->isValid() ? 0 : 1);
