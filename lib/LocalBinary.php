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
        $binary_path = $dest_parent_dir. "/BrowserStackLocal";
        print($binary_path);
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

    public function download_binary($path) {
        $url = $this->platform_url();
        if (!file_exists($path))
            mkdir($path, 0777, true);
        
        file_put_contents($path . '/BrowserStack.zip', fopen($url, 'r'));
        $zip = new \ZipArchive;
        if ($zip->open($path . '/BrowserStack.zip') === TRUE) {
            $zip->extractTo($path);
            $zip->close();
        } 
        else {}
        return $path . "/BrowserStackLocal";
    }

    private function get_available_dirs() {
        $arrlength = count($this->possible_binary_paths);
        for($x = 0; $x < $arrlength; $x++) {
            $path = $this->possible_binary_paths[$x];
            $localpath = $path . "/BrowserStackLocal";
            if(file_exists($localpath) || $this->make_path($path))
                return $path;
        }
        throw new LocalException("Error trying to download BrowserStack Local binary");
    }

    private function make_path($path){
        return mkdir($path, 0777, true);
    }
}

?>
