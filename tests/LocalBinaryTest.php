<?php

namespace BrowserStack;

use BrowserStack\LocalBinary;
use BrowserStack\LocalException;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Test double that lets a test choose the download URL and reach the protected
 * verification helper. platform_url() is protected on LocalBinary precisely so
 * this is possible without patching installed source.
 */
class TestableLocalBinary extends LocalBinary {

  private $url;

  public function set_url($url) {
    $this->url = $url;
  }

  protected function platform_url() {
    return $this->url;
  }

  public function call_verify_binary($path) {
    return $this->verify_binary($path);
  }
}

class LocalBinaryTest extends \PHPUnit\Framework\TestCase {

  private $binary;
  private $dir;

  protected function setUp(): void {
    $this->binary = new TestableLocalBinary();
    $this->dir = sys_get_temp_dir() . '/bs-local-binary-test-' . getmypid() . '-' . mt_rand();
    mkdir($this->dir, 0777, true);
  }

  protected function tearDown(): void {
    foreach (glob($this->dir . '/*') as $file) {
      unlink($file);
    }
    if (is_dir($this->dir))
      rmdir($this->dir);
  }

  private function dest_path() {
    $name = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'BrowserStackLocal.exe' : 'BrowserStackLocal';
    return $this->dir . '/' . $name;
  }

  /**
   * LOC-6740: the download must refuse a server whose certificate chain does
   * not validate. Before the fix CURLOPT_SSL_VERIFYPEER was false, so this
   * returned a path to attacker-supplied bytes instead of raising.
   *
   * @group network
   */
  public function test_download_rejects_untrusted_certificate() {
    $this->binary->set_url('https://self-signed.badssl.com/');
    $raised = null;
    try {
      $this->binary->download_binary($this->dir);
    }
    catch (LocalException $e) {
      $raised = $e;
    }
    $this->assertNotNull($raised, 'download_binary must reject an untrusted certificate');
    // cURL error 60 is CURLE_PEER_FAILED_VERIFICATION — pins the failure to
    // certificate validation rather than any later check.
    $this->assertStringContainsString('cURL error 60', $raised->getMessage());
    $this->assertFalse(file_exists($this->dest_path()), 'no file may be left behind on failure');
  }

  /**
   * @group network
   */
  public function test_download_rejects_hostname_mismatch() {
    $this->binary->set_url('https://wrong.host.badssl.com/');
    $raised = null;
    try {
      $this->binary->download_binary($this->dir);
    }
    catch (LocalException $e) {
      $raised = $e;
    }
    $this->assertNotNull($raised, 'download_binary must reject a certificate for another host');
    $this->assertFalse(file_exists($this->dest_path()));
  }

  /**
   * A non-2xx response body must never be stored and made executable.
   *
   * @group network
   */
  public function test_download_rejects_http_error_status() {
    $this->binary->set_url('https://s3.amazonaws.com/browserStack/browserstack-local/does-not-exist');
    $raised = null;
    try {
      $this->binary->download_binary($this->dir);
    }
    catch (LocalException $e) {
      $raised = $e;
    }
    $this->assertNotNull($raised, 'download_binary must reject a non-2xx response');
    $this->assertFalse(file_exists($this->dest_path()));
  }

  public function test_verify_binary_rejects_error_page() {
    $path = $this->dir . '/error-page';
    file_put_contents($path, '<?xml version="1.0"?><Error><Code>AccessDenied</Code></Error>');
    $this->assertFalse($this->binary->call_verify_binary($path));
  }

  public function test_verify_binary_rejects_empty_file() {
    $path = $this->dir . '/empty';
    file_put_contents($path, '');
    $this->assertFalse($this->binary->call_verify_binary($path));
  }

  /**
   * A file of plausible size that is not a platform executable — e.g. a large
   * HTML interstitial from a captive portal — must still be rejected.
   */
  public function test_verify_binary_rejects_large_non_executable() {
    $path = $this->dir . '/large-html';
    file_put_contents($path, '<html>' . str_repeat('a', 2 * 1024 * 1024) . '</html>');
    $this->assertFalse($this->binary->call_verify_binary($path));
  }

  public function test_verify_binary_accepts_platform_executable() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
      $magic = "MZ\x90\x00";
    else if (PHP_OS == 'Darwin')
      $magic = "\xcf\xfa\xed\xfe";
    else
      $magic = "\x7f" . "ELF";

    $path = $this->dir . '/fake-binary';
    file_put_contents($path, $magic . str_repeat("\x00", 2 * 1024 * 1024));
    $this->assertTrue($this->binary->call_verify_binary($path));
  }
}

?>
