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
use SharkyDog\BLE;
use SharkyDog\MessageBroker as MSGB;
use React\EventLoop\Loop;

function pn($d) {
  print "*** Ex.03: ".print_r($d,true)."\n";
}

// helper to print messages from broker
function pmsg($app,$topic,$msg,$from) {
  pn($app.': from '.$from.' on '.$topic.': '.$msg);
}

BlueZ\Log::level(99);


//
// The SharkyDog\BlueZ\LE\Service class offers an easier way to
// create a scanner and connect it to a message broker (if installed).
//


// same hacks from 01-scanner.php example
function activate_flux_capacitors_super_charging_($lescan) {
  $bt = $lescan->getHCIDump()->getAdapter();

  $resetCustom = function() use($bt) {
    if(!BlueZ\HCI::reset($bt->hci)) {
      return false;
    }
    if(!BlueZ\HCI::hciReset($bt->hci)->ok) {
      return false;
    }
    if(!BlueZ\HCI::setEventMask($bt->hci, 0x3DBFF807FFFBFFFF)->ok) {
      return false;
    }
  };
  $lescan->setResetCustom($resetCustom);

  $resetUSBts = 0;
  $resetUSB = function() use(&$resetUSBts) {
    pn('Reset USB');
    // usb_modeswitch -v 0x0000 -p 0x0000 --reset-usb --quiet
    exec('usb_modeswitch -v '.CONFIG_USB_VENDOR.' -p '.CONFIG_USB_PRODUCT.' --reset-usb --quiet');
    $resetUSBts = time();
  };
  $lescan->on('start-pre', function() use($resetUSB,&$resetUSBts) {
    if($resetUSBts > (time()-60)) {
      pn('USB was recently reset and controller should work');
      return;
    }
    $resetUSB();
    sleep(1);
  });
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

  BlueZ\Log::info('Flux capacitors ready to Rock \'n\' Roll !!!');
}

// the Service class throws exceptions
try {
  // 'host1_ble' is a name meaningful only for the message broker
  // will be appended with '_lescan'
  // so 'host1_ble' becomes 'host1_ble_lescan'
  // optional, if not set and scanner is added to a broker
  // the last 3 bytes of the controller mac will be used (appended 'ddeeff_lescan')
  // or random 3 bytes (in hex) if ScannerBroker() is called before Scanner()
  $svc_ble = new BlueZ\LE\Service('host1_ble');

  $svc_ble->Scanner(CONFIG_BTMAC);
  activate_flux_capacitors_super_charging_($svc_ble->Scanner());

  // can also be used like this to add to a message broker
  //$svc_ble->Scanner(CONFIG_BTMAC, 'lescan/host1', 'tcp://127.0.0.1:12345');
  // or with a local broker
  //$svc_msgb = new MSGB\Service;
  //$svc_ble->Scanner(CONFIG_BTMAC, 'lescan/host1', $svc_msgb->MSGB());

  // or added later to a broker after a check for sharkydog/message-broker package
  if(class_exists(MSGB\Service::class)) {
    $svc_msgb = new MSGB\Service;
    $svc_ble->ScannerBroker('lescan/host1', $svc_msgb->MSGB());
  }


  // testing

  $lescan = $svc_ble->Scanner();
  $index = null;

  $index = $lescan->addListener(function(BLE\Advertisment $adv) use($lescan,&$index) {
    pn($adv->addr.'('.$adv->atyp.'), rssi: '.$adv->rssi);
    $lescan->removeListener($index);
  });

  if(class_exists(MSGB\Service::class)) {
    $msgb_client = new MSGB\Local\Client('viewer1', $svc_msgb->MSGB());
    $msgb_client->on('message', function($topic,$msg,$from) use($msgb_client) {
      $msg = BLE\Advertisment::import($msg);
      pmsg('viewer1', $topic, $msg->addr.'('.$msg->atyp.'), rssi: '.$msg->rssi, $from);
      $msgb_client->send('broker/unsubscribe', $topic);
    });
    $msgb_client->send('broker/subscribe', 'lescan/host1/advertisment');
  }


  // Loop needs to run here to catch errors
  Loop::run();
} catch(\Exception $e) {
  BlueZ\Log::error($e->getMessage());
  Loop::stop();
}
