<?php
require_once('rest.inc.php');

function getTop20Id()
{
  $url = 'https://www.zulutrade.com/zulutrade-client/v2/api/providers/performance/search';
  $dataJson = '{"timeFrame":10000,"minPips":0.1,"tradingExotics":false,"minWinTradesPercentage":80,"sortBy":"amountFollowing","sortAsc":false,"size":20,"page":0,"flavor":"global"}';
  $top20Obj = RestCurl::post($url, $dataJson);
  $top20Data = $top20Obj['data']->result;
  $top20IdArray = [];
  foreach ($top20Data as $d) {
    $tmp['id'] = ($d->trader->providerId);
    $top20IdArray[] = $tmp;
  }

  file_put_contents('data/0_Top20ID.txt', json_encode($top20IdArray));
}

function getDataFromId()
{
  $top20ID = json_decode(file_get_contents('data/0_Top20ID.txt'), true);
  $returnArray = [];
  foreach ($top20ID as $index => $id) {
    $url = 'https://www.zulutrade.com/zulutrade-client/traders/api/providers/' . $id['id'] . '/openTrades';
    $top20Obj = RestCurl::get($url);
    $top20Data = $top20Obj['data'];
    if (!$top20Obj['data']) continue;
    foreach ($top20Data as $data) {
      @$tmp['currency'] = $data->currencyName;
      @$tmp['dateTime'] = $data->dateTime;
      @$tmp['stdLotds'] = $data->stdLotds;
      @$tmp['tradeType'] = $data->tradeType;
      @$tmp['entryRate'] = $data->entryRate;
      @$tmp['pipMultiplier'] = $data->pipMultiplier;
      $returnArray[] = $tmp;
    }
  }

  file_put_contents('data/1_Top20Data.txt', json_encode($returnArray));
}

function formatDataByCurrency()
{
  $top20Data = json_decode(file_get_contents('data/1_Top20Data.txt'), true);
  $listCurrency = [];
  $returnArray = [];
  foreach ($top20Data as $index => $data) {
    if (((time() * 1000) - $data['dateTime']) > (5 * 24 * 60 * 60 * 1000)) continue;
    $currency = str_replace('/', '', $data['currency']);
    $listCurrency[$currency] = true;
    $tmp = [];
    $tmp['stdLotds'] = $data['stdLotds'];
    $tmp['entryRate'] = $data['entryRate'];
    $tmp['pipMultiplier'] = $data['pipMultiplier'];
    $returnArray[$currency][$data['tradeType']][] = $tmp;
  }
  $listCurrency = array_keys($listCurrency);
  file_put_contents('data/2_ListCurrency.txt', json_encode($listCurrency));
  file_put_contents('data/3_FormatDataByCurrency.txt', json_encode($returnArray));
}

function getCurrentPrice()
{
  $listCurrency = json_decode(file_get_contents('data/2_ListCurrency.txt'), true);
  $listCurrency = implode(',', $listCurrency);
  $priceObj = RestCurl::get('https://forex.1forge.com/1.0.2/quotes?pairs=' . $listCurrency . '&api_key=Jf3MVdUSbaHCVy1Tn9EFMAUdkosZhbJj');
  $returnArray = [];
  foreach ($priceObj['data'] as $d) {
    $tmp['price'] = $d->price;
    $tmp['bid'] = $d->bid;
    $tmp['ask'] = $d->ask;
    $returnArray[$d->symbol] = $tmp;
  }

  file_put_contents('data/4_CurrentPrice.txt', json_encode($returnArray));
}

function zipDataByCurrency()
{
  $formatDataByCurrency = json_decode(file_get_contents('data/3_FormatDataByCurrency.txt'), true);
  $currentPrice = json_decode(file_get_contents('data/4_CurrentPrice.txt'), true);
  $returnArray = [];
  foreach ($formatDataByCurrency as $currency => $dataObj) {
    foreach ($dataObj as $type => $data) {
      $totalLotByType = 0;
      $totalPriceByType = 0;
      $pipMultiplier = 0;
      foreach ($data as $order) {
        $totalLotByType += $order['stdLotds'];
        $totalPriceByType += ($order['stdLotds'] * $order['entryRate']);
        $pipMultiplier = $order['pipMultiplier'];
      }
      if ($totalLotByType < 1) continue;
      $averagePrice = ($totalPriceByType / $totalLotByType);
      $averagePrice = number_format($averagePrice, strlen($pipMultiplier));
      $floatingPips = ($currentPrice[$currency]['price'] - $averagePrice) * $pipMultiplier;
      $floatingPips = ($type == 'BUY') ? $floatingPips : $floatingPips * -1;
      $floatingPips = number_format($floatingPips, 1);
      $tmp['currency'] = $currency;
      $tmp['tradeType'] = $type;
      $tmp['stdLotds'] = $totalLotByType;
      $tmp['entryRate'] = (float)$averagePrice;
      $tmp['currentPrice'] = $currentPrice[$currency]['price'];
      $tmp['floatingPips'] = $floatingPips;
      $returnArray[$currency][$type] = $tmp;
    }
  }

  file_put_contents('data/5_ZipDataByCurrency.txt', json_encode($returnArray));
}

function getTip()
{
  $zipDataByCurrency = json_decode(file_get_contents('data/5_ZipDataByCurrency.txt'), true);
  $tmp = [];
  foreach ($zipDataByCurrency as $currency => $dataObj) {
    foreach ($dataObj as $type => $data) {
      if (!$tmp || ($tmp['floatingPips'] > $data['floatingPips'])) {
        $tmp['currency'] = $currency;
        $tmp['tradeType'] = $type;
        $tmp['stdLotds'] = $data['stdLotds'];
        $tmp['entryRate'] = $data['entryRate'];
        $tmp['currentPrice'] = $data['currentPrice'];
        $tmp['floatingPips'] = $data['floatingPips'];
      }
    }
  }
  file_put_contents('data/6_GetTip.txt', json_encode($tmp));
  header("Content-Type: application/json");
  print json_encode($tmp);
}

getTop20Id();
getDataFromId();
formatDataByCurrency();
getCurrentPrice();
zipDataByCurrency();
getTip();