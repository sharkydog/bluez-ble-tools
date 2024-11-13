<?php

// include this file or add your autoloader


// and import your local config

// controller mac address
if(!defined('CONFIG_BTMAC')) {
  define('CONFIG_BTMAC', 'AA:BB:CC:DD:EE:FF');
}

// usb vendor id and product id for usb_modeswitch if needed
// use lsusb to find
if(!defined('CONFIG_USB_VENDOR')) {
  define('CONFIG_USB_VENDOR', '0x0000');
}
if(!defined('CONFIG_USB_PRODUCT')) {
  define('CONFIG_USB_PRODUCT', '0x0000');
}


use SharkyDog\BlueZ;
use SharkyDog\BlueZ\LE\HCI;
use SharkyDog\BlueZ\LE\Scanner;
use SharkyDog\BLE;
use React\EventLoop\Loop;

function pn($d) {
  print "*** Ex.01: ".print_r($d,true)."\n";
}

// Install sharkydog/logger to see all messages
BlueZ\Log::level(99);

//
// The scanner uses hcitool and hcidump to do active and passive scans
// Needs to run as root!
//
// SharkyDog\BLE\Advertisment
// SharkyDog\BLE\AdvData
// SharkyDog\BLE\AdvertismentParser
// come from sharkydog/ble-advertisment package
//

// MAC address of the controller
// hci interface name can also be used,
// but these tend to change numbers sometimes
$btmac = CONFIG_BTMAC;

// Get an instance of the controller
// A simple object that holds mac address and interface name (hciX)
if(!($bt = HCI::adapter($btmac))) {
  pn('Adapter not found!');
  exit;
}

// By default the hcidump process will auto start
// when there are listeners, like our scanner
// and stop when there aren't
$hcid = new BlueZ\HCIDump($bt);

// And the scanner
$lescan = new Scanner($hcid);

// Scan interval and scan window in miliseconds, default 10ms each
// range: 2.5ms - 10.24s in 0.625ms steps
// values will be rounded to the nearest step
// scan window will be set to scan interval if bigger than it
//$lescan->setScanParams(10, 10);

// Active scan (true, default) or passive scan (false)
//$lescan->setScanActive(true);

// Filter duplicates, default false
//$lescan->setFilterDuplicates(false);

// Advertisment filter
// Each advertisment passes through all filters before being send to the listeners.
// If a filter returns false, processing is stopped and advertisment discarded.
// Any other value will be passed to the next filter.
// $prev in the first filter will be null.
//
$lescan->addFilter(function(BLE\Advertisment $adv, $prev) {
  if($adv->atyp != BLE\Advertisment::ADDR_PUB) {
    // only allow beacons with public address
    //return false;
  }
  // listeners will see the result from the last filter
  // can be null as listeners also receive the Advertisment object
  //return $adv;
});

// Only scan responses (active scans)
//$lescan->addFilter(fn($adv) => $adv->etyp == BLE\Advertisment::SCAN_RSP);


// The advertisment parser makes a more readable output
// and parses few common advertising data types,
// but returns an array, not SharkyDog\BLE\Advertisment object.
// The output can be changed further with additional filters.
// More on this in sharkydog/ble-advertisment package example.
$parser = new BLE\AdvertismentParser;

// Could be used as a scanner filter.
// $parser returns null on rejected advertisment, not false
//$lescan->addFilter(fn($adv) => $parser($adv) ?? false);


// Need this little hack to import the listener id as reference
// so it can be removed inside itself
$index = null;

// Add a listener, scanner will start on next event loop tick
$index = $lescan->addListener(function(BLE\Advertisment $adv, $filtered) use($lescan,$parser,&$index) {
  // $filtered is the result from the last filter
  // or null if there are no filters

  // print to see the structure
  // AdvData is stored as type=>value pairs with type in hex and binary string value
  // transformed for debugging when Advertisment is passed to print_r() and var_dump()
  //pn($adv);
  // and/or output from the parser (an array)
  //pn($parser($adv));

  pn($adv->addr.'('.$adv->atyp.'), rssi: '.$adv->rssi);

  // for this example only one Advertisment is needed
  // remove the listener and as there are no other listeners
  // scanner will stop and script will exit
  $lescan->removeListener($index);
});


//
// End of "a walk in the park" section
//


//
// Why are bluetooth and printers always so hard to get working?
//
//
// This part will refer to some raw hci commands
// https://www.bluetooth.com/wp-content/uploads/Files/Specification/HTML/Core-54/out/en/host-controller-interface/host-controller-interface-functional-specification.html#UUID-ee8bbec6-ebdd-b47d-41d5-a7e655cad979
// LE commands are in 7.8
// HCI_Reset and HCI_Set_Event_Mask are 7.3.2 and 7.3.1
//
// Apparently linux kernel or the deprecated bluez tools (hcitool,hciconfig,hcidump)
// may or may not have a bug or a feature
// that stops ble scanning or something else that stops ble scanning
// after some time, in my tests about 20min.
// That may or may not depend on distribution, kernel version,
// distro config, kernel config, controllers, drivers, firmware.
//
// Running 'hcitool -i hciX lescan --duplicates'
// will stop producing output after that time
// but may not exit, no error is seen
// and no indication in hcidump that scanning was stopped.
//
// Situation with dbus might be different as that is the recommended api for all things bluetooth.
// PHP however doesn't have (yet) an easy way to talk to dbus.
//
// The solution is to reset the controller every xx minutes.
// What kind of reset is needed, is a different matter.
//
// A simple 'set scan parameters' hci command was enough for one of my controllers
// to get advertisments flowing again, for others it wasn't.
// Another factor is we do not exactly know the state of the controller
// before the scan is started or even while it's running.
// Everything might be normal, but no advertisments are comming
// simply because there are no beacons nearby or are too weak.
//
// Doing a bunch of checks at regular interval could be expensive
// and might disrupt or stop the scanner, and it may or may not need a reset anyway.
//
// So, by default on start and on every 10min, this scanner will:
//  - run 'hciconfig hciX reset'
//  - send HCI_LE_Set_Scan_Parameters command
//  - send HCI_LE_Set_Scan_Enable command to start scanning
//
// If a custom reset callback is set:
//  - run the callback instead of hciconfig reset
//  - send HCI_LE_Set_Scan_Enable command to stop scanning, silence and ignore any error
//  - send HCI_LE_Set_Scan_Parameters command
//  - send HCI_LE_Set_Scan_Enable command to start scanning
//
// If any step fails, the sequence will be aborted,
// scanner will be stopped and restart will be attempted once after 5 seconds.
// This reset sequence will be executed on every start
// and every reset interval during a running scan.
//
//
// Reset interval and restart attempts can be configured
//
// Set reset interval in seconds, minimum 60s, default 10min
// for now this can't be turned off
// set to something high if you don't need it
//$lescan->setReset(600);
//
// Set restart attempts and delay, default 1 attempt after 5s
// set restart (1st parameter) to 0 to disable
// delay (2nd parameter) minimum is 1s
//$lescan->setRestart(1, 5);
//
//
// Custom reset callback
// One of my controllers needed a special reset
//  - 'hciconfig hciX reset' brings the interface up
//     but after it, controller refused to accept scan parameters
//  - send HCI_Reset command
//     now HCI_LE_Set_Scan_Parameters and HCI_LE_Set_Scan_Enable work
//     but no advertisments
//  - send HCI_Set_Event_Mask command with all bits
//     and now I get results
//
// HCI_Set_Event_Mask is needed here because HCI_Reset
// probably resets it to HCI default (bits 0-44),
// probably along with other settings set by hciconfig reset.
//
// If the callback returns false, reset is considered failed.
// The callback may do nothing and return nothing,
// in which case it will (if set) only remove the default 'hciconfig hciX reset'
//

// In this callback
//  - HCI::reset() runs 'hciconfig hciX reset'
//  - HCI::hciReset() sends HCI_Reset command using
//     hcitool -i hciX cmd 0x3 0x3
//  - HCI::setEventMask() sends HCI_Set_Event_Mask command using
//     hcitool -i hciX cmd 0x3 0x1 0xff 0xff 0xfb 0xff 0x7 0xf8 0xbf 0x3d
//
$resetCustom = function() use($bt) {
  if(!HCI::reset($bt->hci)) {
    return false;
  }

  if(!HCI::hciReset($bt->hci)->ok) {
    return false;
  }

  //$mask = 0x00001FFFFFFFFFFF; // hci default, bits 0-44, (1 << 45) - 1
  //$mask = $mask | (1 << 61); // set bit 61, LE Meta event
  //$mask = 0x20001FFFFFFFFFFF; // hci default + bit 61
  $mask = 0x3DBFF807FFFBFFFF; // all without reserved bits, see HCI specs

  if(!HCI::setEventMask($bt->hci, $mask)->ok) {
    return false;
  }
};

// uncomment to run event loop here and skip hacks
//exit;

// set the custom reset callback above
$lescan->setResetCustom($resetCustom);

//
// Apart from needing a special reset,
// my special controller also needs a hard reset sometimes.
//
// Debian
// apt install usb-modeswitch
//
// Don't use this in a reset callback
// it will kill the hcidump process and trigger a stop
// and will also trigger restart if there are attempts left.
//

// Do not reset USB too often
$resetUSBts = 0;
$resetUSB = function() use(&$resetUSBts) {
  pn('Reset USB');
  // usb_modeswitch -v 0x0000 -p 0x0000 --reset-usb --quiet
  exec('usb_modeswitch -v '.CONFIG_USB_VENDOR.' -p '.CONFIG_USB_PRODUCT.' --reset-usb --quiet');
  $resetUSBts = time();
};

// uncomment to run event loop here and skip hacks
//exit;

// This event is emitted before hcidump is started
// and before any command is send to the controller.
//
$lescan->on('start-pre', function() use($resetUSB,&$resetUSBts) {
  if($resetUSBts > (time()-60)) {
    pn('USB was recently reset and controller should work');
    return;
  }
  $resetUSB();
  sleep(1);
});

// This event is emitted after scanner stops and after hcidump is stopped.
// A stop may be normal or due to an error, like a failed reset or start.
// If $restart is true, a restart attempt will be made after the event
// and is a good indication that something went wrong.
//
$lescan->on('stop-pre', function(bool $restart) use($resetUSB,&$resetUSBts) {
  if(!$restart) {
    return;
  }
  if($resetUSBts > (time()-60)) {
    pn('USB was recently reset and controller still doesn\'t work');
    return;
  }
  $resetUSB();
});
