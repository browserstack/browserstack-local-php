<?php

namespace BrowserStack;

use Exception;

error_reporting(1);

class Local {

    private $handle = NULL;
    private $pipes = array();
    private $loghandle = NULL;
    
    public function __construct() {
        $this->key = getenv("BROWSERSTACK_KEY");
        $this->possible_binary_paths = array();
        $temp = $this->server_home() . "/.browserstack";
        array_push($this->possible_binary_paths, $temp);
        $temp = getcwd();
        array_push($this->possible_binary_paths, $temp);
        $this->logfile = $temp . "/local.log";
        $temp = sys_get_temp_dir();
        array_push($this->possible_binary_paths, $temp);
    }

    public function __destruct() {
    }

    public function isRunning() {
        if (is_null($this->handle))
            return False;

        $status = proc_get_status($this->handle);
        return $status["running"];
    }

    public function server_home() {
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

    public function add_args($arg_key, $value = NULL) {
        if ($arg_key == "key")
            $this->key = $value;
        elseif ($arg_key == "binaryPath")
            $this->binary_path = $value;
        elseif ($arg_key == "logfile")
            $this->logfile = $value;
        elseif ($arg_key == "v")
            $this->verbose_flag = "-v";
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

        if(!$this->check_binary()) {
            throw new LocalException("Unable to download binary");
            return;
        }
        
        $descriptorspec = array(
                0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
                1 => array("pipe", "w"), // stdout is a pipe that the child will write to
                2 => array("file", "/tmp/error-output.txt", "a") // stderr is a file to write to
                );

        $call = $this->command();
        
        $this->handle = proc_open($call, $descriptorspec,$this->pipes);
        
        $this->loghandle = fopen($this->logfile,"r");

        while(!feof($this->loghandle)) {
            $buffer = fgets($this->loghandle);
            if (preg_match("/\bError\b/i", $buffer,$match)) {
                throw new LocalException($buffer);
                proc_terminate($this->handle);
                break;
            }
            elseif (strcmp(rtrim($buffer),"Press Ctrl-C to exit") == 0)
                break;

            flush();    
        }
    }

    public function stop() {
        fclose($this->loghandle);
        if (is_null($this->handle))
            return;
        else
            proc_terminate($this->handle);
    }

    private function platform_url()
    {
        if (PHP_OS == "Darwin")
            return "https://www.browserstack.com/browserstack-local/BrowserStackLocal-darwin-x64.zip";
        else if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
            return "https://www.browserstack.com/browserstack-local/BrowserStackLocal-win32.zip";
        if ((strtoupper(PHP_OS)) == "LINUX") {
            if (PHP_INT_SIZE * 8 == 64)
                return "https://www.browserstack.com/browserstack-local/BrowserStackLocal-linux-x64.zip";
            else
                return "https://www.browserstack.com/browserstack-local/BrowserStackLocal-linux-ia32.zip";
        }
    }

    public function download_binary($path,$url) {
        if (!file_exists($path))
            mkdir($path, 0777, true);
        
        file_put_contents($path . '/BrowserStack.zip', fopen($url, 'r'));
            $zip = new \ZipArchive;
            if ($zip->open($path . '/BrowserStack.zip') === TRUE) {
                $zip->extractTo($path);
                $zip->close();
            } else {
            }
    }

    public function check_binary() {
        $url = $this->platform_url();
        
        if (isset($this->binary_path)) {
            if (is_executable($this->binary_path))
                return true;
            else{
                $this->download_binary(dirname($this->binary_path),$url);
            }
            if(is_executable($this->binary_path))
                return true;
        }
        else
        {
            $arrlength = count($this->possible_binary_paths);
            for($x = 0; $x < $arrlength; $x++) {
                $this->binary_path = $this->possible_binary_paths[$x] . "/BrowserStackLocal";
                if(is_executable($this->binary_path))
                    return true;
                $this->download_binary($this->possible_binary_paths[$x],$url);
                chmod($this->binary_path, 0777);
                if(is_executable($this->binary_path))
                    return true;
            }
        }
        return false;
    }

    public function command() {
        $command = "$this->binary_path -logFile $this->logfile $this->folder_flag $this->key $this->folder_path $this->force_local_flag $this->local_identifier_flag $this->only_flag $this->only_automate_flag $this->proxy_host $this->proxy_port $this->proxy_user $this->proxy_pass $this->force_flag $this->verbose_flag $this->hosts";
        $command = preg_replace('/\s+/S', " ", $command);
        return $command;
    }
}

