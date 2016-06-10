<?php
// ------
// PartCCTVCore.php
// (c) 2016 m1ron0xFF
// @license: CC BY-NC-ND 4.0
// ------

require "../vendor/autoload.php";
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class PartCCTVCore {
    protected $IF_Shutdown = 0;
	protected $IF_Restart_Required = 0;
	protected $CorePID;
	protected $BaseDir;
	protected $WorkerPIDs = array();
	protected $CoreSettings = array();
	protected $Logger;
	protected $CamLogger;
	
    public function __construct() {	
        pcntl_signal(SIGTERM, array($this, "SignalHandler"));
        pcntl_signal(SIGCHLD, array($this, "SignalHandler"));
		
		$this->CorePID = getmypid();
		$this->BaseDir = dirname(__FILE__);		

		// Monolog
		$Handler = new StreamHandler($this->BaseDir.'/PartCCTV.log', Logger::DEBUG);
		$CamHandler = new StreamHandler($this->BaseDir.'/PartCCTV_CAM.log', Logger::DEBUG);

		// Main Log
		$this->Logger  = new Logger('PartCCTV');
		$this->Logger->pushHandler($Handler);

		// Cams Log
		$this->CamLogger = new Logger('PartCCTV_CAM');
		$this->CamLogger->pushHandler($CamHandler);		
    }

    public function run() {	

		$this->Logger->info('Запуск ядра платформы PartCCTV');
		$this->Logger->debug('PID ядра: '.$this->CorePID);		
		
		// MySQL
		$MySQLi = new mysqli('localhost', 'root', 'cctv', 'cctv');
		
		// Проверяем соединение с БД //
		if (mysqli_connect_errno()) {
			$this->Logger->EMERGENCY('Ошибка соединения с БД :'.mysqli_connect_error());
			exit();
		}		
		
		$CoreSettings_raw = $MySQLi->query("SELECT * FROM cam_settings");
		while ($row = $CoreSettings_raw->fetch_assoc()) {
			$this->CoreSettings[$row['param']] = $row['value'];
		}
		unset($CoreSettings_raw);
		unset($row);
		
		if (empty($this->CoreSettings['segment_time_min'])) {
			$this->Logger->EMERGENCY('segment_time_min не может быть равен нулю!!! Аварийное завершение.');
			exit;
		}
		
		$CamSettings_raw = $MySQLi->query("SELECT id FROM cam_list WHERE enabled = 1");	
		
		//Для каждой камеры запускаем свой рабочий процесс			
		while ($row = $CamSettings_raw->fetch_assoc()) {
			$this->CamWorker($row['id']);
		}
		unset($row);
		unset($CamSettings_raw);
		
		$ArchiveCollectionTime = 0;	
		$shutdowned_workers = 0;
		
		$ZMQContext = new ZMQContext();
		
		//  Socket to talk to clients
		$ZMQResponder = new ZMQSocket($ZMQContext, ZMQ::SOCKET_REP);
		$ZMQResponder->bind("tcp://*:5555");
		$this->Logger->debug('Запущен ZeroMQ сервер');
		while (TRUE) {
			
			pcntl_signal_dispatch();
			
			//  Чистим старые записи
			if ( (time() - $ArchiveCollectionTime) > $this->CoreSettings['segment_time_min']*60 ) {
				$this->Logger->debug('Очистка старых записей');
				$ArchiveCollectionTime = time();
				exec('find '.$this->CoreSettings["path"].' -type f -mtime +'.$this->CoreSettings["TTL"].' -delete > /dev/null &');				
			}
            		
			$request = $ZMQResponder->recv (ZMQ::MODE_DONTWAIT);
			if($request) {
			
				$this->Logger->debug("Получен ZMQ запрос: ".$request);
				$request_json = json_decode($request, true);
				
				switch($request_json['action']) {
					
					case 'worker_info':
						if(isset($request_json['id'])) {
							$CamInfo = $MySQLi->prepare("SELECT source FROM cam_list WHERE enabled = 1 AND id = ?");
							$CamInfo->bind_param("i", $request_json['id']);
							$CamInfo->execute();
							$CamInfo->bind_result($source);
							$CamInfo->fetch();
							$CamInfo->close();
							
							$ZMQResponder->send($source);
						} else {
							$ZMQResponder->send('Invalid request: ID is required!');
						}
						break;
						
					case 'worker_if_shutdown':
						$ZMQResponder->send($this->IF_Shutdown);
						
						// Считаем все завершенные процессы
						if($this->IF_Shutdown) {
							++$shutdowned_workers;
						}
						
						break;
						
					case 'core_status':
						$status = array(
							'core_pid' => $this->CorePID,
							'restart_required' => $this->IF_Restart_Required,							
							'path' => $this->CoreSettings['path'],
							'total_space' => round(disk_total_space($this->CoreSettings['path'])/1073741824),
							'free_space' => round(disk_free_space($this->CoreSettings['path'])/1073741824),							
						);
						$ZMQResponder->send(json_encode($status));
						unset ($status);
						break;
						
					case 'core_restart_is_required':
						$this->IF_Restart_Required = 1;
						break;						
						
					case 'core_log':
						$log = file_get_contents($this->BaseDir.'/PartCCTV.log');
						$ZMQResponder->send($log);
						unset ($log);						
						break;	
						
					case 'cam_log':
						$log = file_get_contents($this->BaseDir.'/PartCCTV_CAM.log');
						$ZMQResponder->send($log);
						unset ($log);						
						break;	
						
					default:
						$ZMQResponder->send('Invalid request!');
				}

			} else {
				sleep(1);
			}

			// Завершаем ядро при необходимости
			if ($this->IF_Shutdown) {
				
				// Время начала завершения работы
				if (!isset($shutdown_time)) {
					$shutdown_time = time();
				}
				
				//Все дочерние процессы завершены, можно завершаться
				if ($shutdowned_workers >= count($this->WorkerPIDs)) {
					$this->Logger->INFO('Завершение работы ядра платформы');
					exit;
				} elseif (time() - $shutdown_time > 1*60) {
					// Хьюстон, у нас проблема, прошло больше минуты, а вырубились не все дочерние процессы
					$this->Logger->EMERGENCY ('Аварийное завершение работы платформы: не все дочерние процессы завершены!');
					exec('killall -s 9 php');
				}
			}
		}
    } 

	protected function CamWorker($id) { 
        // Создаем дочерний процесс
        // весь код после pcntl_fork() будет выполняться
        // двумя процессами: родительским и дочерним
        $pid = pcntl_fork();
        if ($pid == -1) {
            // Не удалось создать дочерний процесс
            $this->Logger->alert('Could not launch new worker, exiting');
            return FALSE;
        } 
        elseif ($pid) {
            // Этот код выполнится родительским процессом
			$this->WorkerPIDs[$id] = $pid;
        } 
        else { 
            // А этот код выполнится дочерним процессом
			//Получаем информацию о камере
			$ZMQContext = new ZMQContext();
			$ZMQRequester = new ZMQSocket($ZMQContext, ZMQ::SOCKET_REQ);
			$ZMQRequester->connect("tcp://localhost:5555");
			$ZMQRequester->send(json_encode(array (	'action' => 'worker_info',	'id' => $id	)));
			$worker_info = $ZMQRequester->recv();
			$source = $worker_info;
			$this->CamLogger->info("Запущен процесс камеры id".$id." с PID ".getmypid());
			exec('mkdir '.$this->CoreSettings["path"].'/id'.$id);		
			$attempts = 0;
			$time_to_sleep = 1;
			$time_of_latest_major_fail = time();
			
			WHILE(TRUE) {

				exec('ffmpeg -hide_banner -loglevel error -i "'.$source.'" -c copy -map 0 -f segment -segment_time '. $this->CoreSettings["segment_time_min"]*60 .' -segment_atclocktime 1 -segment_format mkv -strftime 1 "'.$this->CoreSettings["path"].'/id'.$id.'/%Y-%m-%d_%H-%M-%S.mkv" 1> log_id'.$id.'.txt 2>&1');
				
				// А может нам пора выключиться?
				$ZMQRequester->send(json_encode(array (	'action' => 'worker_if_shutdown' )));
				if($ZMQRequester->recv()) {
					$this->CamLogger->info("Завершается процесс камеры id".$id." с PID ".getmypid());
					exit;
				} 	

				sleep($time_to_sleep);				
				
				// Запись была стабильной больше 15 минут, всё ок
				if (time() - $time_of_latest_major_fail >= 15*60) {
					$time_of_latest_major_fail = time();
					$attempts = 0;
					$time_to_sleep = 1;
					$this->CamLogger->NOTICE("Перезапущена запись с камеры id".$id);
				} else {
					// Хьюстон, у нас проблема
					
					// Много спать не к чему
					if($time_to_sleep >= 600) {
						$time_to_sleep = 1;
					} else {
						$time_to_sleep = $time_to_sleep*5;
					}

					// 3 неудачи
					if($attempts > 3) {
						$this->CamLogger->CRITICAL('Не удалось восстановить запись с камеры id'.$id.' в течение последних 3 попыток!');
						$attempts = 0;
					} else {
						$this->CamLogger->WARNING("Перезапущена запись с камеры id".$id);	
					}					
				}								
			}
		}
	}
		
    public function SignalHandler($signo, $pid = null, $status = null) {
        switch($signo) {
            case SIGTERM:
				$this->Logger->DEBUG('Получен сигнал SIGTERM, начало завершения работы платформы');
				$this->IF_Shutdown = 1;
				exec('killall ffmpeg');
                break;		
            case SIGCHLD:
// TODO			
/* 				if(!$this->IF_Shutdown) {
					// При получении сигнала от дочернего процесса
					if (!$pid) {
						$pid = pcntl_waitpid(-1, $status, WNOHANG); 
					} 
					// Пока есть завершенные дочерние процессы
					while ($pid > 0) {
						if ($pid && isset($this->WorkerPIDs[$pid])) {
							// Удаляем дочерние процессы из списка
							unset($this->WorkerPIDs[$pid]);
						} 
						$pid = pcntl_waitpid(-1, $status, WNOHANG);
					} 
				}	 */
                break;
            default:
                // все остальные сигналы
        }
	}		
}
?>