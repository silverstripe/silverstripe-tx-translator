<?php

use SilverStripe\TxTranslator\Translator;
use Symfony\Component\Dotenv\Dotenv;

$vendorDir = dirname(dirname(dirname(__DIR__)));
require "$vendorDir/autoload.php";


$baseDir = dirname($vendorDir);
if (file_exists("$baseDir/.env")) {
    $dotenv = new Dotenv();
    $dotenv->usePutenv(true)->load("$baseDir/.env");
}

$translator = new Translator();
$translator->run();
