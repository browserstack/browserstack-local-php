<?php
// An example of using php-browserstacklocal

namespace BrowserStack;

use BrowserStack\Local;
use BrowserStack\LocalException;

require_once('vendor/autoload.php');

$me = new Local();
$me->is_running();

$args = array(
        "v" => 1);

$me->start($args);
sleep(45);
echo $me->is_running();
#$me->stop();
echo $me->is_running();
