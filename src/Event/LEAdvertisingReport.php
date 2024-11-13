<?php
namespace SharkyDog\BlueZ\LE\Event;
use SharkyDog\BlueZ\HCIDump;
use SharkyDog\BLE\Advertisment;
use SharkyDog\BLE\AdvData;

class LEAdvertisingReport extends LEMeta {
  public $subCode = '02';
  public $numReports;
  public $advertisments = [];

  public function filter(callable $callback): callable {
    $subCode = $this->subCode;
    return function($evt) use($callback,$subCode) {
      if($evt->subCode != $subCode) return;
      $callback($evt);
    };
  }

  protected function _parse(string $code, string $params, HCIDump $hcid): ?LEMeta {
    if($params[0] != "\x02") {
      return parent::_parse($code, $params, $hcid);
    }

    $evt = new self;
    $evt->numReports = ord($params[1]);
    $evt->advertisments = [];

    $rcnt = $evt->numReports;
    $reps = &$evt->advertisments;
    $data = substr($params, 2);
    $dlen = [];
    $params = '';

    $roff = 0;
    for($rnum = 0; $rnum < $rcnt; $rnum++) {
      $reps[$rnum] = new Advertisment;
      $reps[$rnum]->setEventType(ord($data[$rnum]));
      $reps[$rnum]->setAddressType(ord($data[$rcnt+$rnum]));

      $addr = strrev(substr($data,(2*$rcnt)+($rnum*6),6));
      $addr = implode(':',str_split(bin2hex($addr),2));
      $reps[$rnum]->setAddress($addr);

      $dlen[$rnum] = ord($data[(8*$rcnt)+$rnum]);
      $roff += $dlen[$rnum];
    }

    $doff = 0;
    for($rnum = 0; $rnum < $rcnt; $rnum++) {
      $adata = substr($data,(9*$rcnt)+$doff,$dlen[$rnum]);
      $doff += $dlen[$rnum];

      $reps[$rnum]->setRSSI(unpack('crssi',$data[(9*$rcnt)+$roff+$rnum])['rssi']);
      AdvData::parseBin($adata, $reps[$rnum]->data);
    }

    return $evt;
  }
}
