<?php
namespace SharkyDog\BlueZ\LE;
use SharkyDog\BlueZ;
use SharkyDog\BlueZ\HCI\Error;
use SharkyDog\BlueZ\HCI\CommandResult;

// HCI commands and events
// LE Controller commands
// https://www.bluetooth.com/wp-content/uploads/Files/Specification/HTML/Core-54/out/en/host-controller-interface/host-controller-interface-functional-specification.html#UUID-0f07d2b9-81e3-6508-ee08-8c808e468fed

class HCI extends BlueZ\HCI {
  public static function leSetScanParams(string $hci, bool $active, float $interval_ms, float $window_ms): CommandResult {
    $int_dec = round($interval_ms / 0.625);
    $int_dec = min(max(0x04, $int_dec), 0x4000);
    $wdw_dec = round($window_ms / 0.625);
    $wdw_dec = min(max(0x04, $wdw_dec), $int_dec);

    $params = [];
    $params[] = (int)$active;
    $params = array_merge($params, unpack('C*', pack('v', $int_dec)));
    $params = array_merge($params, unpack('C*', pack('v', $wdw_dec)));
    $params[] = 0; // Own_Address_Type
    $params[] = 0; // Scanning_Filter_Policy

    $ret = self::cmd($hci, 0x08, 0x000B, ...$params);
    return self::cmdRet($ret, 0, __FUNCTION__);
  }

  public static function leSetScanEnable(string $hci, bool $enable, bool $filter_duplicates): CommandResult {
    $ret = self::cmd($hci, 0x08, 0x000C, (int)$enable, (int)$filter_duplicates);
    return self::cmdRet($ret, 0, __FUNCTION__.'('.(int)$enable.')');
  }

  public static function leSetAdvertisingParams(string $hci, float $interval_min_ms, float $interval_max_ms, int $adv_type=3): CommandResult {
    $int_min_dec = round($interval_min_ms / 0.625);
    $int_min_dec = min(max(0x20, $int_min_dec), 0x4000);
    $int_max_dec = round($interval_max_ms / 0.625);
    $int_max_dec = min(max($int_min_dec, $int_max_dec), 0x4000);

    $params = [];
    $params = array_merge($params, unpack('C*', pack('v', $int_min_dec)));
    $params = array_merge($params, unpack('C*', pack('v', $int_max_dec)));
    $params[] = in_array($adv_type, [0,2,3]) ? $adv_type : 0x03;
    $params[] = 0; // Own_Address_Type
    $params[] = 0; // Peer_Address_Type
    $params = array_merge($params, [0,0,0,0,0,0]); // Peer_Address
    $params[] = 7; // Advertising_Channel_Map
    $params[] = 0; // Advertising_Filter_Policy

    $ret = self::cmd($hci, 0x08, 0x0006, ...$params);
    return self::cmdRet($ret, 0, __FUNCTION__);
  }

  public static function leSetAdvertisingData(string $hci, string $data): CommandResult {
    if(($dlen=strlen($data)) > 31) {
      throw new \Exception('AdvertisingData too big');
    }

    $data = str_pad($data, 31, "\x0");
    $params = [$dlen];
    $params = array_merge($params, array_map('ord',str_split($data)));

    $ret = self::cmd($hci, 0x08, 0x0008, ...$params);
    return self::cmdRet($ret, 0, __FUNCTION__);
  }

  public static function leSetScanResponseData(string $hci, string $data): CommandResult {
    if(($dlen=strlen($data)) > 31) {
      throw new \Exception('ScanResponseData too big');
    }

    $data = str_pad($data, 31, "\x0");
    $params = [$dlen];
    $params = array_merge($params, array_map('ord',str_split($data)));

    $ret = self::cmd($hci, 0x08, 0x0009, ...$params);
    return self::cmdRet($ret, 0, __FUNCTION__);
  }

  public static function leSetAdvertisingEnable(string $hci, bool $enable): CommandResult {
    $ret = self::cmd($hci, 0x08, 0x000A, (int)$enable);
    return self::cmdRet($ret, 0, __FUNCTION__);
  }
}
