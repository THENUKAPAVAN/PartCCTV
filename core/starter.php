<?php
// ------
// starter.php
// (c) 2016 m1ron0xFF
// @license: CC BY-NC-SA 4.0
// ------

chdir(__DIR__);
require_once __DIR__.'/../version.php';
require_once __DIR__.'/../vendor/autoload.php';
class PartCCTVException extends Exception {};

try {
	if (version_compare(PHP_VERSION, '7.0.0') < 0) {
		throw new PartCCTVException('Неподерживаемая версия PHP: ' . PHP_VERSION);
	}
	if (!extension_loaded('pdo')) {
	   throw new PartCCTVException('Отсутствует PHP расширение PDO');
	}
	if (!extension_loaded('zmq')) {
		throw new PartCCTVException('Отсутствует PHP расширение ZeroMQ (zmq)');
	}
} catch (PartCCTVException $e) {
	echo "PartCCTV start check failed: " . $e->getMessage() . PHP_EOL;
	exit(1);
}

// Создаем дочерний процесс
// весь код после pcntl_fork() будет выполняться двумя процессами: родительским и дочерним
$child_pid = pcntl_fork();
if ($child_pid) {
	echo 'Start OK' . PHP_EOL;
} else {
	// Делаем основным процессом дочерний.
	posix_setsid();


// Поехали!
require 'PartCCTVCore.php';
$PartCCTVCore = new PartCCTVCore;
$PartCCTVCore->run();
}	
