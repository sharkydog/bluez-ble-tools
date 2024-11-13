<?php
namespace SharkyDog\BlueZ\LE;
use SharkyDog\BlueZ;
use SharkyDog\MessageBroker as MSGB;
use React\EventLoop\Loop;

class Service {
  private $_name;
  private $_adapters = [];
  private $_hcid = [];
  private $_lescan;
  private $_lescan_msgb;

  final public function __construct(string $name='') {
    $this->_name = $name;

    register_shutdown_function(function() {
      foreach($this->_hcid as $hcid) {
        $hcid->stop(true);
      }
    });

    Loop::futureTick(function() {
      $this->_onStart();
    });
  }

  private function _onStart() {
    if($this->_lescan_msgb) {
      $this->_lescan_msgb->connect();
    }
  }

  private function _brokerClient($name, $brc) {
    if(is_string($brc)) {
      $url = parse_url($brc);

      if(!isset($url['scheme'],$url['host'])) {
        throw new \Exception('Broker client: Can not parse url');
      }

      $scheme = strtolower($url['scheme']);

      if($scheme == 'tcp') {
        if(!isset($url['port'])) {
          throw new \Exception('Broker client: Port is required for TCP url');
        }
        $brc = new MSGB\TCP\Client($url['host'], $url['port'], $name);
      }
      else if($scheme == 'ws' || $scheme == 'wss') {
        $brc = new MSGB\WebSocket\Client($brc, $name);
      }
      else {
        throw new \Exception('Broker client: Unknown url scheme, supported are tcp, ws and wss');
      }
    }
    else if($brc instanceOf MSGB\Server) {
      $brc = new MSGB\Local\Client($name, $brc);
    }
    else if(!($brc instanceOf MSGB\ClientInterface)) {
      throw new \Exception('Broker client: Must be an url or instance of '.MSGB\Server::class.' or '.MSGB\ClientInterface::class);
    }

    return $brc;
  }

  public function Adapter($hci): BlueZ\Adapter {
    if($hci instanceOf BlueZ\Adapter) {
      return $hci;
    } else if(!is_string($hci)) {
      throw new \Exception('Bluetooth adapter must be a string or instance of '.BlueZ\Adapter::class);
    }

    if(empty($this->_adapters)) {
      if(($adapters = BlueZ\HCI::adapters()) === null) {
        throw new \Exception('No bluetooth adapters found');
      }

      foreach($adapters as $adapter) {
        $this->_adapters[$adapter->hci] = $adapter;
        $this->_adapters[strtolower($adapter->mac)] = $adapter;
      }
    }

    $hci = strtolower($hci);

    if(!isset($this->_adapters[$hci])) {
      throw new \Exception('Bluetooth adapter '.$hci.' not found');
    }

    return $this->_adapters[$hci];
  }

  public function HCID($hci): BlueZ\HCIDump {
    $hci = $this->Adapter($hci);

    if(!isset($this->_hcid[$hci->hci])) {
      $this->_hcid[$hci->hci] = new BlueZ\HCIDump($hci);
    }

    return $this->_hcid[$hci->hci];
  }

  public function Scanner($hci=null, ?string $topic=null, $msgb=null): ?Scanner {
    if($hci === null || $this->_lescan) {
      return $this->_lescan;
    }

    $this->_lescan = new Scanner($this->HCID($hci));
    $this->ScannerBroker($topic, $msgb);

    return $this->_lescan;
  }

  public function ScannerBroker(?string $topic=null, $msgb=null): ?MSGB\ClientInterface {
    if($topic === null) {
      return $this->_lescan_msgb;
    }
    if(!interface_exists('SharkyDog\MessageBroker\ClientInterface')) {
      throw new \Exception('Scanner: Broker not found, install sharkydog/message-broker');
    }

    $scanner = $this->Scanner();

    if(!$this->_lescan_msgb) {
      if(!$this->_name && $scanner) {
        $name = substr($scanner->getHCIDump()->getAdapter()->mac, -8);
        $name = strtolower(str_replace(':','',$name));
      } else {
        $name = $this->_name ?: bin2hex(random_bytes(3));
      }
      $this->_lescan_msgb = $this->_brokerClient($name.'_lescan', $msgb);
    } else if($msgb) {
      throw new \Exception('Scanner: Can not add to another broker through the Service class');
    }

    if($scanner) {
      if(!$topic) {
        throw new \Exception('Scanner: Broker topic is empty');
      }
      $scanner->addToBroker($this->_lescan_msgb, $topic);
    }

    return $this->_lescan_msgb;
  }
}
