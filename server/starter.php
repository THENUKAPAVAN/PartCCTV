<?php
// ------
// starter.php
// (c) 2016 m1ron0xFF
// @license: CC BY-NC-ND 4.0
// ------

$errors = array();

if (version_compare(PHP_VERSION, '7.0.0') < 0) {
    $errors[] = 'Неподерживаемая версия PHP: ' . PHP_VERSION . PHP_EOL;
}

if (!(extension_loaded('mysql') or extension_loaded('mysqli'))) {
   $errors[] = 'Отсутствует расширение MySQL' . PHP_EOL;
}

if (!extension_loaded('zmq')) {
    $errors[] = 'Отсутствует расширение ZeroMQ ("zmq")' . PHP_EOL;
}

if (!empty($errors)) {
    $errors[] = 'Аварийное завершение!' . PHP_EOL;	
	foreach ($errors as $error) {
		echo $error;
	}
	exit;
}

unset ($errors);

// Создаем дочерний процесс
// весь код после pcntl_fork() будет выполняться двумя процессами: родительским и дочерним
$child_pid = pcntl_fork();
if ($child_pid) {
    // Выходим из родительского, привязанного к консоли, процесса
    exit();
}

// Делаем основным процессом дочерний.
posix_setsid();

ini_set('error_log',dirname(__FILE__).'/error.log');
fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);
$STDIN = fopen('/dev/null', 'r');
$STDOUT = fopen('/dev/null', 'r');
$STDERR = fopen('/dev/null', 'r');

require 'PartCCTVCore.php';
$PartCCTVCore = new PartCCTVCore;
$PartCCTVCore->run();
?>