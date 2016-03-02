<?php

namespace BrowserStack;

use Exception;
use BrowserStack\LocalBinary;
use BrowserStack\LocalException;

error_reporting(1);

class Local {

  private $handle = NULL;
  private $pipes = array();
  private $loghandle = NULL;
  public $pid = NULL;
  
  public function __construct() {
    $this->key = getenv("BROWSERSTACK_ACCESS_KEY");
    $this->logfile = getcwd() . "/local.log";
  }

  public function __destruct() {
  }

  public function isRunning() {
    if (is_null($this->handle))
      return False;

    $status = proc_get_status($this->handle);
    return is_null($status["running"]) ? false : $status["running"];
  }

  public function add_args($arg_key, $value = NULL) {
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
      $this->local_identifier_flag = "-localIdentifier $value";
    elseif ($arg_key == "proxyHost")
      $this->proxy_host = "-proxyHost $value";
    elseif ($arg_key == "proxyPort")
      $this->proxy_port = "-proxyPort $value";
    elseif ($arg_key == "proxyUser")
      $this->proxy_user = "-proxyUser $value";
    elseif ($arg_key == "proxyPass")
      $this->proxy_pass = "-proxyPass $value";
    elseif ($arg_key == "hosts")
      $this->hosts = $value;
    elseif ($arg_key == "f") {
      $this->folder_flag = "-f";
      $this->folder_path = $value;
    }
  }

  public function start($arguments) {
    foreach($arguments as $key => $value)
      $this->add_args($key,$value);

    $this->binary = new LocalBinary();
    $this->binary_path = $this->binary->binary_path();
    
    $descriptorspec = array(
      0 => array("pipe", "r"),
      1 => array("pipe", "w"),
      2 => array("pipe", "w")
    );

    $call = $this->command();
    system('echo "" > '. $this->logfile);
    $this->handle = proc_open($call, $descriptorspec, $this->pipes);
    $status = proc_get_status($this->handle);
    $this->pid = $status['pid'];

    $this->loghandle = fopen($this->logfile,"r");
    while (true) {
      $buffer = fread($this->loghandle, 1024);
      if (preg_match("/Error:[^\n]+/i", $buffer, $match)) {
        $this->stop();
        throw new LocalException($match[0]);
        break;
      }
      elseif (preg_match("/\bPress Ctrl-C to exit\b/i", $buffer, $match)){
        fclose($this->loghandle);
        break;
      }

      //flush();
      sleep(1);
    }
  }

  public function stop() {
    fclose($this->loghandle);
    if (is_null($this->handle))
      return;
    else {
      while(fgets($this->pipes[0]));
      fclose($this->pipes[0]);
      fclose($this->pipes[1]);
      fclose($this->pipes[2]);

      // if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN')
      //   exec('kill -2 ' . $this->pid);
      proc_terminate($this->handle, 2);
      $status = proc_get_status($this->handle);
      echo "Stopping";
      print_r($status);
      //proc_close($this->handle);
      // $status = proc_get_status($this->handle);
      // echo "Stopped";
      // print_r($status);
      while($this->isRunning())
        sleep(1);
    }
  }

  public function command() {
    $command = "$this->binary_path -logFile $this->logfile $this->folder_flag $this->key $this->folder_path $this->force_local_flag $this->local_identifier_flag $this->only_flag $this->only_automate_flag $this->proxy_host $this->proxy_port $this->proxy_user $this->proxy_pass $this->force_flag $this->verbose_flag $this->hosts";
    $command = preg_replace('/\s+/S', " ", $command);
    return $command;
  }
}

?>
