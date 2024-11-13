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
use SharkyDog\MessageBroker as MSGB;
use React\EventLoop\Loop;

function pn($d) {
  print "*** Ex.02: ".print_r($d,true)."\n";
}

// helper to print messages from broker
function pmsg($app,$topic,$msg,$from) {
  pn($app.': from '.$from.' on '.$topic.': '.$msg);
}

// Install sharkydog/logger to see all messages
BlueZ\Log::level(99);

//
// The scanner can be connected to the message broker (sharkydog/message-broker package).
// It starts only when there are subscribers for its topic
// and stops when all subscribers have unsubscribed or disconnected.
//
// The scanner and the broker should be run in separate processes
// as commands to the controller are blocking and can affect connection to the broker.
// In this example they will run in the same process for simplicity.
//
// The scanner needs to run as root!
// The broker can run as unprivileged user.
//

// The scanner
if(!($bt = HCI::adapter(CONFIG_BTMAC))) {
  pn('Adapter not found!');
  exit;
}
$hcid = new BlueZ\HCIDump($bt);
$lescan = new Scanner($hcid);
$parser = new BLE\AdvertismentParser;

// Base topic for messages from the scanner
// Advertisments will be published on lescan/host1/advertisment
// When the scanner stops, it will send an empty message on lescan/host1/stop
$baseTopic = 'lescan/host1';

// The message broker
$msgb = new MSGB\Server;

// The scanner will be connected locally
// see sharkydog/message-broker about remote connectors
$msgb_client1 = new MSGB\Local\Client('scanner1', $msgb);

// Broker client to listen for advertisments
$msgb_client2 = new MSGB\Local\Client('viewer1', $msgb);

// Add scanner to broker
//
// A message is sent for every advertisment that passes all filters.
//
// The Advertisment object will be used if there are no filters
// or the last filter returns boolean true or empty value
// Then if the result is an Advertisment object, it will be exported to an array
// Then the result is json encoded and send to the broker if json_encode() succeeds
//
$lescan->addToBroker($msgb_client1, $baseTopic);


// Message received from broker
$msgb_client2->on('message', function($topic,$msg,$from) use($msgb_client2,$parser,$baseTopic) {
  // scanner stopped
  // either no subscribers on advertisment topic
  // or host/hardware problem and restart will not be attempted
  //
  if($topic == $baseTopic.'/stop') {
    pn('viewer1: '.$from.' stopped');
    return;
  }

  // viewer1 is subscribing to advertisment and stop topics
  // so broker will only send those messages to this client
  // above we handled stop, so this must be advertisment

  // an advertisment message is json encoded export of SharkyDog\BLE\Advertisment
  // if not changed by filters
  //
  // when json decoded, data will be in hex form
  // decode the message and print to see the structure
  //
  //$msg = json_decode($msg,true);
  //pn($msg);

  // or import it back as SharkyDog\BLE\Advertisment
  // data will be decoded to binary string
  // a string $msg is treated as json encoded
  // an array $msg is treated as returned by Advertisment->export()
  // if $msg is already SharkyDog\BLE\Advertisment, it will be returned as is
  // null is returned if none of the above or some elements of the advertisment are missing
  //
  if(!($msg = BLE\Advertisment::import($msg))) {
    // maybe investigate why we received invalid message
    return;
  }

  pmsg('viewer1', $topic, $msg->addr.'('.$msg->atyp.'), rssi: '.$msg->rssi, $from);

  // uncomment to see imported message
  //pn($msg);

  // and/or output from the parser (an array)
  // the parser will do the same advertisment import as above
  // so it can be given json encoded string, an array or Advertisment object
  //pn($parser($msg));

  // for this example only one message is needed
  // unsubscribe and as there are no other subscribers
  // scanner will stop
  // and as there are no listening sockets in the broker (remote listeners or clients)
  // script will exit
  $msgb_client2->send('broker/unsubscribe', $topic);
});

// subscribe
// only subscribers on lescan/host1/advertisment will start the scanner
// subscribing for stop will not
Loop::addTimer(0.1, function() use($baseTopic,$msgb_client2) {
  $msgb_client2->send('broker/subscribe', $baseTopic.'/advertisment');
  $msgb_client2->send('broker/subscribe', $baseTopic.'/stop');
});


// Add the parser to the broker
// parse lescan/host1/advertisment messages
// and publish json encoded array
// on advparser/parsedadv
$parser->addToBroker(
  $msgb,
  $baseTopic.'/advertisment',
  'advparser/parsedadv'
);
// Broker client to listen for parser messages
$msgb_client3 = new MSGB\Local\Client('viewer2', $msgb);
$msgb_client3->on('message', function($topic,$msg,$from) use($msgb_client3) {
  pmsg('viewer2', $topic, '', $from);
  pn(json_decode($msg,true));
  $msgb_client3->send('broker/unsubscribe', 'advparser/parsedadv');
});
Loop::addTimer(0.1, function() use($msgb_client3) {
  $msgb_client3->send('broker/subscribe', 'advparser/parsedadv');
});


//
// same hacks from 01-scanner.php example
//
// uncomment to run event loop here and skip hacks
//exit;
//

$resetCustom = function() use($bt) {
  if(!HCI::reset($bt->hci)) {
    return false;
  }
  if(!HCI::hciReset($bt->hci)->ok) {
    return false;
  }
  if(!HCI::setEventMask($bt->hci, 0x3DBFF807FFFBFFFF)->ok) {
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
