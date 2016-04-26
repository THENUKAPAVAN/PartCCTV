<?php
// ------
// PartCCTVCore.php
// (C) m1ron0xFF
// ------

class PartCCTVCore {
    protected $WorkerPIDs = array();
	protected $CorePID;
	protected $BaseDir;
	protected $CoreSettings = array();
	
	public function log($message) {
        $time = @date('[d/M/Y:H:i:s]');
		echo "$time $message".PHP_EOL;
    }
	
    public function __construct() {	
        pcntl_signal(SIGTERM, array($this, "SignalHandler"));
        pcntl_signal(SIGCHLD, array($this, "SignalHandler"));

		$this->CorePID = getmypid();
		$this->BaseDir = dirname(__FILE__);		

/* 		// Monolog
		use libs\Monolog\Logger;
		use libs\Monolog\Handler\StreamHandler;
		use libs\Monolog\Handler\PlivoHandler;

		$StreamHandler = new StreamHandler(__DIR__.'/PartCCTV_debug.log', Logger::DEBUG);
		$CamHandler = new StreamHandler(__DIR__.'/PartCCTV_Cam_debug.log', Logger::DEBUG);
		$PlivoHandler = new PlivoHandler($token,$auth_id,$fromPhoneNumber,$toPhoneNumber, Logger::ALERT);

		// Main Log
		$logger  = new Logger('PartCCTV_PlivoLogger');
		$logger->pushHandler($StreamHandler);
		$logger->pushHandler($PlivoHandler);

		// Cams Log
		$CamLogger = new Logger('PartCCTV_CamLogger');
		$CamLogger->pushHandler($CamHandler);
		$CamLogger->pushHandler($PlivoHandler); */
		
		function Autoloader($Class) {
			$ArrayClass = explode('\\', $Class);
			$ClassType = $ArrayClass[1];
			$ClassName = $ArrayClass[2];
			PartCCTVCore::log('Инициализация '.$ClassType.' '.$ClassName);
			switch ($ClassType) {
				case 'Module':
					$path = dirname(__FILE__) .'/modules/';
					break;
				case 'Lib':
					$path = dirname(__FILE__) .'/libraries/';
					break;	
				case 'Core':
					$path = dirname(__FILE__) .'/core/';
					break;	
				default: 
			}				
			require_once $path.$ClassName.'.php';
		}
		
		spl_autoload_register('Autoloader');
		
		$this->log('---                                      ---');		
		$this->log('---Сonstructed PartCCTV daemon controller---');
		$this->log('---                                      ---');	
    }

    public function run() {	

		$this->log('Запуск платформы PartCCTV...');	
		$MySQLi = new PartCCTV\Module\MySQLi\createCon;
		$MySQLi->connect();
		
		$CoreSettings_raw = $MySQLi->myconn->query("SELECT * FROM `cam_settings`");
		while ($row = $CoreSettings_raw->fetch_assoc()) {
			$this->CoreSettings[$row['param']] = $row['value'];
		}
		unset($CoreSettings_raw);
		unset($row);
		
		$CamSettings_raw = $MySQLi->myconn->query("SELECT * FROM `cam_list` WHERE `enabled` = '1'");
		
		$MySQLi->close();
		unset($MySQLi);		
		
		//ZeroMQ
		$ZeroMQ = new PartCCTV\Module\ZeroMQ\ZeroMQ;
		$ZMQ_PID = $ZeroMQ->launchZeroMQ($this->BaseDir, $this->CorePID, $this->CoreSettings);
		$this->WorkerPIDs[$ZMQ_PID] = 'ZeroMQ';
 		unset($ZMQ_PID);
		unset($ZeroMQ);
		
		//Для каждой камеры запускаем свой рабочий процесс			
		while ($row = $CamSettings_raw->fetch_assoc()) {
		    $this->CamWorker($row['id'],$row['source']);
		}
		unset($row);
		unset($CamSettings_raw);
		
		$ArchiveCollectionTime = 0;
		
        // Гоняем бесконечный цикл			
        while(TRUE) {	
			//Чистим старые записи
			if ( (time() - $ArchiveCollectionTime) > $this->CoreSettings['segment_time_min']*60 ) {
				$ArchiveCollectionTime = time();
				exec('find '.$this->CoreSettings["path"].' -type f -mtime +'.$this->CoreSettings["TTL"].' -delete > /dev/null &');				
			}
			pcntl_signal_dispatch();
            sleep(1);      	   
		}
    } 


	protected function CamWorker($id,$source) { 
        // Создаем дочерний процесс
        // весь код после pcntl_fork() будет выполняться
        // двумя процессами: родительским и дочерним
        $pid = pcntl_fork();
        if ($pid == -1) {
            // Не удалось создать дочерний процесс
            error_log('Could not launch new worker, exiting');
            return FALSE;
        } 
        elseif ($pid) {
            // Этот код выполнится родительским процессом
            $this->WorkerPIDs[$pid] = 'id'.$id;
        } 
        else { 
            // А этот код выполнится дочерним процессом
			WHILE(TRUE) {
            $this->log("Запущен процесс камеры id".$id." с PID ".getmypid());
			exec('mkdir '.$this->CoreSettings["path"].'/id'.$id);	
			exec('ffmpeg -hide_banner -loglevel error -i "'.$source.'" -c copy -map 0 -f segment -segment_time '. $this->CoreSettings["segment_time_min"]*60 .' -segment_atclocktime 1 -segment_format mkv -strftime 1 "'.$this->CoreSettings["path"].'/id'.$id.'/%Y-%m-%d_%H-%M-%S.mkv" 1> log_id'.$id.'.txt 2>&1');
            $this->log("Перезапущен процесс камеры id".$id);			
			}
		}
	}
		
    public function SignalHandler($signo, $pid = null, $status = null) {
        switch($signo) {
            case SIGTERM:
				//Посылаем SIGTERM всем CHILD
				exec('killall ffmpeg');					
				foreach( $this->WorkerPIDs as $key => $value ) {
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
                    if ($pid && isset($this->WorkerPIDs[$pid])) {
                        // Удаляем дочерние процессы из списка
                        unset($this->WorkerPIDs[$pid]);
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