<?php

namespace BrowserStack;

use Exception;

error_reporting(1);

class Local {

    private $handle = NULL;
    private $pipes = array();

    public function __construct() {
        $this->key = getenv("BROWSERSTACK_KEY");
        if (!is_executable("BrowserStack/BrowserStackLocal"))
            $this->prepare_binary();
    }

    public function __destruct() {
        echo "";
    }

    public function is_running() {
        $host = 'localhost';
        $port = '45691';

        $connection = fsockopen($host, $port);
        if (is_resource($connection))
            return True;
        else
            return False;
    }

    public function add_args($arg_key, $value = NULL) {
        if ($arg_key == "access_key")
            $this->key = $value;
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

        $descriptorspec = array(
                0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
                1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
                2 => array("file", "/tmp/error-output.txt", "a") // stderr is a file to write to
                );

        $call = $this->command();

        $this->handle = proc_open($call, $descriptorspec,$this->pipes);

        while(!feof($this->pipes[1])) {
            $buffer = fgets($this->pipes[1]);

            if (preg_match("/\bError\b/i", $buffer,$match)) {
                throw new LocalException($buffer);
                proc_terminate($this->handle);
                return;
            }
            elseif (strcmp(rtrim($buffer),"Press Ctrl-C to exit") == 0)
                return;

            flush();    
        }
    }

    public function stop() {
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

    public function prepare_binary($url) {
        $url = $this->platform_url();
        mkdir('BrowserStack', 0777, true);
        file_put_contents("BrowserStack/BrowserStack.zip", fopen($url, 'r'));
        $zip = new \ZipArchive;
        if ($zip->open('BrowserStack/BrowserStack.zip') === TRUE) {
            $zip->extractTo("BrowserStack/");
            $zip->close();
        } else {

        }
        chmod("BrowserStack/BrowserStackLocal", 0777);
    }

    public function command() {
        $command = "./BrowserStack/BrowserStackLocal $this->folder_flag $this->key $this->folder_path $this->force_local_flag $this->local_identifier_flag $this->only_flag $this->only_automate_flag $this->proxy_host $this->proxy_port $this->proxy_user $this->proxy_pass $this->force_flag $this->verbose_flag $this->hosts";
        $command = preg_replace('/\s+/S', " ", $command);
        return $command;
    }
}

