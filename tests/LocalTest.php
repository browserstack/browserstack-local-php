<?php

namespace BrowserStack;

use BrowserStack\Local;
use BrowserStack\LocalBinary;
use BrowserStack\LocalException;

require_once __DIR__ . '/../vendor/autoload.php';

class LocalTest extends \PHPUnit\Framework\TestCase {

  private $bs_local;

  protected function setUp(): void {
    $this->bs_local = new Local();
  }

  protected function tearDown(): void {
    $this->bs_local->stop();
  }

  public function test_verbose() {
    $this->bs_local->add_args('v');
    $this->assertStringContainsString('-v',$this->bs_local->start_command());
  }

  public function test_set_folder() {
    $this->bs_local->add_args('f', "/");
    $this->assertStringContainsString('-f',$this->bs_local->start_command());
    $this->assertStringContainsString('/',$this->bs_local->start_command());
  }

  public function test_enable_force() {
    $this->bs_local->add_args("force");
  }

  public function test_set_local_identifier() {
    $this->bs_local->add_args("localIdentifier", "randomString");
    $this->assertStringContainsString("-localIdentifier 'randomString'",$this->bs_local->start_command());
  }

  public function test_enable_only() {
    $this->bs_local->add_args("only");
    $this->assertStringContainsString('-only',$this->bs_local->start_command());
  }

  public function test_enable_only_automate() {
    $this->bs_local->add_args("onlyAutomate");
    $this->assertStringContainsString('-onlyAutomate', $this->bs_local->start_command()); 
  }

  public function test_enable_force_local() {
    $this->bs_local->add_args("forcelocal");
    $this->assertStringContainsString('-forcelocal',$this->bs_local->start_command());
  }

  public function test_custom_boolean_argument() {
    $this->bs_local->add_args("boolArg1", true);
    $this->bs_local->add_args("boolArg2", true);
    $this->assertStringContainsString('-boolArg1',$this->bs_local->start_command());
    $this->assertStringContainsString('-boolArg2',$this->bs_local->start_command());
  }

  public function test_custom_keyval() {
    $this->bs_local->add_args("customKey1", "custom value1");
    $this->bs_local->add_args("customKey2", "custom value2");
    $this->assertStringContainsString('-customKey1 \'custom value1\'',$this->bs_local->start_command());
    $this->assertStringContainsString('-customKey2 \'custom value2\'',$this->bs_local->start_command());
  }

  public function test_set_proxy() {
    $this->bs_local->add_args("proxyHost", "localhost");
    $this->bs_local->add_args("proxyPort", 8080);
    $this->bs_local->add_args("proxyUser", "user");
    $this->bs_local->add_args("proxyPass", "pass");
    $this->assertStringContainsString("-proxyHost 'localhost' -proxyPort '8080' -proxyUser 'user' -proxyPass 'pass'",$this->bs_local->start_command());
  }

  public function test_enable_force_proxy() {
    $this->bs_local->add_args("-forceproxy");
    $this->assertStringContainsString('-forceproxy',$this->bs_local->start_command());
  }

  public function test_hosts() {
    $this->bs_local->add_args("-hosts", "localhost,8080,0");
    $this->assertStringContainsString('localhost,8080,0',$this->bs_local->start_command());
  }

  // ---------------------------------------------------------------------------
  // Command-injection regression tests (CWE-78 / CWE-88).
  //
  // These execute the assembled command line for real, but never the
  // BrowserStackLocal binary: binary_path is pointed at /bin/echo, so the only
  // thing that can run besides echo is an injected payload. Each test drops a
  // marker file; the marker existing means the shell executed attacker bytes.
  //
  // The payloads use command substitution rather than a `; cmd` chain on
  // purpose: the command line starts with the `exec` builtin, which replaces
  // the shell, so a trailing chain never gets its turn -- while $(...) is
  // expanded before exec runs. Every one of these fails on the pre-fix code.
  // ---------------------------------------------------------------------------

  /** @return string a marker path that does not exist yet */
  private function marker($name) {
    $path = rtrim(sys_get_temp_dir(), '/') . '/bsl_test_' . $name . '_' . getmypid();
    if (file_exists($path)) { unlink($path); }
    return $path;
  }

  private function assertNotExecuted($marker, $command) {
    $fired = file_exists($marker);
    if ($fired) { unlink($marker); }
    $this->assertFalse($fired, "payload executed -- command injection is live. Command was: " . $command);
  }

  public function test_no_injection_via_local_identifier() {
    $marker = $this->marker('lid');
    $this->bs_local->binary_path = '/bin/echo';
    $this->bs_local->add_args('key', 'dummykey');
    $this->bs_local->add_args('localIdentifier', 'x$(touch ' . $marker . ')');
    $command = $this->bs_local->start_command();
    shell_exec($command . ' 2>&1');
    $this->assertNotExecuted($marker, $command);
  }

  public function test_no_injection_via_proxy_host_backticks() {
    $marker = $this->marker('proxy');
    $this->bs_local->binary_path = '/bin/echo';
    $this->bs_local->add_args('key', 'dummykey');
    $this->bs_local->add_args('proxyHost', 'x`touch ' . $marker . '`');
    $command = $this->bs_local->start_command();
    shell_exec($command . ' 2>&1');
    $this->assertNotExecuted($marker, $command);
  }

  public function test_no_injection_via_hosts() {
    $marker = $this->marker('hosts');
    $this->bs_local->binary_path = '/bin/echo';
    $this->bs_local->add_args('key', 'dummykey');
    $this->bs_local->add_args('hosts', 'x$(touch ' . $marker . ')');
    $command = $this->bs_local->start_command();
    shell_exec($command . ' 2>&1');
    $this->assertNotExecuted($marker, $command);
  }

  public function test_no_injection_via_logfile() {
    $marker = $this->marker('logfile');
    $this->bs_local->binary_path = '/bin/echo';
    $this->bs_local->add_args('key', 'dummykey');
    $this->bs_local->add_args('logfile', "/tmp/bsl_test.log'\$(touch " . $marker . ")'");
    $command = $this->bs_local->start_command();
    shell_exec($command . ' 2>&1');
    $this->assertNotExecuted($marker, $command);
  }

  public function test_no_injection_via_custom_flag_value() {
    $marker = $this->marker('customval');
    $this->bs_local->binary_path = '/bin/echo';
    $this->bs_local->add_args('key', 'dummykey');
    $this->bs_local->add_args('customFlag', "' \$(touch " . $marker . ") '");
    $command = $this->bs_local->start_command();
    shell_exec($command . ' 2>&1');
    $this->assertNotExecuted($marker, $command);
  }

  public function test_no_injection_via_stop_command() {
    $marker = $this->marker('stop');
    $this->bs_local->binary_path = '/bin/echo';
    $this->bs_local->add_args('localIdentifier', 'x$(touch ' . $marker . ')');
    $command = $this->bs_local->stop_command();
    shell_exec($command . ' 2>&1');
    $this->assertNotExecuted($marker, $command);
  }

  public function test_no_injection_via_pid_in_is_running() {
    $marker = $this->marker('pid');
    $this->bs_local->pid = 'aux$(touch ' . $marker . ')';
    $running = $this->bs_local->isRunning();
    $this->bs_local->pid = NULL; // keep tearDown()'s stop() a no-op
    $this->assertNotExecuted($marker, 'isRunning() with a non-numeric $pid');
    $this->assertFalse($running, 'a non-numeric pid must not be reported as running');
  }

  public function test_rejects_an_injectable_argument_name() {
    $marker = $this->marker('argkey');
    $thrown = false;
    try {
      $this->bs_local->add_args('x$(touch ' . $marker . ')', 'v');
    } catch (LocalException $e) {
      $thrown = true;
    }
    $this->assertTrue($thrown, 'add_args() must reject an argument name that is not [A-Za-z0-9_-]+');
    $this->assertNotExecuted($marker, 'add_args() with an injectable argument name');
  }

  public function test_argument_names_the_library_documents_are_still_accepted() {
    // Regression guard on the argument-name gate: everything the README and the
    // existing tests use must keep working, dashes included.
    foreach (array('v', 'force', 'only', 'onlyAutomate', 'forcelocal', 'forceproxy',
                   '-forceproxy', '-hosts', 'customKey1', 'custom_key_2', 'a1') as $name) {
      $local = new Local();
      $local->add_args($name, 'true');
      $this->assertNotEmpty($local->start_command(), "argument name '$name' must stay usable");
    }
  }

  public function test_unset_options_leave_no_empty_arguments() {
    // start_command() used to squeeze out the gaps left by unset flags with a
    // whitespace collapse. That collapse also rewrote whitespace inside quoted
    // values, so it was replaced by dropping the empty parts -- this asserts the
    // command line stays free of stray empty tokens.
    $this->bs_local->add_args('key', 'dummykey');
    $command = $this->bs_local->start_command();
    $this->assertStringNotContainsString("  ", $command);
    $this->assertStringNotContainsString(" '' ", $command);
  }

  public function test_values_keep_their_internal_whitespace() {
    $this->bs_local->add_args('key', 'dummykey');
    $this->bs_local->add_args('localIdentifier', "two  spaces");
    $this->assertStringContainsString("-localIdentifier 'two  spaces'", $this->bs_local->start_command());
  }

  /**
   * Starts the real binary — needs BROWSERSTACK_ACCESS_KEY and outbound network.
   *
   * @group network
   */
  public function test_isRunning() {
    $this->assertFalse($this->bs_local->isRunning());
    $this->bs_local->start(array('v' => true));
    $this->assertTrue($this->bs_local->isRunning());
    $this->bs_local->stop();
    $this->assertFalse($this->bs_local->isRunning());
    $this->bs_local->start(array('v' => true));
    $this->assertTrue($this->bs_local->isRunning());
  }

  /**
   * Starts the real binary — needs BROWSERSTACK_ACCESS_KEY and outbound network.
   *
   * @group network
   */
  public function test_checkPid() {
    $this->assertFalse($this->bs_local->isRunning());
    $this->bs_local->start(array('v' => true));
    $this->assertTrue($this->bs_local->pid > 0);
  }

  /**
   * Starts the real binary twice — needs BROWSERSTACK_ACCESS_KEY and outbound network.
   *
   * @group network
   */
  public function test_multiple_binary() {
    $this->bs_local->start(array('v' => true));
    $bs_local_2 = new Local();  
    $log_file2 = getcwd(). '/log2.log';
    print($log_file2);
    try {
      $bs_local_2->start(array('v' => true, 'logfile' => $log_file2));
      $this->fail("Expected Exception has not been raised.");
    } catch (LocalException $ex) {
      $emessage = $ex->getMessage();
      $this->assertEquals(trim($emessage), 'Either another browserstack local client is running on your machine or some server is listening on port 45691');
      unlink($log_file2);
      return;
    }
  }
}
