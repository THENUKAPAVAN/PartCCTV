<?php
//------
//starter.php
//(c) m1ron0xFF
//------

require_once $baseDir.'/Core/ExclusiveLock.php';
//PIDLock_Test
$lock = new ExclusiveLock( "PartCCTV", "Starter");
//lock
if( $lock->lock( ) == FALSE )
    echo("PID Lock Test Failed!");
	exit;
$lock->unlock();
unset($lock);

// Создаем дочерний процесс
// весь код после pcntl_fork() будет выполняться двумя процессами: родительским и дочерним
$child_pid = pcntl_fork();
if ($child_pid) {
    // Выходим из родительского, привязанного к консоли, процесса
    exit();
}

// Делаем основным процессом дочерний.
posix_setsid();

$baseDir = dirname(__FILE__);
ini_set('error_log',$baseDir.'/error.log');
fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);
$STDIN = fopen('/dev/null', 'r');
$STDOUT = fopen($baseDir.'/debug.log', 'ab');
$STDERR = fopen('/dev/null', 'r');

//Грузим ядро
require 'PartCCTVCore.php';
$PartCCTV = new PartCCTVCore;
$PartCCTV->run();
?>