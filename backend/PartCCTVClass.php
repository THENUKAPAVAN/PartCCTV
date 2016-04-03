<?php
//------
//PartCCTVClass.php
//(c) mironoff
//Демонизация оттуда: http://habrahabr.ru/post/134620/
//------

// Без этой директивы PHP не будет перехватывать сигналы
declare(ticks=1); 

class PartCCTVClass {
    // Здесь будем хранить запущенные дочерние процессы
    protected $currentJobs = array();
	protected $Classpid;
	
	public function log($message) {
        $time = @date('[d/M/Y:H:i:s]');
		echo "$time $message".PHP_EOL;
    }
	
    public function __construct() {
		$this->log('---                                      ---');		
		$this->log('---Сonstructed PartCCTV daemon controller---');
		$this->log('---                                      ---');			
        // Ждем сигналы SIGTERM и SIGCHLD
        pcntl_signal(SIGTERM, array($this, "childSignalHandler"));
        pcntl_signal(SIGCHLD, array($this, "childSignalHandler"));
    }

    public function run() {	
		$this->log('Запуск платформы PartCCTV...');	
		
		//MySQL
		mysqli_report(MYSQLI_REPORT_STRICT);
		//----------------------------
		//ВНИМАНИЕ!!!!!!! 
		//Поменять настройки подключения к БД
		//----------------------------
		try {
			$mysql = new mysqli('localhost', 'root', 'cctv', 'cctv');
		} catch (Exception $e) {
			die($e->getMessage());
		}	
		//----------------------------
		//ВНИМАНИЕ!!!!!!! 
		//Поменять настройки подключения к БД
		//----------------------------
		$this->Classpid = getmypid();	
		$maxProcesses = ($mysql->query("SELECT * FROM `cam_list` WHERE `enabled` = '1'")->num_rows)+1;
		$this->log("Максимум процессов: $maxProcesses");
		$camera = $mysql->query("SELECT * FROM `cam_list` WHERE `enabled` = '1'");
		$params_raw = $mysql->query("SELECT * FROM `cam_settings`");
		$params = array();	
		while ($row = $params_raw->fetch_assoc()) {
			$params[$row['param']] = $row['value'];
		}
		unset($params_raw);
		unset($row);
		
		$mysql->close();
		
		//Запускаем сервер ZeroMQ
		$this->launchZeroMQ($params['path']);		
		
		//Для каждой камеры запускаем свой дочерний процесс			
		while ($row = $camera->fetch_assoc()) {
		    $this->launchJob($row['id'],$row['source'],$params['path'],$params['segment_time_min']);
		}
		unset($row);
		unset($camera);
		
		sleep(1); 
		
        // Гоняем бесконечный цикл			
        // Если уже запущено максимальное количество дочерних процессов
        while(count($this->currentJobs) == $maxProcesses) {
			//Чистим старые записи				 
			exec('find '.$params["path"].' -type f -mtime +'.$params["TTL"].' -delete > /dev/null &');
            sleep($params['segment_time_min']*60);      	
        }
    } 


	protected function launchJob($id,$source,$path,$seg_time) { 
        // Создаем дочерний процесс
        // весь код после pcntl_fork() будет выполняться
        // двумя процессами: родительским и дочерним
        $pid = pcntl_fork();
        if ($pid == -1) {
            // Не удалось создать дочерний процесс
            error_log('Could not launch new job, exiting');
            return FALSE;
        } 
        elseif ($pid) {
            // Этот код выполнится родительским процессом
            $this->currentJobs[$pid] = TRUE;
        } 
        else { 
            // А этот код выполнится дочерним процессом
            $this->log("Запущен процесс с ID ".getmypid());
            $this->log("Начинаю запись камеры с id ".$id);
			exec('mkdir '.$path.'/id'.$id);	
			while (TRUE) {
				exec('ffmpeg -hide_banner -loglevel error -i "'.$source.'" -c copy -map 0 -f segment -segment_time '. $seg_time*60 .' -segment_atclocktime 1 -segment_format mp4 -strftime 1 "'.$path.'/id'.$id.'/%Y-%m-%d_%H-%M-%S.mp4" 1> log_id'.$id.'.txt 2>&1');
				sleep(10);
				$this->log("Прервалась запись камеры с id $id ,перезапускаю...");
			}
        } 
        return TRUE; 
    } 
	
	protected function launchZeroMQ($path) { 
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
            $this->currentJobs[$pid] = 'ZMQ';			
        } 
        else { 
            // А этот код выполнится дочерним процессом
            
			$context = new ZMQContext(1);
			//  Socket to talk to clients
			$responder = new ZMQSocket($context, ZMQ::SOCKET_REP);
			$responder->bind("tcp://127.0.0.1:5555");
			$this->log('Запущен ZeroMQ сервер...');
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
						$status = array('total_space' => round(disk_total_space($path)/1073741824), 'free_space' => round(disk_free_space($path)/1073741824), 'path' => $path);
						$responder->send(json_encode($status));
						unset ($status);
						break;						
					case 'log':
						$log = file_get_contents(dirname(__FILE__).'/application.log');
						$responder->send($log);
						unset ($log);						
						break;						
					case 'kill':
						// Перезагрузка
						$responder->send("OK");  					
						exec('bash restart.sh '.$this->Classpid.' '.getcwd().' > /dev/null &');
						break;					
					default:
						$responder->send("Invalid request!");
						break;
				}
			}
        } 
        return TRUE; 
    } 	
	
    public function childSignalHandler($signo, $pid = null, $status = null) {
        switch($signo) {
            case SIGTERM:
				//КОСТЫЛИ
				exec('killall ffmpeg');					
				foreach( $this->currentJobs as $key => $value ) {
					exec('kill '.$key);
				}		
                exit(1);	
                break;		
            case SIGCHLD:
                // При получении сигнала от дочернего процесса
                if (!$pid) {
                    $pid = pcntl_waitpid(-1, $status, WNOHANG); 
                } 
                // Пока есть завершенные дочерние процессы
                while ($pid > 0) {
                    if ($pid && isset($this->currentJobs[$pid])) {
                        // Удаляем дочерние процессы из списка
                        unset($this->currentJobs[$pid]);
                    } 
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                } 
                break;
            default:
                // все остальные сигналы
        }
	}		
}
?>