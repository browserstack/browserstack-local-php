<?php

namespace BrowserStack;

use Exception;
use BrowserStack\LocalException;

error_reporting(1);

class LocalBinary {

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
    $dest_binary_name = "BrowserStackLocal";
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
      $dest_binary_name = $dest_binary_name. ".exe";
    }
    $binary_path = $dest_parent_dir. "/". $dest_binary_name;
    if(file_exists($binary_path)){
      return $binary_path;
    }
    else {
      return $this->download_binary($dest_parent_dir);
    }
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

  private function platform_url(){
    if (PHP_OS == "Darwin") {
      if (in_array(php_uname('m'), array('arm64', 'aarch64')))
        return 'https://s3.amazonaws.com/browserStack/browserstack-local/BrowserStackLocal-darwin-arm64';
      return 'https://s3.amazonaws.com/browserStack/browserstack-local/BrowserStackLocal-darwin-x64';
    }
    else if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
      return 'https://s3.amazonaws.com/browserStack/browserstack-local/BrowserStackLocal.exe';
    if ((strtoupper(PHP_OS)) == "LINUX") {
      if (PHP_INT_SIZE * 8 == 64)
        return 'https://s3.amazonaws.com/browserStack/browserstack-local/BrowserStackLocal-linux-x64';
      else
        return 'https://s3.amazonaws.com/browserStack/browserstack-local/BrowserStackLocal-linux-ia32';
    }
  }

  public function download_binary($path) {
    $urls = array($this->platform_url());
    // If the arm64 binary is not published to the legacy bucket yet, fall
    // back to the x64 binary, which still works on Apple Silicon via
    // Rosetta 2 — releasing must not turn a working download into a 404.
    if (substr($urls[0], -13) === '-darwin-arm64')
      $urls[] = str_replace('-darwin-arm64', '-darwin-x64', $urls[0]);
    if (!file_exists($path))
      mkdir($path, 0777, true);


    $dest_binary_name = "BrowserStackLocal";
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
      $dest_binary_name = $dest_binary_name. ".exe";
    }
    $dest_binary_path = $path. '/'. $dest_binary_name;
    foreach ($urls as $url) {
      $file = fopen($dest_binary_path , "w+");
      $ch = curl_init("");
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_FILE, $file);
      // Fail on HTTP >= 400 instead of writing the error body to disk —
      // a saved error body would satisfy the file_exists() check in
      // binary_path() and stick until the user deletes it by hand.
      curl_setopt($ch, CURLOPT_FAILONERROR, true);
      $data = curl_exec ($ch);
      curl_close ($ch);

      fclose($file);
      if ($data !== false) {
        chmod($dest_binary_path, 0755);
        return $dest_binary_path;
      }
      // remove the empty/partial file so the next attempt (or next run) retries
      unlink($dest_binary_path);
    }
    throw new LocalException("Failed to download BrowserStackLocal binary from: " . implode(", ", $urls));
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
