<?php
/**
 * src/SSH.php.
 *
 * A wrapper class for the PHPSECLIB SSHv2 client
 *
 * PHP version 7
 *
 * @category  default
 *
 * @author    metaclassing
 * @copyright 2010-2017 @authors
 * @license   http://www.gnu.org/copyleft/lesser.html The GNU LESSER GENERAL PUBLIC LICENSE, Version 3.0
 */

namespace Ohtarr;

// TODO:
/*
    SSH key based authentication vs username/password
    EXTERNALIZE the SSH prompt patterns into a JSON file
*/

class SSH
{
    public $connected;
    public $prompt;
    public $patterns;
    public $pattern;
    public $host;
    public $port = 22;
    public $messages = '';
    public $loglevel = 0;
    public $timeout = 5;
    public $ssh;

    public function __construct($data = null)
    {
        $this->connected = '';
        $this->prompt = '';
        $this->devicetype = '';

        // Load a set of common default prompt patterns!
        // These patterns are applied IN ORDER, so put the most specific FIRST!
        $this->patterns = [
            // Aruba is dumb: (Stupid-Preferred-Master) #
                ['devicetype'	=> 'aruba',
                    'detect'	 => '/\(([\w\-\/]+)\)\s+[#>]\s*$/',
                    'match'		 => '/(.*)\(%s\).*(>|#)\s*/',
                    ],

                    [
                        'devicetype'=> 'arubaclearpass',
                        'detect'    => '/\[([\w@\.\-\/]+)\][#>]\s*$/',
                        'match'     => '/(.*)\[%s\].*(>|#)\s*/',
                    ],
            /*
                Sample Prompts: ( Test with http://regex101.com/ )
                    IOS	 -   KHONEMDCRRR01#
                    IOS-XE  -   KHONEMDCRWA02#
                    IOS-XR  -   RP/0/RSP0/CPU0:KHONEMDCRWA01#
                    NXOS	-   KHONEMDCSWC01_ADMIN#
                    ASA	 -   khonedmzrfw01/pri/act/901-IN#
            */
                ['devicetype'	=> 'ciscoxr',
                    'detect'	 => '/RP\/0\/RSP0\/CPU0:([\w\-]+)(.*)[#>]\s*$/',
                    'match'		 => '/(.*)RP\/0\/RSP0\/CPU0:%s.*(>|#)\s*/',
                    ],

                ['devicetype'	=> 'cisco',
//					'detect'	=> '/([\w\-]+)(\/.*)?[#>]\s*$/' ,
                    'detect'	=> '/(?!.*:)([\w\-\/]+)[#>]\s*$/',
                    /*
                                    ^--- Dont match anything up to a leading : (XR format)
                                            ^--- Match a-z0-9 - and /
                                                        ^--- Terminate match with our prompt enders > and #
                                                            ^--- Ignore any trailing whitespace
                    */
                    'match'		=> '/(.*)%s.*(>|#)\s*/',
                    ],

/*				array(	'devicetype'	=> 'cisconxos' ,
                    'detect'	=> '/([\w\-]+)(\/.*)?[#>]\s*$/' ,
                    'match'		=> '/(.*)%s.*(>|#)([^ \n\r^M]+)/'
                    ),
/**/
            ];

        // COPY the properties from an object we were passed
        if (is_object($data)) {
            if (property_exists($data, 'host')) {
                $this->host = $data->host;
            }
            if (property_exists($data, 'username')) {
                $this->username = $data->username;
            }
            if (property_exists($data, 'password')) {
                $this->password = $data->password;
            }
        }
        // COPY the properties from the array we were passed
        if (is_array($data)) {
            if (isset($data['host'])) {
                $this->host = $data['host'];
            }
            if (isset($data['username'])) {
                $this->username = $data['username'];
            }
            if (isset($data['password'])) {
                $this->password = $data['password'];
            }
        }
    }

    // append to or return the contents of our running log
    public function log($message = '', $level = 0)
    {
        if ($message && $level <= $this->loglevel) {
            $this->messages[] = $message;
        }
        if ($this->loglevel >= 9) {
            echo 'Metaclassing\SSH: '.$message.PHP_EOL;
        }

        return $this->messages;
    }

    // Connect function, attempts to log in
    public function connect()
    {
        if ($this->connected) {
            throw new \Exception('Already connected');
        }
        if (! $this->host) {
            throw new \Exception('Missing host');
        }
        if (! $this->username) {
            throw new \Exception('Missing username');
        }
        if (! $this->password) {
            throw new \Exception('Missing password');
        }
        if (! $this->tcpprobe($this->host, $this->port, 2)) {
            throw new \Exception('Unable to probe port '.$this->port.' on host '.$this->host);
        }
        // Create our PHPSECLIB SSH2 object
        $this->log('Creating phpseclib\Net\SSH2 object for host '.$this->host, 9);
        $this->ssh = new \phpseclib\Net\SSH2($this->host, $this->port);
        $this->log('Setting timeout to '.$this->timeout, 9);
        $this->ssh->setTimeout($this->timeout);
        // Attempt to login
        $this->log('Sending login credentials: '.$this->username.' '.$this->password, 9);
        $this->connected = $this->ssh->login($this->username, $this->password);
        if (! $this->connected) {
            throw new \Exception('Failed to connect to host');
        }
        $this->prompt = '';
        $this->log('Connected, calling findprompt', 9);
        $this->findprompt();

        return $this;
    }

    // Does a simple TCP connection to test that the configured port is listening to our connection attempts
    protected function tcpprobe($host, $port, $timeout = 1)
    {
        if (false == ($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
            return false;
        }
        if (false == (socket_set_nonblock($socket))) {
            return 0;
        }
        $time = time();
        while (! @socket_connect($socket, $host, $port)) {
            $err = socket_last_error($socket);
            if ($err == 115 || $err == 114) {
                if ((time() - $time) >= $timeout) {
                    socket_close($socket);

                    return false;
                }
                sleep(1);
                continue;
            }

            return false;
        }
        socket_close($socket);

        return true;
    }

    // Logic to search initial device output and find the prompt
    protected function findprompt()
    {
        $this->log('entering findprompt', 7);
        if (! $this->connected) {
            throw new \Exception('Error, trying to find prompt but not connected');
        }
        if (! $this->patterns) {
            throw new \Exception('Error, trying to find prompt but no patterns are defined');
        }

        // If we cant find the prompt in 5 calls to read, give up.
        $try = 0;
        while ($try++ < 5) {
            $this->log('looking for prompt, try '.$try, 7);
            $DATA = $this->read('/.*[>|#]/');
            $LINES = explode("\n", $DATA);
            foreach ($LINES as $LINE) {
                foreach ($this->patterns as $PATTERN) {
                    if (preg_match($PATTERN['detect'], $LINE, $MATCH)) {
                        $this->prompt = $MATCH[1];		// I think we found the prompt.
                        $this->pattern = $PATTERN;		// Use this pattern for matching
                        $this->log('I think i found a prompt '.$this->prompt, 7);
                        $this->log('escaped prompt is '.preg_quote($this->prompt, '/'), 7);
                        $this->write("\n");				// So lets send a new line and check.
                        if ($this->read(sprintf($this->pattern['match'], preg_quote($this->prompt, '/')))) {
                            $this->log('Success! '.$this->prompt.' really is a prompt', 7);

                            return $this->prompt;
                        } else {
                            $this->log('Failure! '.$this->prompt.' failed test, attempting to  use next search pattern', 7);
                        }
                    } else {
                        $this->log('Line did not match pattern '.$PATTERN['detect'].': '.$LINE, 7);
                    }
                }
            }
        }
        throw new \Exception('Error, unable to match prompt of host for interactive cli');
    }

    // TODO: this needs to become more graceful
    public function disconnect()
    {
        if (! $this->connected) {
            throw new \Exception('Not connected');
        }
        $this->write("exit\n");
        unset($this->ssh);

        return $this;
    }

    // Blind write function, does not block or wait for a reply
    protected function write($command)
    {
        if (! $this->connected) {
            throw new \Exception('Not connected');
        }
        $this->ssh->write($command);
    }

    // Blocking read function, waits for the expected REGEX to match
    protected function read($expect)
    {
        if (! $this->connected) {
            throw new \Exception('Not connected');
        }

        return $this->ssh->read($expect, \phpseclib\Net\SSH2::READ_REGEX);
    }

    // Blocking command execute wrapper that calls write and then blocking read
    public function exec($command, $maxtries = 4)
    {
        if (! $this->connected) {
            throw new \Exception('Not connected');
        }
        if (! $this->prompt) {
            throw new \Exception('Prompt is unknown');
        }
        $this->ssh->setTimeout($this->timeout);

        $this->write($command."\n");
        $DELIMITER = sprintf($this->pattern['match'], preg_quote($this->prompt, '/'));
        $OUTPUT = '';
        $TRIES = 0;
        while (! preg_match($DELIMITER, $OUTPUT, $REG) && $TRIES++ <= $maxtries) {
            $OUTPUT .= $this->read($DELIMITER);
        }

        return $OUTPUT;
    }
}
