<?php
//------
//starter.php
//(c) m1ron0xFF
//------


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