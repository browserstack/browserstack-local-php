<?php

namespace BrowserStack;

use Exception;
use BrowserStack\LocalException;

error_reporting(1);

class LocalBinary {

  const DOWNLOAD_ATTEMPTS = 3;
  const CONNECT_TIMEOUT = 10;
  const DOWNLOAD_TIMEOUT = 300;

  // Any real BrowserStackLocal build is tens of MB. A gateway error page or a
  // truncated transfer is orders of magnitude smaller.
  const MIN_BINARY_SIZE = 1048576;

  public function __construct() {
    $this->possible_binary_paths = array(
      $this->server_home() . "/.browserstack",
      getcwd(),
      sys_get_temp_dir()
    );
  }

  public function __destruct() {
  }

  public function binary_path() {
    $dest_parent_dir = $this->get_available_dirs();
    $binary_path = $dest_parent_dir. "/". $this->dest_binary_name();
    if (file_exists($binary_path)) {
      if ($this->verify_binary($binary_path)) {
        $this->make_executable($binary_path);
        return $binary_path;
      }
      // A cached file that is not a usable binary is discarded rather than
      // executed — it may be a stored error page or a partial download.
      // nosemgrep: php.lang.security.unlink-use.unlink-use -- basename is fixed by dest_binary_name(), no user input in the path
      unlink($binary_path);
    }
    return $this->download_binary($dest_parent_dir);
  }

  private function server_home() {
    // getenv('HOME') isn't set on Windows and generates a Notice.
    $home = getenv('HOME');
    if (!empty($home)) {
      // home should never end with a trailing slash.
      $home = rtrim($home, '/');
    }
    elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
      // home on windows
      $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
      // If HOMEPATH is a root directory the path can end with a slash. Make sure
      // that doesn't happen.
      $home = rtrim($home, '\\/');
    }
    return empty($home) ? NULL : $home;
  }

  private function is_windows() {
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
  }

  private function dest_binary_name() {
    return $this->is_windows() ? "BrowserStackLocal.exe" : "BrowserStackLocal";
  }

  // protected so tests can point the download at a local fixture without
  // patching installed source.
  protected function platform_url(){
    if (PHP_OS == "Darwin")
      return 'https://s3.amazonaws.com/browserStack/browserstack-local/BrowserStackLocal-darwin-x64';
    else if ($this->is_windows())
      return 'https://s3.amazonaws.com/browserStack/browserstack-local/BrowserStackLocal.exe';
    if ((strtoupper(PHP_OS)) == "LINUX") {
      if (PHP_INT_SIZE * 8 == 64)
        return 'https://s3.amazonaws.com/browserStack/browserstack-local/BrowserStackLocal-linux-x64';
      else
        return 'https://s3.amazonaws.com/browserStack/browserstack-local/BrowserStackLocal-linux-ia32';
    }
  }

  public function download_binary($path) {
    $url = $this->platform_url();
    if (empty($url))
      throw new LocalException("No BrowserStack Local binary is available for platform " . PHP_OS);

    if (!file_exists($path))
      mkdir($path, 0777, true);

    $dest_binary_path = $path. '/'. $this->dest_binary_name();
    $last_error = "unknown error";

    for ($attempt = 1; $attempt <= self::DOWNLOAD_ATTEMPTS; $attempt++) {
      try {
        $this->fetch_binary($url, $dest_binary_path);
        if ($this->verify_binary($dest_binary_path)) {
          // Execute permission is granted only after the download has been
          // verified, never before.
          $this->make_executable($dest_binary_path);
          return $dest_binary_path;
        }
        $last_error = "downloaded file is not a valid BrowserStackLocal binary";
      }
      catch (LocalException $e) {
        $last_error = $e->getMessage();
      }
      // Never leave an unverified file behind for a later run to pick up and execute.
      // nosemgrep: php.lang.security.unlink-use.unlink-use -- basename is fixed by dest_binary_name(), no user input in the path
      if (file_exists($dest_binary_path))
        unlink($dest_binary_path);
    }

    throw new LocalException("Error trying to download BrowserStack Local binary from " .
      $url . " after " . self::DOWNLOAD_ATTEMPTS . " attempts. Last error: " . $last_error);
  }

  protected function fetch_binary($url, $dest_binary_path) {
    $file = fopen($dest_binary_path, "w+");
    if ($file === false)
      throw new LocalException("Unable to open " . $dest_binary_path . " for writing");

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    // The binary is executed on the user's machine, so the transport that
    // delivers it must be authenticated: validate the certificate chain and
    // that the certificate matches the host we asked for.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // A redirect must not be able to downgrade the transfer to plaintext.
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_FILE, $file);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
    curl_setopt($ch, CURLOPT_TIMEOUT, self::DOWNLOAD_TIMEOUT);
    $result = curl_exec($ch);
    $curl_errno = curl_errno($ch);
    $curl_error = curl_error($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($file);

    if ($result === false || $curl_errno !== 0)
      throw new LocalException("Download failed (cURL error " . $curl_errno . "): " . $curl_error);

    if ($http_status < 200 || $http_status >= 300)
      throw new LocalException("Download failed with HTTP status " . $http_status);
  }

  // Confirms the bytes on disk are a platform executable of plausible size.
  // This catches error pages, truncated transfers and empty files; it is not an
  // authenticity check — that is the job of TLS chain validation in
  // fetch_binary(). The file is deliberately not executed to test it.
  protected function verify_binary($binary_path) {
    // The retry loop stats the same path up to DOWNLOAD_ATTEMPTS times, so a
    // cached stat entry from a previous attempt must not decide this one.
    clearstatcache(true, $binary_path);
    if (!is_file($binary_path))
      return false;
    if (filesize($binary_path) < self::MIN_BINARY_SIZE)
      return false;

    $handle = fopen($binary_path, "rb");
    if ($handle === false)
      return false;
    $magic = fread($handle, 4);
    fclose($handle);
    if ($magic === false || strlen($magic) < 4)
      return false;

    if ($this->is_windows())
      $valid = (substr($magic, 0, 2) === "MZ");
    else if (PHP_OS == "Darwin")
      // Mach-O 64/32-bit little-endian, plus the universal ("fat") header.
      $valid = in_array($magic, array("\xcf\xfa\xed\xfe", "\xce\xfa\xed\xfe", "\xca\xfe\xba\xbe"), true);
    else
      $valid = ($magic === "\x7f" . "ELF");

    return $valid;
  }

  private function make_executable($binary_path) {
    clearstatcache(true, $binary_path);
    if ($this->is_windows() || is_executable($binary_path))
      return true;
    return @chmod($binary_path, 0755);
  }

  private function get_available_dirs() {
    $arrlength = count($this->possible_binary_paths);
    for($x = 0; $x < $arrlength; $x++) {
      $path = $this->possible_binary_paths[$x];
      if(file_exists($path) || $this->make_path($path))
        return $path;
    }
    throw new LocalException("Error trying to download BrowserStack Local binary");
  }

  private function make_path($path){
    return mkdir($path, 0777, true);
  }
}

?>
