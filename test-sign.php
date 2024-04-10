<?php

/*
Buy price of the last order encodes TP/SL wanted in cents digits:
e.g. 69300.XY
- here Y is for TP in 0.001 (1..9 +2, 0 means disabled)
- and X is for SL in 0.003 (1..0, never disabled)

mycron.sh
while true ; do curl 'http://localhost:80/test-sign.php?fire=1' > /dev/null ; sleep 31 ; done
*/

header('Content-Type: text/plain');

require('keyz.php'); // should define $apikey and $secret global vars

$output = new \stdClass();

$url = 'https://api.binance.com/api/v3';

function halt($msg) {
    echo json_encode(["error"=>$msg]) . "\n";
    exit(1);
}

function req($action, $params, $signed=true, $method='GET') {
	global $url, $apikey, $secret;
	$query = $params;
	if ($signed) {
		$query .= '&timestamp=' . time() . '000&recvWindow=10000';
		$sig = hash_hmac('sha256', $query, $secret);
		$query .= '&signature=' . $sig;
	}
	$opts = ['http'=>['method'=>$method, 'header'=>'X-MBX-APIKEY:' . $apikey,'ignore_errors'=>true]];
	$res = @file_get_contents("$url/$action?$query", false, stream_context_create($opts));
	if ($res === false) halt("error sending http request: " . error_get_last()['message']);
	$resj = json_decode($res);
	if ($resj === null) halt("error parsing json: $res");
	if (is_object($resj) && isset($resj->code)) halt("request returned error {$resj->code}: $res\n");
	return $resj;
}

function assets() {
	// e.g. {"asset":"USDT","free":"0.49349445","locked":"15972.15000000"}
	$acc = req('account', 'recvWindow=5000');
	$res = [];
	foreach ($acc->balances as $symbol) {
		if (in_array($symbol->asset, ['BTC', 'USDT'])) {
		$res[$symbol->asset] = $symbol;
		}
	}
	return $res;
}

function lastOrder() {
	$orders = req('allOrders', 'symbol=BTCUSDT&limit=20');
	for ($i = count($orders) - 1; $i >= 0; $i--) {
		if ($orders[$i]->status == 'FILLED') {
			return $orders[$i];
		}
	}
	return null;
}

function getCoinAmount($coin) {
    $info = req('account', 'omitZeroBalances=true');
    foreach ($info->balances as $asset) {
    	if ($asset->asset == $coin) return floatval($asset->free);
    }
    return 0;
}

function sltp($price) {
	$sl = floor($price * 10) % 10;
	if ($sl == 0) $sl = 10;
	$sl = $price * (1 - 0.004 * $sl);
	$tp = round($price * 100) % 10;
	if ($tp == 0) $tp = 30;
	$tp = $price * (1.002 + 0.001 * $tp);
	return [$sl, $tp];
}

function lastTickInfo() {
	return array_slice(req('klines', 'symbol=BTCUSDT&interval=1m&limit=1', false)[0], 2, 3);
}

$last = lastOrder();
if ($last === null) {
	$output->result = "no last order found";
} else {
	$output->last = ['side'=>$last->side, 'price'=>$last->price];
	$output->open = null;
    if ($last->side == 'BUY' && $last->price > 0) {
    	$btcAmt = getCoinAmount('BTC');
		list ($stoploss, $takeprof) = sltp($last->price);
		$output->open = ['price'=>$last->price, 'tp'=>$takeprof, 'sl'=>$stoploss, 'btc'=>$btcAmt];
		$btcAmt = number_format($btcAmt * 0.9985, 5);
		list($hi, $lo, $cur) = lastTickInfo();
		$output->cur = ['hi'=>$hi, 'lo'=>$lo, 'cur'=>$cur];
		$cur = floatval($cur);
		if ($cur < $stoploss || $cur >= $takeprof) {
			$cmd = isset($_GET['fire']) ? 'order' : 'order/test';
			$output->order = req($cmd, 'symbol=BTCUSDT&side=SELL&type=MARKET&quantity=' . $btcAmt, true, 'POST');
		} elseif (isset($_GET['sell'])) {
			$price = $_GET['sell'];
			if ($price < $cur) {
			    $output->error = "Requested sell price $price below current $cur";
			} else {
				$output->debug = "symbol=BTCUSDT&side=SELL&type=LIMIT&timeInForce=GTC&quantity=$btcAmt&price=$price";
				$output->order = req('order', "symbol=BTCUSDT&side=SELL&type=LIMIT&timeInForce=GTC&quantity=$btcAmt&price=$price", true, 'POST');
			}
		}
    } elseif ($last->side == 'SELL' && isset($_GET['buy'])) {
        $price = $_GET['buy'];
        $usdAmt = number_format(getCoinAmount('USDT') * 0.9985, 2, '.', '');
        list($hi, $lo, $cur) = lastTickInfo();
        if ($price > $cur) {
        	$output->error = "Requested buy price $price above current $cur";
        } else {
        	$output->debug = "symbol=BTCUSDT&side=SELL&type=LIMIT&timeInForce=GTC&quantityOrderQty=$usdAmt&price=$price";
        	$output->order = req('order/test', "symbol=BTCUSDT&side=SELL&type=LIMIT&timeInForce=GTC&quantityOrderQty=$usdAmt&price=$price", true, 'POST');
        }
    }
}

$t0 = @file_get_contents('ts.txt') ?? 0;
$t = time();
file_put_contents('ts.txt', $t);
$output->dt = $t-$t0;

echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
