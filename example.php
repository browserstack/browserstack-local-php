<?php
// An example of using php-browserstacklocal

namespace BrowserStack;

use BrowserStack\BrowserStackLocal;
use BrowserStack\BrowserStackLocalException;

require_once('vendor/autoload.php');

$me = new BrowserStackLocal();
$me->is_running();

$args = array(
    "v" => 1,
    "localIdentifier" => "randomString",
    "onlyAutomate" => 1
);

$me->start($args);
$me->is_running();
$me->stop();
$me->is_running();
