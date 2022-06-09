<?php
require __DIR__ . '/php-binance-api.php';

function db(){
  return  __DIR__ . '/../db.php';
}
function getAllBot(){
  include db();

  $res = $mysqli->query("SELECT * FROM r_bot_active WHERE stat = 'acive'");
    while ($row = mysqli_fetch_array($res)) {
      // code...
    }
}
function sendMessage($phone, $body){
	include __DIR__ . '/../token.php';

  $phone = $phone;
	$body = $body;
	$date = [
	 'phone' => $phone,
	 'body' => $body
	];
	$json = json_encode($date);
	$options = stream_context_create(['http' => [
	 'method' => 'POST',
	 'header' => 'Content-type: application/json',
	 'content' => $json
	]
	]);
	$result9 = file_get_contents($url, false, $options);
  return $result9;
}

function testBot($user_id, $ticker, $budget, $cycles){
  require __DIR__ . '/../vendor/autoload.php';



  $api = new Binance\API("aY3wyNg4xawYmNMTUg7g6PhJKcwI1hw9hMnh6qQHHlSLZctgx6cWWeLmhagXozSK","g7v8sPpCQ3TgWxByA5RxEVGdMuwhbRKaFbLeIMXGNET9HnzMwbUsREG9c5e67SOw");

  require $_SERVER['DOCUMENT_ROOT'].'/binance/fun/connectDB.php';

  $first_buy = $budget / $cycles;
  sendMessage('79186777440', "Пользователь $user_id  Расчитываем первых вход $first_buy");

  $price = $api->price($ticker);
  sendMessage('79186777440', "Пользователь $user_id  Получаем цену $price");

  sleep(35);

  sendMessage('79186777440', "Пользователь $user_id  проводим сделку");

}



function activeBot($user_id, $ticker, $budget, $cycles, $r_active){
  include db();

  $res = $mysqli->query("SELECT * FROM r_users WHERE id = $user_id ");
    $row = mysqli_fetch_array($res);
    if($row[0] != 0){
      $api = $row['api'];
      $secret = $row['secret'];
    } else echo 'dont USER';

    $tr_proc = 2;
    $tr_proc_up = 1;

  	$price = $api->price($ticker);

  	$trading_sum = 55;
  	$unix_time = time() * 1000;
  	$toDay = date('Y-m-d');

  	$res = $mysqli->query("SELECT * FROM bot_step WHERE user_id = $user_id ORDER BY id DESC");
  		$row = mysqli_fetch_array($res);

  		$cycle = $row['cycle'];
  		//Остановить робота после завершения цикла
  		// if($cycle == 0) die;

  		if($cycle > 2) {
  			$tr_proc = 7; $tr_proc_up = 0.7;
  		}
  		if($cycle >= 3) {
  			$tr_proc = 10; $tr_proc_up = 0.5;
  		}
  		if($cycle >= 6) {
  			$tr_proc = 4; $tr_proc_up = 1.5;
  		}
  		$date = $row['unix_date'];


  			//Если последняя запись равняется продаже
  			if($cycle == 0){
  				//Делаем первую покупку по маркету
  				$quantity = $trading_sum / $price;
  				//Купить мо паркету
  				$quantity = substr($quantity, 0, 4);
  				$sum = $quantity * $price;

  				$order = $api->marketBuy($ticker, $quantity);
  				$order_arr = $order['fills'];
  					//Перебераем вложенный массив FILLS для суммирования всех сделок
  					$or_sum = 0; $or_qty = 0;
  					foreach ($order_arr as $key => $val) {
  						if(!empty($val['qty']) AND !empty($val['price'])){
  							$or_qty += $val['qty'];
  							$or_sum += ($val['qty'] * $val['price']);
  						}
  					}


  				if(!empty($or_sum) AND !empty($or_qty)){
  					$insert = $mysqli->query("INSERT INTO bot_step (ticker, stat, qty, cycle, sum, unix_date, date, user_id) VALUES ('$ticker', 'buy', '$quantity', 1, '$sum', '$unix_time', '$toDay', $user_id)");
   				if($insert === false) echo "false"; die;
  				}
  			}
  			// Если циклк не равен нулю (первая закупка сделана)
  			if($row[0] != 0){
  				$cycle = $row['cycle'];

  				$res = $mysqli->query("SELECT unix_date FROM bot_step WHERE cycle = 0 AND user_id = $user_id ORDER BY id DESC");
  					$row = mysqli_fetch_array($res);
  					if($row[0] != 0){
  						$lastDate = $row[0];
  					} else $lastDate = 0;

  				//Количество покупок за последниц цикл
  				$sum_order = 0;
  				$qty = 0;

  				$res = $mysqli->query("SELECT * FROM bot_step WHERE unix_date > $lastDate AND cycle >= 1 AND user_id = $user_id");
  					while ($row = mysqli_fetch_array($res)) {
  						$sum_order += $row['sum'];
  						$qty += $row['qty'];
  					}

  					$avr = $sum_order / $qty;
  					$price_proc = $avr / 100 * $tr_proc;
  					$price_proc_up = $avr / 100 * $tr_proc_up;
  					$price_proc_up = cutNum($price_proc_up);
  					$avr_down = $avr - $price_proc;
  					$avr_up = $avr + $price_proc_up;
  					$avr = cutNum($avr);
  					$price_proc = cutNum($price_proc);
  					$avr_down = cutNum($avr_down);
  					$avr_up = cutNum($avr_up);
  					$price = cutNum($price);


  					$balances = $api->balances();
  					$usdt = $balances['USDT']['available'];


  					if($price <= $avr_down){ // Усреднить

  						//Увеличеваем цикл
  						$cycle += 1;
  						$sum = $qty * $price;
  						$quantity = $sum / $price;
  						$quantity = cutNum($quantity);

  						//Если на балансе больше чем сумма покупки проводим ордер
  						if($usdt >= $sum){

  							$order = $api->marketBuy($ticker, $quantity);
  							$order_arr = $order['fills'];
  								//Перебераем вложенный массив FILLS для суммирования всех сделок
  								$or_sum = 0; $or_qty = 0;
  								foreach ($order_arr as $key => $val) {
  									if(!empty($val['qty']) AND !empty($val['price'])){
  										$or_qty += $val['qty'];
  										$or_sum += ($val['qty'] * $val['price']);
  									}
  								}
  							if(!empty($or_sum) AND !empty($or_qty)){
  								$insert = $mysqli->query("INSERT INTO bot_step (ticker, stat, qty, cycle, sum, unix_date, date, user_id) VALUES ('$ticker', 'buy', '$or_qty', $cycle, '$or_sum', '$unix_time', '$toDay', $user_id)");

  										if($insert === false) {
  											echo "false";
  											die;
  										}	else {
  											echo "<pre>";
  											var_dump($order);
  											echo "</pre>";
  										}
  								}

  							} else { // ЕСЛИ НЕХВАТАЕТ ДЕНЕГ НА УСРЕДНЕНИЯ ПОТРАТИТЬ ВСЕ ОСТАВШИЕСЯ
  								// $coin_name = str_replace('USDT', '', $ticker);
  								// $col_coin = floatval($balances['USDT']['available']);
  								// $col_coin = intval($col_coin);
  								// $quantity = cutNum($col_coin / $price);
  								//
  								// $order = $api->marketBuy($ticker, $quantity);
  								// $order_arr = $order['fills'];
  								// 	//Перебераем вложенный массив FILLS для суммирования всех сделок
  								// 	$or_sum = 0; $or_qty = 0;
  								// 	foreach ($order_arr as $key => $val) {
  								// 		if(!empty($val['qty']) AND !empty($val['price'])){
  								// 			$or_qty += $val['qty'];
  								// 			$or_sum += ($val['qty'] * $val['price']);
  								// 		}
  								// 	}
  								// if(!empty($or_sum) AND !empty($or_qty)){
  								// 	$insert = $mysqli->query("INSERT INTO bot_step (ticker, stat, qty, cycle, sum, unix_date, date, user_id) VALUES ('$ticker', 'buy', '$or_qty', $cycle, '$or_sum', '$unix_time', '$toDay', $user_id)");
  								//
  								// 			if($insert === false) {
  								// 				echo "false";
  								// 				die;
  								// 			}	else {
  								// 				echo "<pre>";
  								// 				var_dump($order);
  								// 				echo "</pre>";
  								// 			}
  								// 	}

  							}
  						}


  // $price >= $avr_up
  						 if ($price >= $avr_up){ // Зафиксировать

  						$cycle = 0;
  						//Получаем название монеты и запрашиваем баланс
  						$coin_name = str_replace('USDT', '', $ticker);
  						$col_coin = floatval($balances[$coin_name]['available']);


  						$order = $api->marketSell($ticker, $col_coin);
  						$order_arr = $order['fills'];
  							//Перебераем вложенный массив FILLS для суммирования всех сделок
  							$or_sum = 0; $or_qty = 0;
  							foreach ($order_arr as $key => $val) {
  								if(!empty($val['qty']) AND !empty($val['price'])){
  									$or_qty += $val['qty'];
  									$or_sum += ($val['qty'] * $val['price']);
  								}
  							}

  						if(!empty($or_sum) AND !empty($or_qty)){
  							$insert = $mysqli->query("INSERT INTO bot_step (ticker, stat, qty, cycle, sum, unix_date, date, user_id) VALUES ('$ticker', 'sell', '$or_qty', $cycle, '$or_sum', '$unix_time', '$toDay', $user_id)");
  							sendMessage('79186777440', "ПРОДАЖА НА $or_sum");
  							sendMessage('79182615271', "ПРОДАЖА НА $or_sum");
  							if($insert === false) echo "false"; die;
  						}

  					}
  					echo "<br /> Процент покупки: <b>$tr_proc</b>  Процент продажи: <b>$tr_proc_up</b><br />";

  					echo "Цикл: <b>$cycle</b> Цена: <b>$price</b> Средняя: <b>$avr</b> Процент: <b>$price_proc_up</b>  Цена при снижении: <b>$avr_down</b> Цена при росте <b>$avr_up</b> <br />";
  			}


}
 ?>
