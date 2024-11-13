<?php
namespace SharkyDog\BlueZ\LE;
use SharkyDog\BlueZ;
use SharkyDog\BlueZ\LE\Event\LEAdvertisingReport;
use SharkyDog\BLE\Advertisment;
use SharkyDog\MessageBroker as MSGB;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use React\EventLoop\Loop;

class Scanner {
  use PrivateEmitterTrait;

  private $_hci;
  private $_hcid;
  private $_hcid_idx;
  private $_timer;
  private $_reset = 600;
  private $_resetCustom;
  private $_restart = 5;
  private $_restartAttempts = 1;
  private $_restartAttempt = 0;
  private $_stopped = false;
  private $_p_scan_interval = 10;
  private $_p_scan_window = 10;
  private $_p_active = true;
  private $_p_filter_dupl = false;
  private $_filters = [];
  private $_listeners = [];

  public function __construct(BlueZ\HCIDump $hcid) {
    $this->_hci = $hcid->getAdapter();
    $this->_hcid = $hcid;

    $this->_hcid->on('exit', function($code,$term) {
      if(!$this->_hcid_idx) return;
      BlueZ\Log::error('LEScanner: HCIDump stopped');
      $this->_stop();
    });
  }

  public function getHCIDump(): BlueZ\HCIDump {
    return $this->_hcid;
  }

  public function setScanParams(float $interval_ms, float $window_ms) {
    $this->_p_scan_interval = $interval_ms;
    $this->_p_scan_window = $window_ms;
  }

  public function setScanActive(bool $p) {
    $this->_p_active = $p;
  }

  public function setFilterDuplicates(bool $p) {
    $this->_p_filter_dupl = $p;
  }

  public function setRestart(int $attempts, int $delay) {
    $this->_restartAttempts = max(0,$attempts);
    $this->_restart = $this->_restartAttempts ? max(1,$delay) : 0;
  }

  public function setReset(int $interval) {
    $this->_reset = max(60,$interval);
  }

  public function setResetCustom(?callable $callback) {
    $this->_resetCustom = $callback;
  }

  public function addFilter(callable $filter) {
    $this->_filters[] = $filter;
  }

  public function addListener(callable $listener): int {
    $this->_listeners[] = $listener;
    $index = array_key_last($this->_listeners);
    $this->_tickListeners();
    return $index;
  }

  public function removeListener(int $index) {
    unset($this->_listeners[$index]);
    if(empty($this->_listeners)) {
      $this->_tickListeners();
    }
  }

  public function addToBroker(MSGB\ClientInterface $msgb, string $basetopic) {
    $index = null;

    $onAdv = function($adv,$filtered) use($msgb,$basetopic) {
      if($filtered !== true && !empty($filtered)) {
        $adv = $filtered;
      }
      if($adv instanceOf Advertisment) {
        $adv = $adv->export(true);
      }
      if(!($adv = json_encode($adv))) {
        return;
      }
      $msgb->send($basetopic.'/advertisment', $adv);
    };

    $msgb->on('message', function($topic,$msg,$from) use(&$index,$onAdv) {
      if($topic == 'broker/subscribers') {
        list($topic,$count) = explode(' ',$msg);
        if((int)$count) {
          if($index === null) {
            $index = $this->addListener($onAdv);
          }
        } else {
          if($index !== null) {
            $this->removeListener($index);
            $index = null;
          }
        }
        return;
      }
    });

    $msgb->on('close', function() use(&$index) {
      if($index !== null) {
        $this->removeListener($index);
        $index = null;
      }
    });

    $msgb->on('open', function() use($msgb,$basetopic) {
      $msgb->send('broker/subscribers', $basetopic.'/advertisment');
    });

    $this->on('stop', function() use($msgb,$basetopic) {
      $msgb->send($basetopic.'/stop', '');
    });
  }

  public function start(bool $force=false) {
    $this->_stopped = false;

    if($this->_hcid_idx) {
      return;
    } else if($this->_timer) {
      Loop::cancelTimer($this->_timer);
      $this->_timer = null;
      $this->_start();
    } else if($force || !empty($this->_listeners)) {
      $this->_start();
    }
  }

  public function stop() {
    if($this->_hcid_idx) {
      $this->_stop();
    } else if($this->_timer) {
      Loop::cancelTimer($this->_timer);
      $this->_timer = null;
    }

    $this->_stopped = true;
  }

  private function _reset() {
    if($this->_resetCustom) {
      if(($this->_resetCustom)() === false) {
        return false;
      }
      HCI::cmdSilenceNext();
      HCI::leSetScanEnable($this->_hci->hci, false, false);
    } else {
      if(!HCI::reset($this->_hci->hci)) {
        return false;
      }
    }

    $active = $this->_p_active;
    $int_ms = $this->_p_scan_interval;
    $wdw_ms = $this->_p_scan_window;
    if(!HCI::leSetScanParams($this->_hci->hci, $active, $int_ms, $wdw_ms)->ok) {
      return false;
    }

    $filter_dupl = $this->_p_filter_dupl;
    if(!HCI::leSetScanEnable($this->_hci->hci, true, $filter_dupl)->ok) {
      return false;
    }

    return true;
  }

  private function _start() {
    if($this->_hcid_idx || $this->_timer || $this->_stopped) {
      return;
    }

    $this->_emit('start-pre');

    if($this->_hcid_idx || $this->_timer || $this->_stopped) {
      return;
    }

    $this->_hcid_idx = $this->_hcid->onEvent(function($evt) {
      $this->_onLEAdvRep($evt);
    }, new LEAdvertisingReport);

    $this->_timer = Loop::addPeriodicTimer($this->_reset, function() {
      $this->_timer = null;

      BlueZ\Log::debug('LEScanner: Reset', 'lescan','reset');
      $reset = $this->_reset();
      $this->_emit('reset', [$reset]);

      if(!$reset) $this->_stop();
      else $this->_restartAttempt = 0;
    });

    BlueZ\Log::debug('LEScanner: Start', 'lescan','start');
    $start = $this->_reset();
    $this->_emit('start', [$start]);

    if(!$start) {
      $this->_stop();
    } else {
      BlueZ\Log::debug('LEScanner: Started', 'lescan','started');
      $this->_restartAttempt = 0;
    }
  }

  private function _stop() {
    if(!$this->_hcid_idx) {
      return;
    }

    if($this->_timer) {
      Loop::cancelTimer($this->_timer);
      $this->_timer = null;
    }

    HCI::cmdSilenceNext();
    HCI::leSetScanEnable($this->_hci->hci, false, false);

    $this->_hcid->removeEventListener($this->_hcid_idx);
    $this->_hcid_idx = null;

    if(!empty($this->_listeners) && $this->_restart && !$this->_stopped) {
      $restart = ($this->_restartAttempt++) < $this->_restartAttempts;
    } else {
      $restart = false;
    }

    $this->_emit('stop-pre', [$restart]);

    if($this->_hcid_idx) {
      return;
    }

    BlueZ\Log::debug('LEScanner: Stop', 'lescan','stop');

    if(!empty($this->_listeners) && !$restart) {
      $dev = $this->_hci->hci.','.$this->_hci->mac;
      BlueZ\Log::error('LEScanner: Can not start on '.$dev, 'lescan','stop');
    }

    if(!empty($this->_listeners) && $this->_restart && !$this->_stopped) {
      $restart = $restart;
    } else {
      $restart = false;
    }

    if($restart) {
      $this->_timer = Loop::addTimer($this->_restart, function() {
        $this->_timer = null;
        $this->_start();
      });
    } else {
      $this->_emit('stop');
    }
  }

  private function _tickListeners() {
    static $ticked = false;
    if($ticked) return;

    Loop::futureTick(function() use(&$ticked) {
      $ticked = false;

      if(empty($this->_listeners)) {
        $this->_stop();
      } else {
        $this->_start();
      }
    });

    $ticked = true;
  }

  private function _onLEAdvRep($evt) {
    foreach($evt->advertisments as $adv) {
      $ret = null;

      foreach($this->_filters as $filter) {
        if(($ret = $filter($adv,$ret)) === false) {
          continue 2;
        }
      }

      foreach($this->_listeners as $listener) {
        $listener($adv,$ret);
      }
    }
  }
}
