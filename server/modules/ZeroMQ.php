<?php
namespace PartCCTV\Module\ZeroMQ;

class ZeroMQ {
	function launchZeroMQ($BaseDir, $CorePID, $CoreSettings) { 
	   // Создаем дочерний процесс
	   // весь код после pcntl_fork() будет выполняться
	   // двумя процессами: родительским и дочерним
	   $pid = pcntl_fork();
	   if ($pid == -1) {
			// Не удалось создать дочерний процесс
			error_log('Не удалось запустить ZeroMQ сервер!!!');
			return FALSE;
		} 
		elseif ($pid) {
			// Этот код выполнится родительским процессом

		} 
		else { 
			// А этот код выполнится дочерним процессом
			  
			$context = new \ZMQContext(1);
			//  Socket to talk to clients
			$responder = new \ZMQSocket($context, \ZMQ::SOCKET_REP);
			$responder->bind("tcp://127.0.0.1:5555");
			\PartCCTVCore::log('Запущен ZeroMQ сервер...');
			while (TRUE) {
				//  Wait for next request from client
				try {
				$request = $responder->recv();
				} catch (ZMQSocketException $e) {
					if ($e->getCode() == 4) //  4 == EINTR, interrupted system call
					{			
						usleep(1); //  Don't just continue, otherwise the ticks function won't be processed, and the signal will be ignored, try it!
						continue; //  Ignore it, if our signal handler caught the interrupt as well, the $running flag will be set to false, so we'll break out
					}
					throw $e; //  It's another exception, don't hide it to the user
				}
				
				switch($request) {
					case 'status':
						$status = array('total_space' => round(disk_total_space($CoreSettings['path'])/1073741824), 'free_space' => round(disk_free_space($CoreSettings['path'])/1073741824), 'path' => $CoreSettings['path']);
						$responder->send(json_encode($status));
						unset ($status);
						break;						
					case 'log':
						$log = file_get_contents($BaseDir.'/application.log');
						$responder->send($log);
						unset ($log);						
						break;						
					case 'kill':
						// Перезагрузка
						$responder->send("OK");  					
						exec('bash restart.sh '.$CorePID.' '.getcwd().' > /dev/null &');
						break;					
					default:
						$responder->send("Invalid request!");
						break;
				}
			}
		} 
		return TRUE; 
	}
}