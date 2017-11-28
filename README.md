# PHP7-SSHWrapper
Friendly wrapper for the amazing PHPSECLIB SSHv2 implementation
# SIMPLE example usage:
```php
# more test.php
<?php
// run: composer require metaclassing/php7-sshwrapper:dev-master
require_once('vendor/autoload.php');

// Some information for how to connect to the device
$deviceinfo = [
    'host'     => '204.123.456.789',
    'username' => 'awesomeUsername',
    'password' => 'SecretP4ssW@rd'
];

// My wrapper throws exceptions, even though phpseclibv2 does not
try {
    // Create a ssh object with our device information
    $sshwrapper = new Metaclassing\SSH( $deviceinfo );

    // order the wrapper to attempt to connect
    // my wrapper has all that handy network device prompt regex logic added!
    $sshwrapper->connect();

    // send the term len 0 command to stop paging output with ---more---
    $sshwrapper->exec('terminal length 0');

    // send an actual command we want the output from
    $command = 'show version';
    $output = $sshwrapper->exec($command);
    echo $output . PHP_EOL;

// catch any exceptions with helpful error hunting
} catch (\Exception $e) {
    echo 'Encountered exception: ' . $e->getMessage() . PHP_EOL;
}

```

Which when run should result in some output like:
```
# php test.php
show version
Cisco IOS Software, 3700 Software (C3745-ADVENTERPRISEK9-M), Version 12.4(15)T12, RELEASE SOFTWARE (fc3)
Technical Support: http://www.cisco.com/techsupport
Copyright (c) 1986-2010 by Cisco Systems, Inc.
Compiled Fri 22-Jan-10 04:24 by prod_rel_team

ROM: System Bootstrap, Version 12.4(13r)T5, RELEASE SOFTWARE (fc1)

LAX-R3745 uptime is 4 years, 14 weeks, 3 days, 21 hours, 31 minutes
System returned to ROM by reload at 19:33:30 CDT Mon Aug 19 2013
System restarted at 19:34:55 CDT Mon Aug 19 2013
System image file is "flash:c3745-adventerprisek9-mz.124-15.T12.bin"

Cisco 3745 (R7000) processor (revision 2.0) with 184320K/77824K bytes of memory.
Processor board ID JMX0804L2Y6
R7000 CPU at 350MHz, Implementation 39, Rev 3.3, 256KB L2, 2048KB L3 Cache
2 FastEthernet interfaces
1 Virtual Private Network (VPN) Module
DRAM configuration is 64 bits wide with parity disabled.
151K bytes of NVRAM.
125440K bytes of ATA System CompactFlash (Read/Write)

Configuration register is 0x2102

LAX-R3745#
```
