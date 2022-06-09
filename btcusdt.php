<?php

include __DIR__ . '/db.php';
include __DIR__ . '/func/robot.php';
include __DIR__ . '/php-binance-api.php';

$start = microtime(true);

$api = new Binance\API("2","2");
$ticker = 'BTCUSDT';
$price = $api->price($ticker);

echo "\r BTC price: $price";
include __DIR__ . '/../curl/vendor/autoload.php';
// error_reporting(1);
$ticker = 'BTCUSDT';

$res = $mysqli->query("SELECT * FROM r_active_test WHERE stat != 0 AND ticker = '$ticker'");
  while ($row = mysqli_fetch_array($res)) {
    $r_active = $row['id'];

    $getStat = getStatTradingTrue($r_active, $price);

      // if($getStat === true){
      if(true){
        $request = new cURL\Request('http://localhost/func/trading.php?startTrading='.$r_active.'');
        $request->getOptions()
            ->set(CURLOPT_TIMEOUT, 1)
            ->set(CURLOPT_RETURNTRANSFER, true);

        // add callback when the request will be completed
        $request->addListener('complete', function (cURL\Event $event) {
            $response = $event->response;
            $content = $response->getContent();
           echo $content;
        });

        while ($request->socketPerform()) {
            // do anything else when the request is processed
        }
      }

  }

  $end = microtime(true);
  $result_time = $end - $start;
  // echo "\n $result_time";

function getStatTradingTrue($r_active, $price){

	include __DIR__ . '/../func/db.php';
	include __DIR__ . '/../func/lot_size.php';

	$res = $mysqli->query("SELECT * FROM trading WHERE r_active = '$r_active' ORDER BY id DESC");
		$row = mysqli_fetch_array($res);

    if($row == 0) {return true;} //Если ещё ни разу не торговал
		$ts_stat = $row['ts_stat'];

		if($ts_stat == 0){ //если трейлинг стоп не включен проверить достигла ли цена для включения тс
			if($price > $row['ts_start_up'] or $price < $row['ts_start_down']){ // если цена больше цены старта тс вверх или меньше старта вниз
				return true;
			}
			return false; // завершить проверку
		} else {
			if($ts_stat == 1){ // если включен тс вверх проверить поинты
				if($price > $row['point_up'] or $price <= $row['point_down']){ // если цена больше верхнего поина или ниже нижнего поинта
					return true;
				}
				return false; // завершить проверку
			} else if ($ts_stat == 2){
				if($price < $row['point_down'] or $price >= $row['point_up']){ // если цена больше верхнего поина или ниже нижнего поинта
					return true;
				}
			}
		}
}
 ?>
