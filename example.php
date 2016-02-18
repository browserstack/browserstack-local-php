<?php
// An example of using php-browserstacklocal

namespace BrowserStack;

use BrowserStack\Local;
use BrowserStack\LocalException;

require_once('vendor/autoload.php');

$me = new Local();
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
