<?php
// An example of using php-browserstacklocal

namespace BrowserStack;

use BrowserStack\Local;
use BrowserStack\LocalException;

require_once('vendor/autoload.php');

$me = new Local();
$args = array(
        "v" => 1);
$me->is_running();

$me->start($args);
echo $me->is_running();
sleep(50);
#$me->stop();
echo $me->is_running();
