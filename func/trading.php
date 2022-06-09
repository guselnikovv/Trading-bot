<?php
include 'db.php';

if(!empty($_GET['startTrading'])){

	$r_active = $_GET['startTrading'];

	$res = $mysqli->query("SELECT * FROM r_active_test WHERE id = $r_active");
		$row = mysqli_fetch_array($res);
		$user_id = $row['user_id'];
		$ticker = $row['ticker'];
		$budget = $row['budget'];
		$cycles = $row['cycles'];
		$trading_stat = $row['stat'];

	$res = $mysqli->query("SELECT * FROM r_users WHERE id = $user_id");
		$row = mysqli_fetch_array($res);
		$api_key = $row['api'];
		$secret = $row['secret'];
		$phone = $row['phone'];

    //Если Api и Secret не пустые запустить функцию трейдинга
		if(!empty($api_key) AND !empty($secret)) startTrading($user_id, $ticker, $budget, $cycles, $api_key, $secret, $r_active, $trading_stat, $phone);
}

function startTrading($user_id, $ticker, $budget, $cycles, $api_key, $secret, $r_active, $trading_stat, $phone){
  require __DIR__ . '/../vendor/autoload.php';
  $api = new Binance\API("$api_key","$secret");

  require 'db.php';
	include 'Trading_fun.php';

		//Стартовая процентовка усреднений
	  $tr_proc_up = 1; 	$tr_proc_down = 0.5;

		//Расчет количества частей усреднений
		$p = 1;
		for ($i=1; $i < $cycles; $i++) {
			$p = $p * 2;
		}

		//Расчет суммы первого входа
		$trading_sum = $budget / $p;

    //Формируем дату
  	$unix_time = time() * 1000; $toDay = date('Y-m-d H:i:s');

    //Выгружаем параметры последнего ордера
  	$res = $mysqli->query("SELECT * FROM trading WHERE user_id = $user_id AND ticker = '$ticker' ORDER BY id DESC");
  		$row = mysqli_fetch_array($res);
			$table_id = $row['id'];
			$table_order_id = $row['order_id'];
			$cycle = $row['cycle'];
			$ts_start_up = $row['ts_start_up'];
			$ts_start_down = $row['ts_start_down'];
		  $ts_stat = $row['ts_stat'];
			$avr = $row['avr'];
			$point_up = $row['point_up'];
			$point_down = $row['point_down'];

  		//Остановить робота после завершения цикла
  		if($cycle == 0 AND $trading_stat == 2) {
        $res = $mysqli->query("UPDATE r_active_test SET stat = 0 WHERE id = $r_active");
        echo "\n Остановка после выхода";
        die;
      }

      include 'cycles.php';

			$price = $api->price($ticker);
			echo "\n \n \n \n \e[1;95m Robot: $r_active | User: $user_id | Price: $price \n";

			//Начальная процентовка отскока для трейлинг стопа
			$ts_proc_up = 0;
			$ts_proc_down = 0.3;

			if($cycle == 0){
				if(!empty($table_order_id)){
					$table_order_id += 1;
				} else {
					$table_order_id = 1;
				}


  			 //Делаем первую покупку по маркету
  			 $quantity = $trading_sum / $price;
				 $quantity = roundLotSize($ticker, $quantity);

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

					$base_avr = $or_sum / $or_qty;
					$ts_start_up = cutNum($base_avr + ($base_avr / 100 * $tr_proc_up));// цена при старте трейлинг стопа вверх
					$ts_start_down = cutNum($base_avr - ($base_avr / 100 * $tr_proc_down));// цена при старте трейлинг стопа вниз

  				if(!empty($or_sum) AND !empty($or_qty)){
  					$insert = $mysqli->query("INSERT INTO trading (r_active, order_id, user_id, ticker, stat, qty, cycle, sum, unix_date, date_time, avr, ts_start_up, ts_start_down, ts_stat)
                VALUES ($r_active, $table_order_id, $user_id, '$ticker', 'buy', '$or_qty', 1, '$or_sum', '$unix_time', '$toDay', '$base_avr', '$ts_start_up', '$ts_start_down', '0')");
						if($insert === false) {
							$up = $mysqli->query("UPDATE r_active_test SET stat = 0 WHERE id = $r_active");
							sendMessage('79186777440', "$r_active Ошибка добавления Первая закупка $r_active, $table_order_id, $user_id, $ticker , $or_qty , $or_sum , $unix_time , $toDay , $base_avr , $ts_start_up , $ts_start_down ");
						}

						echo "\r \x1b[32m Первая закупка: $ticker price: $price coin: $or_qty sum: $or_sum start_ up $ts_start_up start down: $ts_start_down";
						echo "x1b[0m] ";

						insertLog($user_id, $r_active, "цикл: 1 Покупка $or_sum tiker: $ticker");
						die;

  				}
					##########################################################
  			}

					// Если цена больше минимального значения для старта трейлинг стопа и трейлинг стоп ещё не активирован
					if($ts_stat == 0) { // если не включет трейлинг стоп
						if($ts_stat == 0 AND $price < $ts_start_down) {
							if($cycles == $cycle){
								echo "Стоп! последний цикл";
								die;
							}

							$point_down = $price; //Новая цена верхнего поинта
							$point_up = $point_down + ($price / 100 * $ts_proc_down); // Минимальная цена поинта при достижении продать

							$update = $mysqli->query("UPDATE trading SET ts_stat = 2, point_up = '$point_up', point_down = '$point_down' WHERE id = $table_id");
							if($update === false) {
								$up = $mysqli->query("UPDATE r_active_test SET stat = 0 WHERE id = $r_active");
								sendMessage('79186777440', "$r_active Ошибка обнавление вкл трейлинг стоп вниз");
							} else {
								insertLog($user_id, $r_active, "Цена упала, включаем тс вниз $point_up");
								echo "Цена  упала, включаем трейлинг стоп <br /> point_up: $point_up";
								die;
							}

						}
						$e_proc_up = round(($ts_start_up - $price) * 100 / $price, 2);
						echo " \n \e[1;92m До запуска TS вверх $e_proc_up % ";
						$e_proc_down = round(($ts_start_down - $price) * 100 / $price, 2);
						echo "| \e[1;91m До запуска TS вниз $e_proc_down % ";
					}


					//если включен трейлинг стоп в верх и цена выше предидущего верхнего поинта - обновляем поинты
					if($ts_stat == 1 AND $price > $point_up) {
						// Увеличиваем процент отскока вниз для фиксирования процент роста от средней делем на 6 частей
						$proc = ($price -$avr) * 100 / $avr;
						$proc = $proc / 6;

						$point_up = $price; //Новая цена верхнего поинта
						$point_down = $point_up - ($price / 100 * $proc); // Минимальная цена поинта при достижении продать

						$update = $mysqli->query("UPDATE trading SET point_up = '$point_up', point_down = '$point_down' WHERE id = $table_id");
						if($update === false) {
							$up = $mysqli->query("UPDATE r_active_test SET stat = 0 WHERE id = $r_active");
							sendMessage('79186777440', "$r_active $table_id, $point_up , $point_down Ошибка обновления поинта тс вверх");
						} else {
							insertLog($user_id, $r_active, "ена пошла вверх, обновляем поинт $point_up");
							echo "Цена пошла вверх, обновляем поинты New proc down: $proc";
							die;
						}

					}
//вернуть
						//if($ts_stat == 1 AND $price <= $point_down){
					if($price >= $ts_start_up){
						// Получаем последнюю unix дату нулевой сделки что-бы от неё суммировать все покупки и получить количество монет
						$res = $mysqli->query("SELECT id FROM trading WHERE cycle = 0 AND user_id = $user_id AND ticker = '$ticker' AND r_active = $r_active ORDER BY id DESC");
	  					$row = mysqli_fetch_array($res);
	  					if($row[0] != 0){
	  						$lastId = $row[0];
	  					} else $lastId = 0;

							$sum_order = 0;	$qty = 0;
		  				$res = $mysqli->query("SELECT * FROM trading WHERE id > $lastId AND cycle >= 1 AND user_id = $user_id AND ticker = '$ticker' AND r_active = $r_active");
		  					while ($row = mysqli_fetch_array($res)) {
		  						$sum_order += $row['sum'];
		  						$qty += $row['qty'];
		  					}
								echo "$sum_order $qty $lastDate";


								$balances = $api->balances();
								$coin_name = str_replace('USDT', '', $ticker);
	  						$col_coin = floatval($balances[$coin_name]['available']);

                $quantity = roundLotSize($ticker, $col_coin);

                $order = $api->marketSell($ticker, $quantity);
  							$order_arr = $order['fills'];
  								//Перебераем вложенный массив FILLS для суммирования всех сделок
  								$or_sum = 0; $or_qty = 0;
  								foreach ($order_arr as $key => $val) {
  									if(!empty($val['qty']) AND !empty($val['price'])){
  										$or_qty += $val['qty'];
  										$or_sum += ($val['qty'] * $val['price']);
  									}
  								}

								$fix_profit = $or_sum - $sum_order;
                  if(!empty($or_sum) AND !empty($or_qty)) {
								$insert = $mysqli->query("INSERT INTO trading (r_active, order_id, user_id, ticker, stat, qty, cycle, sum, unix_date, date_time, avr, ts_start_up, ts_start_down, ts_stat, profit) VALUES
								($r_active, $table_order_id, $user_id, '$ticker', 'sell', '$qty', 0, '$or_sum', '$unix_time', '$toDay', '0', '0', '0', '0', '$fix_profit')");
								if($insert === false) {
									$up = $mysqli->query("UPDATE r_active_test SET stat = 0 WHERE id = $r_active");
									sendMessage('79186777440', "$r_active Ошибка усреднения $r_active , $table_order_id , $user_id , $ticker , $qty , $or_sum , $unix_time , $toDay , $fix_profit");
								} else {
									$send_profit = round($fix_profit, 2);
                  sendMessage($phone, "*$ticker:* Продажа: *$or_sum* Профит: *$send_profit $*");
                  sendMessage('79186777440', "*$ticker* *$user_id* *$r_active* Продажа: *$or_sum* Профит: *$fix_profit*");
									echo "Фиксируем: <br /> coin $qty sum: $or_sum";
									die;
                }
              }

					}

					//Если цена опустилась нижнего придела поинта point_down - обновляем поинты
					if($ts_stat == 2){
						if($cycle >= $cycles) {
							echo "\n\n $cycles - $cycle Достигли максимальный цикл";
							$res = $mysqli->query("UPDATE trading SET ts_stat = 0 WHERE id = $table_id AND user_id = $user_id");
							if($res === false){
								$mysqli->query("UPDATE r_active_test SET stat = 0 WHERE id = $r_active");
							}
							die;
						}

						$e_update_point_down = round(($price - $point_down) * 100 / $price, 4);

						if($price < $point_down) {
							// Увеличиваем процент отскока вверх для усреднения процент падения от средней делем на 6 частей
							$proc = ($avr - $price) * 100 / $avr;
							$proc = $proc / 6;

							$point_down = $price; //Новая цена нижнего поинта
							$point_up = $point_down + ($price / 100 * $proc); // верхний предел поинта по достижению сделать усреднение

							$update = $mysqli->query("UPDATE trading SET point_up = '$point_up', point_down = '$point_down' WHERE id = $table_id");
							if($update === false) {
								$up = $mysqli->query("UPDATE r_active_test SET stat = 0 WHERE id = $r_active");
								sendMessage('79186777440', "$r_active Ошибка обновления поинта вниз");
							} else {
									echo "\n \e[1;91m Цена просела, обновляем поинты New proc $proc";
									die;
							}

							} // end обновляем поинты ЦЕНА ПРОСЕЛА

							if($price >= $point_up) { //Если цена сровнялась или больше чем верхний поинт - докупаем(усредняем)
									echo "Усреднить <Br></Br>";
								// Получаем последнюю unix дату нулевой сделки что-бы от неё суммировать все покупки и получить количество монет
								$res = $mysqli->query("SELECT unix_date FROM trading WHERE cycle = 0 AND user_id = $user_id AND ticker = '$ticker' ORDER BY id DESC");
			  					$row = mysqli_fetch_array($res);
			  					if($row[0] != 0){
			  						$lastDate = $row[0];
			  					} else $lastDate = 0;

									$sum_order = 0;	$qty = 0;
				  				$res = $mysqli->query("SELECT * FROM trading WHERE unix_date > $lastDate AND cycle >= 1 AND user_id = $user_id AND ticker = '$ticker'");
				  					while ($row = mysqli_fetch_array($res)) {
				  						$sum_order += $row['sum'];
				  						$qty += $row['qty'];
				  					}


										$true_qty = $sum_order / $price;
                    $quantity = roundLotSize($ticker, $true_qty);
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

											$cycle += 1;

											include 'cycles.php';

										$all_qty = $or_qty + $qty; //Получаем общее количество купленных монет

										$new_avr = ($or_sum + $sum_order) / $all_qty; // Общая потраченная сумма делится на общее количество моент
										$ts_start_up = cutNum($new_avr + ($new_avr / 100 * $tr_proc_up));// цена при старте трейлинг стопа вверх
										$ts_start_down = cutNum($new_avr - ($new_avr / 100 * $tr_proc_down));// цена при старте трейлинг стопа вниз



                    if(!empty($or_sum) AND !empty($or_qty)) {
										$insert = $mysqli->query("INSERT INTO trading (r_active, order_id, user_id, ticker, stat, qty, cycle, sum, unix_date, date_time, avr, ts_start_up, ts_start_down, ts_stat) VALUES
										($r_active, $table_order_id, $user_id, '$ticker', 'buy', '$or_qty', $cycle, '$or_sum', '$unix_time', '$toDay', '$new_avr', '$ts_start_up', '$ts_start_down', '0')");
										if($insert === false) {
											$up = $mysqli->query("UPDATE r_active_test SET stat = 0 WHERE id = $r_active");
                      sendMessage('79186777440', "$r_active Ошибка усреднения");
										}
										echo "\n \e[1;92m Усреднились на сумму $or_sum new avr $new_avr | ";
                  }
							} //end Разворот дукупаем
						}

}


function insertLog($user_id, $r_active, $body){
	include 'db.php';

	$toTime = date("Y-m-d H:i:s");
	$res = $mysqli->query("INSERT INTO r_log (time, users_id, r_id, body, icycle)
																		VALUES ('$toTime', $user_id, $r_active, '$body', '0')");

}

function sendMessage($phone, $body){
	include __DIR__ . '/../chatapi.php';

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
	$result = file_get_contents($url, false, $options);
	return $result;
}


function cutNum($int){
	return substr($int, 0, 12);
}
 ?>
