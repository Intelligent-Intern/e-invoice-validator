<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/RuntimeRequirements.php';
use IntelligentIntern\EInvoiceValidator\RuntimeRequirements;

$errors=[];
if (!RuntimeRequirements::supports(PHP_VERSION)) $errors[]='PHP security floor not met: '.RuntimeRequirements::CONSTRAINT;
$progress=ini_get('session.upload_progress.enabled');
if ($progress!==false && filter_var($progress,FILTER_VALIDATE_BOOLEAN)) $errors[]='Set session.upload_progress.enabled=0 in php.ini before request parsing';
echo json_encode(['status'=>$errors?'error':'ok','php'=>PHP_VERSION,'sapi'=>PHP_SAPI,
    'sessionUploadProgressEnabled'=>$progress!==false&&filter_var($progress,FILTER_VALIDATE_BOOLEAN),
    'errors'=>$errors],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
exit($errors?1:0);
