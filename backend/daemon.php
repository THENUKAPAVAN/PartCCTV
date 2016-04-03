<?php
//------
//daemon.php
//(c) mironoff
//------

$baseDir = dirname(__FILE__);

// Проверка работоспособности MySQL
	mysqli_report(MYSQLI_REPORT_STRICT);
	//----------------------------
	//ВНИМАНИЕ!!!!!!! 
	//Поменять настройки подключения к БД
	//----------------------------
	try {
		$mysql = new mysqli('localhost', 'root', 'cctv', 'cctv');
	} catch (Exception $e) {
		echo'Houston, we have a problem with MySQL...'.PHP_EOL;
		echo $e->getMessage().PHP_EOL;
		exit;
	}
		$mysql->close();

// PID Lock		
/* 	$fp = fopen($baseDir.'/PartCCTV.pid', "c+");
	$count = 0;
	$got_lock = true;
	while (!flock($fp, LOCK_EX | LOCK_NB, $wouldblock)) {
			if ($wouldblock && $count++ < 1) {
				sleep(1);
			} else {
				$got_lock = false;
				break;
			}
	}
	if ($got_lock) {
		ftruncate($fp, 0); // очищаем файл

	} else {
		echo 'Платформа PartCCTV уже запущена!'.PHP_EOL;
		exit;
	} */
		
//Если всё хорошо.	
echo'OK'.PHP_EOL;

// Создаем дочерний процесс
// весь код после pcntl_fork() будет выполняться двумя процессами: родительским и дочерним
$child_pid = pcntl_fork();
if ($child_pid) {
    // Выходим из родительского, привязанного к консоли, процесса
    exit();
}
// Делаем основным процессом дочерний.
posix_setsid();

// Дальнейший код выполнится только дочерним процессом, который уже отвязан от консоли
ini_set('error_log',$baseDir.'/error.log');
fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);
$STDIN = fopen('/dev/null', 'r');

/* //Удаляем старый лог
unlink($baseDir.'/application.log'); */

$STDOUT = fopen($baseDir.'/application.log', 'ab');
$STDERR = fopen('/dev/null', 'r');

//Поехали!
require 'PartCCTVClass.php';
$PartCCTV = new PartCCTVClass;
$PartCCTV->run();

/* fflush($fp);        // очищаем вывод перед отменой блокировки
flock($fp, LOCK_UN); // отпираем файл
fclose($fp); */
?>
