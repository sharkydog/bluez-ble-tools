<?php
namespace SharkyDog\BlueZ\LE\Event;
use SharkyDog\BlueZ\HCIDump;

class LEMeta extends HCIDump\Event {
  public $code = '3E';
  public $subCode;

  private $_sevt_hnd = [];

  public function addSubeventHandler(self $evt) {
    $subc = strtoupper($evt->subCode);
    if(isset($this->_sevt_hnd[$subc])) return;
    $this->_sevt_hnd[$subc] = $evt;
  }

  protected function _parse(string $code, string $params, HCIDump $hcid): ?self {
    $subc = bin2hex($params[0]);

    if(isset($this->_sevt_hnd[$subc])) {
      $evt = $this->_sevt_hnd[$subc]->_parse($code, $params, $hcid);
    } else {
      $evt = new self;
      $evt->subCode = $subc;
    }

    return $evt;
  }
}
