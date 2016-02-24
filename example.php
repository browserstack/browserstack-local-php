<?php
// An example of using php-browserstacklocal

namespace BrowserStack;

require_once('vendor/autoload.php');

use BrowserStack\Local;
use BrowserStack\LocalException;

$me = new Local();
$me->isRunning();
$args = array("v" => 1);
$me->start($args);
echo $me->isRunning();
$me->stop();
echo $me->isRunning();
