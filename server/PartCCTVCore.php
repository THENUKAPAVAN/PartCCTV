<?php
// ------
// PartCCTVCore.php
// (c) 2016 m1ron0xFF
// @license: CC BY-NC-ND 4.0
// ------

require "../vendor/autoload.php";
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\PlivoHandler;

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
		/* $SMSHandler = new PlivoHandler($token,$auth_id,$fromPhoneNumber,$toPhoneNumber, Logger::ALERT); */

		// Main Log
		$this->Logger  = new Logger('PartCCTV');
		$this->Logger->pushHandler($Handler);
		/* $this->Logger->pushHandler($SMSHandler); */

		// Cams Log
		$this->CamLogger = new Logger('PartCCTV_CAM');
		$this->CamLogger->pushHandler($CamHandler);
		/* $this->CamLogger->pushHandler($SMSHandler);	 */		
    }

    public function run() {	

		$this->Logger->info('Запуск платформы PartCCTV');
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
		
		$ZMQContext = new ZMQContext();
		
		//  Socket to talk to clients
		$ZMQResponder = new ZMQSocket($ZMQContext, ZMQ::SOCKET_REP);
		$ZMQResponder->bind("tcp://*:5555");
		$this->Logger->debug('Запущен ZeroMQ сервер');
		while (!$this->IF_Shutdown) {
			
			//  Чистим старые записи
			if ( (time() - $ArchiveCollectionTime) > $this->CoreSettings['segment_time_min']*60 ) {
				$this->Logger->debug('Очистка старых записей');
				$ArchiveCollectionTime = time();
				exec('find '.$this->CoreSettings["path"].' -type f -mtime +'.$this->CoreSettings["TTL"].' -delete > /dev/null &');				
			}
			
			pcntl_signal_dispatch();
            		
			//  Wait for next request from client
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
						break;
						
					case 'core_status':
						$status = array(
							'total_space' => round(disk_total_space($this->CoreSettings['path'])/1073741824),
							'free_space' => round(disk_free_space($this->CoreSettings['path'])/1073741824),
							'path' => $this->CoreSettings['path'],
							'IF_Restart_Required' => $this->IF_Restart_Required			
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
			
			WHILE(TRUE) {
				exec('ffmpeg -hide_banner -loglevel error -i "'.$source.'" -c copy -map 0 -f segment -segment_time '. $this->CoreSettings["segment_time_min"]*60 .' -segment_atclocktime 1 -segment_format mkv -strftime 1 "'.$this->CoreSettings["path"].'/id'.$id.'/%Y-%m-%d_%H-%M-%S.mkv" 1> log_id'.$id.'.txt 2>&1');
				sleep(1);
				$ZMQRequester->send(json_encode(array (	'action' => 'worker_if_shutdown' )));
				if($ZMQRequester->recv() == 1) {
					$this->CamLogger->info("Завершается процесс камеры id".$id." с PID ".getmypid());
					exit;
				} else {
					$this->CamLogger->warning("Перезапущен процесс камеры id".$id);				
				}			
			}
		}
	}
		
    public function SignalHandler($signo, $pid = null, $status = null) {
        switch($signo) {
            case SIGTERM:
				$this->IF_Shutdown = 1;
				sleep(1);
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