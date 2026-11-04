<?php
declare(strict_types=1);
use IntelligentIntern\EInvoiceValidator\RuntimeRequirements;
foreach (['8.2.34','8.3.35','8.4.26','8.5.11','8.4.19','8.1.99','8.6.0','8.4.27RC1','invalid'] as $version) {
    check(!RuntimeRequirements::supports($version),'reject unpatched/unreviewed PHP '.$version);
}
foreach (['8.2.35','8.3.36','8.4.27','8.5.12','8.2.36','8.3.37','8.4.28','8.5.13','8.4.27+vendor.1'] as $version) {
    check(RuntimeRequirements::supports($version),'accept patched PHP '.$version);
}
$composer=json_decode(file_get_contents(dirname(__DIR__).'/composer.json'),true,32,JSON_THROW_ON_ERROR);
check($composer['require']['php']===RuntimeRequirements::CONSTRAINT,'Composer and actual-runtime policy agree');
check(in_array('src/bootstrap.php',$composer['autoload']['files'],true),'Composer activates actual runtime guard');
RuntimeRequirements::assertSupported();
check(RuntimeRequirements::supports(PHP_VERSION),'suite runs on patched PHP');
