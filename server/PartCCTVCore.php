<?php
// ------
// PartCCTVCore.php
// (c) 2016 m1ron0xFF
// @license: CC BY-NC-SA 4.0
// ------

chdir(__DIR__);
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../libs/MonologHandler.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use unreal4u\MonologHandler;
use unreal4u\TgLog;

class PartCCTVCore {
    protected $IF_Shutdown = 0;
	protected $IF_Restart_Required = 0;
	protected $CorePID;
	protected $WorkerPIDs = array();
	protected $CoreSettings = array();
	protected $Logger;
	protected $CamLogger;
	protected $PartCCTV_ini = array();    
	
    public function __construct() {	
        pcntl_signal(SIGTERM, array($this, "signalHandler"));
        pcntl_signal(SIGCHLD, array($this, "signalHandler"));
		
		$this->CorePID = getmypid();	
        
        $this->PartCCTV_ini = parse_ini_file(__DIR__.'/../PartCCTV.ini', true);

		// Monolog
        if($this->PartCCTV_ini['monolog_stream']['enabled'] || $this->PartCCTV_ini['monolog_telegram']['enabled']) {
            // Main Log
            $this->Logger  = new Logger('PartCCTV');

            // Cams Log
            $this->CamLogger = new Logger('PartCCTV_CAM');       
            
            $LoggerRef = new \ReflectionClass( 'Monolog\Logger' );            
        }
        
        //StreamHandler
        if($this->PartCCTV_ini['monolog_stream']['enabled']) {
            $level = $LoggerRef->getConstant( $this->PartCCTV_ini['monolog_stream']['log_level'] );
            $this->Logger->pushHandler(new StreamHandler(__DIR__.'/../PartCCTV.log', $level));            
    		$this->CamLogger->pushHandler(new StreamHandler(__DIR__.'/../PartCCTV_CAM.log', $level));	        
        }
        //TelegramHandler
        if($this->PartCCTV_ini['monolog_telegram']['enabled']) {
            $level = $LoggerRef->getConstant( $this->PartCCTV_ini['monolog_telegram']['log_level'] );            
            $TelegramHandler = new MonologHandler(new TgLog($this->PartCCTV_ini['monolog_telegram']['token']), $this->PartCCTV_ini['monolog_telegram']['user_id'], $level);
            $this->Logger->pushHandler($TelegramHandler);
            $this->CamLogger->pushHandler($TelegramHandler);             
        }    	        
    }

    public function run() {	

		$this->Logger->info('Запуск ядра платформы PartCCTV');
		$this->Logger->info('PID ядра: '.$this->CorePID);		
		
        //PDO
        try {
            $DBH = new PDO($this->PartCCTV_ini['db']['dsn'], $this->PartCCTV_ini['db']['user'], $this->PartCCTV_ini['db']['password']);
        }
        catch(PDOException $e) {  
			$this->Logger->EMERGENCY('Ошибка соединения с БД : '.$e->getMessage());
			exit(1);            
        }        
            		
		$CoreSettings_raw = $DBH->query("SELECT * FROM cam_settings");
        $CoreSettings_raw->setFetchMode(PDO::FETCH_ASSOC);  
		while ($row = $CoreSettings_raw->fetch()) {
			$this->CoreSettings[$row['param']] = $row['value'];
		}
		unset($CoreSettings_raw);
		unset($row);
		
		if (empty($this->CoreSettings['segment_time_min'])) {
			$this->Logger->EMERGENCY('segment_time_min не может быть равен нулю!!! Аварийное завершение.');
			exit(1);
		}
		
		$CamSettings_raw = $DBH->query("SELECT id FROM cam_list WHERE enabled = 1");
        $CamSettings_raw->setFetchMode(PDO::FETCH_ASSOC);         
		
		//Для каждой камеры запускаем свой рабочий процесс			
		while ($row = $CamSettings_raw->fetch()) {
			$this->camWorker($row['id']);
		}
		unset($row);
		unset($CamSettings_raw);
		
		$ArchiveCollectionTime = 0;	
		
		$ZMQContext = new ZMQContext();
		
		//  Socket to talk to clients
		$ZMQResponder = new ZMQSocket($ZMQContext, ZMQ::SOCKET_REP);
		$ZMQResponder->bind("tcp://*:5555");
		$this->Logger->debug('Запущен ZeroMQ сервер');
		while (TRUE) {
			
			pcntl_signal_dispatch();
			
			//  Чистим старые записи
			if ( (time() - $ArchiveCollectionTime) >= $this->CoreSettings['segment_time_min']*60 ) {
				$this->Logger->debug('Очистка старых записей');
				$ArchiveCollectionTime = time();
				exec('find '.$this->CoreSettings["path"].' -type f -mtime +'.$this->CoreSettings["TTL"].' -delete > /dev/null &');				
			}
            		
			$ZMQRequest = $ZMQResponder->recv (ZMQ::MODE_DONTWAIT);
			
			if($ZMQRequest) {
			
				$this->Logger->debug("Получен ZMQ запрос: ".$ZMQRequest);
				
				$Parsed_Request = json_decode($ZMQRequest, true);
				
				switch (json_last_error()) {
					case 'Request_Error_NONE':
						break;
					case 'Request_Error_DEPTH':
						$Request_Error = 'JSON Parser: Достигнута максимальная глубина стека';
						break;
					case 'Request_Error_STATE_MISMATCH':
						$Request_Error = 'JSON Parser: Некорректные разряды или не совпадение режимов';
						break;
					case 'Request_Error_CTRL_CHAR':
						$Request_Error = 'JSON Parser: Некорректный управляющий символ';
						break;
					case 'Request_Error_SYNTAX':
						$Request_Error = 'JSON Parser: Синтаксическая ошибка, не корректный JSON';
						break;
					case 'Request_Error_UTF8':
						$Request_Error = 'JSON Parser: Некорректные символы UTF-8, возможно неверная кодировка';
						break;
					default:
						$Request_Error = 'JSON Parser: Неизвестная ошибка';
						break;
				}
				
				if(!isset($Request_Error)) {
					
					switch($Parsed_Request['action']) {
						
						case 'worker_info':
							if(isset($Parsed_Request['id'])) {
								$CamInfo = $DBH->prepare("SELECT source FROM cam_list WHERE enabled = 1 AND id = :id");
                                $CamInfo->bindParam(':id', $Parsed_Request['id']);
                                $CamInfo->execute();
								$Response = $CamInfo->fetchColumn();
                                $CamInfo = null;
							} else {
								$Request_Error = 'worker_info: ID is required!';
							}
							break;
							
						case 'worker_if_shutdown':
							$Response = $this->IF_Shutdown;
							break;
							
						case 'core_status':
							$status = array(
								'core_pid' => $this->CorePID,
								'restart_required' => $this->IF_Restart_Required,							
								'path' => $this->CoreSettings['path'],
								'total_space' => round(disk_total_space($this->CoreSettings['path'])/1073741824),
								'free_space' => round(disk_free_space($this->CoreSettings['path'])/1073741824)					
							);
							$Response = json_encode($status);
							unset ($status);
							break;
							
						case 'core_workerpids':
							$Response = json_encode($this->WorkerPIDs);
							unset ($status);
							break;							
							
						case 'core_restart_is_required':
							$this->IF_Restart_Required = 1;
							$Response = 'OK';
							break;					

						case 'core_stop':
							exec('kill '.$this->CorePID);
							$Response = 'OK';
							break;								
							
						case 'core_log':
							$Response_Log = file_get_contents(__DIR__.'/../PartCCTV.log');			
							break;	
							
						case 'cam_log':
							$Response_Log = file_get_contents(__DIR__.'/../PartCCTV_CAM.log');		
							break;	
							
						default:
							$Request_Error = 'Unknown request!';
							break;							
					}

				}
					
				if(isset($Request_Error)) {
					$this->Logger->INFO("Ошибка обработки запроса: ".$Request_Error);
					$ZMQResponder->send($Request_Error);
					unset($Request_Error);
				} elseif(isset($Response)) {
					$this->Logger->DEBUG("Ответ платформы: ".$Response);
					$ZMQResponder->send($Response);
					unset($Response);
				} elseif(isset($Response_Log)) {
					$ZMQResponder->send($Response_Log);
					unset($Response_Log);
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
				
				if (count($this->WorkerPIDs) === 0) {
					$this->Logger->INFO('Завершение работы ядра платформы');
					exit(0);
				} elseif (time() - $shutdown_time > 60) {
					// Хьюстон, у нас проблема, прошло больше минуты, а вырубились не все дочерние процессы
					$this->Logger->EMERGENCY ('Аварийное завершение работы платформы: не все воркеры завершены!');
					exec('killall -s9 php');
					exit(1);
				}
			}
		}
    } 

	protected function camWorker($id) { 
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
			$this->WorkerPIDs[$pid] = $id;
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
			$this->CamLogger->info("Запущен воркер id".$id." с PID ".getmypid());
			exec('mkdir '.$this->CoreSettings["path"].'/id'.$id);		
			$attempts = 0;
			$time_to_sleep = 1;
			$time_of_latest_major_fail = time();
			
			WHILE(TRUE) {

				exec('ffmpeg -hide_banner -loglevel error -i "'.$source.'" -c copy -map 0 -f segment -segment_time '. $this->CoreSettings["segment_time_min"]*60 .' -segment_atclocktime 1 -segment_format mkv -strftime 1 "'.$this->CoreSettings["path"].'/id'.$id.'/%Y-%m-%d_%H-%M-%S.mkv" 1> log_id'.$id.'.txt 2>&1');
				
				// А может нам пора выключиться?
				$ZMQRequester->send(json_encode(array (	'action' => 'worker_if_shutdown' )));
				if($ZMQRequester->recv()) {
					$this->CamLogger->debug("Завершается воркер id".$id." с PID ".getmypid());
					exit(0);
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
						$time_to_sleep = $time_to_sleep*2;
					}

					// 3 неудачи
					if($attempts > 3) {
						$this->CamLogger->CRITICAL('Не удалось восстановить запись с камеры id'.$id.' в течение последних 3 попыток!');
						$attempts = 0;
					} else {
						++$attempts;
						$this->CamLogger->WARNING("Перезапущена запись с камеры id".$id);	
					}					
				}								
			}
		}
	}
		
    public function signalHandler($signo, $pid = null, $status = null) {
        switch($signo) {
            case SIGTERM:
				$this->Logger->info('Получен сигнал SIGTERM, начало завершения работы платформы');
				$this->IF_Shutdown = 1;
				exec('killall ffmpeg');
                break;		
            case SIGCHLD:
                // При получении сигнала от дочернего процесса
                if (!$pid) {
                    $pid = pcntl_waitpid(-1, $status, WNOHANG); 
                } 
                // Пока есть завершенные дочерние процессы
                while ($pid > 0) {
                    if ($pid && isset($this->WorkerPIDs[$pid])) {
                        if(!$this->IF_Shutdown) {
                            $this->Logger->CRITICAL('Воркер с PID '.$pid.' неожиданно завершил работу');							
                        } else {
                            $this->Logger->DEBUG('Воркер с PID '.$pid.' завершил работу');                            
                        }
                        // Удаляем дочерние процессы из списка
                        unset($this->WorkerPIDs[$pid]);
                    } 
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                } 	
                break;
            default:
                // все остальные сигналы
				break;
        }
	}		
}
