<?php
// An example of using php-browserstacklocal.

namespace BrowserStack;

use BrowserStack\Local;
use BrowserStack\LocalException;

require_once __DIR__ . '/../vendor/autoload.php';

class LocalTest extends \PHPUnit_Framework_TestCase {
	
  private $bs_local;
  
  public function setUp(){
    $this->bs_local = new Local();
  }
  
  public function test_verbose() {
    $this->bs_local->add_args('v');
    $this->assertContains('-v',$this->bs_local->command());
  }

  public function test_set_folder() {
    $this->bs_local->add_args('f', "/");
    $this->assertContains('-f',$this->bs_local->command());
    $this->assertContains('/',$this->bs_local->command());
  }
  
  public function test_enable_force() {
    $this->bs_local->add_args("force");
  }

  public function test_enable_only() {
    $this->bs_local->add_args("only");
    $this->assertContains('-only',$this->bs_local->command());
  }

  public function test_enable_only_automate() {
    $this->bs_local->add_args("onlyAutomate");
    $this->assertContains('-onlyAutomate', $this->bs_local->command()); 
  }

  public function test_enable_force_local() {
    $this->bs_local->add_args("forcelocal");
    $this->assertContains('-forcelocal',$this->bs_local->command());
  }

  public function test_set_local_identifier() {
    $this->bs_local->add_args("localIdentifier", "randomString");
    $this->assertContains('-localIdentifier randomString',$this->bs_local->command());
  }

  public function test_set_proxy() {
    $this->bs_local->add_args("proxyHost", "localhost");
    $this->bs_local->add_args("proxyPort", 8080);
    $this->bs_local->add_args("proxyUser", "user");
    $this->bs_local->add_args("proxyPass", "pass");
    $this->assertContains('-proxyHost localhost -proxyPort 8080 -proxyUser user -proxyPass pass',$this->bs_local->command());
  }

  public function test_hosts() {
    $this->bs_local->add_args("hosts", "localhost,8080,0");
    $this->assertContains('localhost,8080,0',$this->bs_local->command());
  }

  public function test_multiple_binary() {
    $this->bs_local->start();
    $bs_local_2 = new Local(getenv("BROWSERSTACK_KEY"));  
    try {
      $bs_local_2->start();
    } catch (LocalException $ex) {
        $emessage = $ex->getMessage();
        $this->assertEquals(trim($emessage), '*** Error: Either another browserstack local client is running on your machine or some server is listening on port 45691');
        $bs_local_2->stop();
        $this->bs_local->stop();
        sleep(2);
        return;
      }
    $this->fail("Expected Exception has not been raised.");
    $this->bs_local->stop();
    sleep(2);
  }

  public function test_is_running() {
    $this->assertFalse($this->bs_local->is_running());
    $this->bs_local->start();
    $this->assertTrue($this->bs_local->is_running());
    $this->bs_local->stop();
    sleep(2);
    $this->assertFalse($this->bs_local->is_running());
  }
}