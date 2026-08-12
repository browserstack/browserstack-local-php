<?php

namespace BrowserStack;

use Exception;
use BrowserStack\LocalBinary;
use BrowserStack\LocalException;

error_reporting(1);

class Local {

  /**
   * Argument names are emitted as `-<name>` flags straight into the command
   * line, so the name itself is an injection vector (CWE-78). Only these
   * characters may reach the shell as a flag name; anything else is rejected
   * by add_args() rather than quoted, because a quoted flag name would not be
   * a flag any more.
   */
  const ARG_KEY_PATTERN = '/^[A-Za-z0-9_-]+$/';

  public $pid = NULL;

  /**
   * Quote a value so the shell treats it as exactly one literal argument.
   * escapeshellarg() has been available since PHP 4, so this keeps the
   * library's declared floor of PHP >= 5.3.19.
   */
  private static function esc($value) {
    return escapeshellarg((string) $value);
  }

  /**
   * Join pre-escaped command fragments, dropping the empty ones.
   *
   * This replaces the old `preg_replace('/\s+/S', " ", $command)` collapse.
   * That collapse existed only to squeeze out the gaps left by unset flags,
   * but it rewrote whitespace *inside* quoted values too — which would now
   * corrupt legitimately escaped arguments (a value of "a  b" would arrive at
   * the binary as "a b"). Filtering the parts achieves the same tidy command
   * line without touching the arguments themselves.
   */
  private static function join_parts($parts) {
    $out = array();
    foreach ($parts as $part) {
      $part = trim((string) $part);
      if ($part !== "")
        $out[] = $part;
    }
    return implode(" ", $out);
  }

  public function __construct() {
    $this->key = getenv("BROWSERSTACK_ACCESS_KEY");
    $this->logfile = getcwd() . "/local.log";
    $this->user_args = array();
    $this->binary_path = "";
    $this->folder_flag = "";
    $this->folder_path = "";
    $this->force_local_flag = "";
    $this->local_identifier_flag = "";
    $this->only_flag = "";
    $this->only_automate_flag = "";
    $this->proxy_host = "";
    $this->proxy_port = "";
    $this->proxy_user = "";
    $this->proxy_pass = "";
    $this->force_proxy_flag = "";
    $this->force_flag = "";
    $this->verbose_flag = "";
    $this->hosts = "";
  }

  public function __destruct() {
  }

  public function isRunning() {
    if ($this->pid == NULL)
      return False;
    if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
    {
      $processes = explode( "\n", shell_exec( "tasklist.exe" ));
      foreach( $processes as $process )
      {
        if( strpos( "Image Name", $process ) === 0 || strpos( "===", $process ) === 0 )
          continue;
        $matches = false;
        preg_match( "/(.*?)\s+(\d+).*$/", $process, $matches );
        $this->pid = $matches[ 2 ];
        return True;
      }
      return False;
    }
    else {
      // $pid is public and is also populated from the spawned process's stdout
      // (see start()), so it must never be concatenated into a shell string
      // raw. A PID is an integer by definition — cast, and treat anything that
      // is not a positive integer as "not running" rather than asking ps about
      // it.
      $pid = intval($this->pid);
      if ($pid <= 0)
        return False;
      $return_message = shell_exec("ps -" . $pid . " | wc -l");
      if (intval($return_message) > 1)
      {
        return True;
      }
      return False;
    }
  }

  public function add_args($arg_key, $value = NULL) {
    if (!is_string($arg_key) || !preg_match(self::ARG_KEY_PATTERN, $arg_key))
      throw new LocalException(
        "Invalid BrowserStack Local argument name. Argument names may only " .
        "contain letters, digits, '-' and '_'; got: " . var_export($arg_key, true)
      );

    if ($arg_key == "key")
      $this->key = $value;
    elseif ($arg_key == "binaryPath")
      $this->binary_path = $value;
    elseif ($arg_key == "logfile")
      $this->logfile = $value;
    elseif ($arg_key == "v")
      $this->verbose_flag = "-vvv";
    elseif ($arg_key == "force")
      $this->force_flag = "-force";
    elseif ($arg_key == "only")
      $this->only_flag = "-only";
    elseif ($arg_key == "onlyAutomate")
      $this->only_automate_flag = "-onlyAutomate";
    elseif ($arg_key == "forcelocal")
      $this->force_local_flag = "-forcelocal";
    elseif ($arg_key == "localIdentifier")
      $this->local_identifier_flag = "-localIdentifier " . self::esc($value);
    elseif ($arg_key == "proxyHost")
      $this->proxy_host = "-proxyHost " . self::esc($value);
    elseif ($arg_key == "proxyPort")
      $this->proxy_port = "-proxyPort " . self::esc($value);
    elseif ($arg_key == "proxyUser")
      $this->proxy_user = "-proxyUser " . self::esc($value);
    elseif ($arg_key == "proxyPass")
      $this->proxy_pass = "-proxyPass " . self::esc($value);
    elseif ($arg_key == "forceproxy")
      $this->force_proxy_flag = "-forceproxy";
    elseif ($arg_key == "hosts")
      $this->hosts = $value;
    elseif ($arg_key == "f") {
      $this->folder_flag = "-f";
      $this->folder_path = $value;
    }
    elseif ($value !== NULL && strtolower((string) $value) == "true"){
      array_push($this->user_args, "-$arg_key");
    }
    else {
      array_push($this->user_args, "-$arg_key " . self::esc($value));
    }
  }

  public function start($arguments) {
    foreach($arguments as $key => $value)
      $this->add_args($key,$value);

    $this->binary = new LocalBinary();
    $this->binary_path = $this->binary->binary_path();
    
    $call = $this->start_command();
    // The logfile path is caller-supplied (add_args('logfile', ...)), so it is
    // quoted here too — the old single-quote wrapper was escapable. The Windows
    // branch additionally used a single-quoted PHP string, so it truncated a
    // file literally named '$this->logfile' instead of the configured one.
    system("echo \"\" > " . self::esc($this->logfile));
    $call = $call . " 2>&1";
    $return_message = shell_exec($call);
    $data = json_decode($return_message,true);
    if ($data["state"] != "connected") {
      throw new LocalException($data['message']['message']);
    }
    $this->pid = $data['pid'];
  }

  public function stop() {
    if(!$this->pid) return;
    $call = $this->stop_command();
    shell_exec("$call");
    $this->pid = null;
  }

  public function start_command() {
    $exec = "exec";
    // TODO to test on windows
    if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
      $exec = "call";

    // Every caller-supplied value is quoted so the shell sees it as one literal
    // argument. The fixed flag names and the $exec builtin are the only tokens
    // that stay unquoted, and none of them is caller-controlled. The flag
    // fragments built in add_args() are already escaped there.
    $parts = array($exec);
    if ((string) $this->binary_path !== "")
      $parts[] = self::esc($this->binary_path);
    $parts[] = "-d";
    $parts[] = "start";
    $parts[] = "-logFile";
    $parts[] = self::esc($this->logfile);
    $parts[] = $this->folder_flag;
    if ((string) $this->key !== "")
      $parts[] = self::esc($this->key);
    if ((string) $this->folder_path !== "")
      $parts[] = self::esc($this->folder_path);
    $parts[] = $this->force_local_flag;
    $parts[] = $this->local_identifier_flag;
    $parts[] = $this->only_flag;
    $parts[] = $this->only_automate_flag;
    $parts[] = $this->proxy_host;
    $parts[] = $this->proxy_port;
    $parts[] = $this->proxy_user;
    $parts[] = $this->proxy_pass;
    $parts[] = $this->force_proxy_flag;
    $parts[] = $this->force_flag;
    $parts[] = $this->verbose_flag;
    if ((string) $this->hosts !== "")
      $parts[] = self::esc($this->hosts);
    $parts = array_merge($parts, $this->user_args);

    return self::join_parts($parts);
  }

  public function stop_command() {
    $exec = "exec";
    // TODO to test on windows
    if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
      $exec = "call";

    $parts = array($exec);
    if ((string) $this->binary_path !== "")
      $parts[] = self::esc($this->binary_path);
    $parts[] = "-d";
    $parts[] = "stop";
    $parts[] = $this->local_identifier_flag;

    return self::join_parts($parts);
  }

}

?>
