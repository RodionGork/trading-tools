<?php

$api = 'https://api.binance.com';

$month = $argv[1];
$ts0 = strtotime($month . "-01");
$ts1 = strtotime('+1 month', $ts0);

$res = '';

$cnt = intdiv($ts1 - $ts0, 60);

while ($cnt) {
  $limit = min(1000, $cnt);
  $cnt -= $limit;
  $data = file_get_contents("$api/api/v3/klines?symbol=BTCUSDT&interval=1m&limit=$limit&startTime={$ts0}000");
  if (!$data) {
    echo "Error on fetching data\n";
    return;
  }
  $data = json_decode($data);
  foreach ($data as $tick) {
    $res .= implode(',', array_map(fn($v) => number_format($v, 2, ".", ''), array_slice($tick, 1, 4))) . "\n";
  }
  $ts0 += $limit * 60;
  echo ".";
}
echo "\n";

file_put_contents("bnc-data/$month.csv", $res);
