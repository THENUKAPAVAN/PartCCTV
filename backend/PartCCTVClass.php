<?php
//бОльшая часть кода оттуда: http://habrahabr.ru/post/134620/

// Без этой директивы PHP не будет перехватывать сигналы
declare(ticks=1); 

class PartCCTVClass {
    // Здесь будем хранить запущенные дочерние процессы
    protected $currentJobs = array();
	protected $Classpid;

    public function __construct() {
		echo "---".PHP_EOL;
		echo "---".PHP_EOL;
        echo "Сonstructed PartCCTV daemon controller".PHP_EOL;
        // Ждем сигналы SIGTERM и SIGCHLD
        pcntl_signal(SIGTERM, array($this, "childSignalHandler"));
        pcntl_signal(SIGCHLD, array($this, "childSignalHandler"));
    }

    public function run() {
        echo "Запуск платформы PartCCTV...".PHP_EOL;
		$this->Classpid = getmypid();
		
		$mysql = mysqli_connect('localhost', 'root', 'cctv', 'cctv');
		$maxProcesses = mysqli_num_rows(mysqli_query($mysql, "SELECT * FROM `cam_list` WHERE `enabled` = '1'"));
		echo "Максимум процессов: $maxProcesses".PHP_EOL;
		$camera = mysqli_query($mysql, "SELECT * FROM `cam_list` WHERE `enabled` = '1'");
		$params = mysqli_fetch_assoc(mysqli_query($mysql, "SELECT * FROM `cam_settings`"));
		mysqli_close($mysql);
		
		$this->launchZeroMQ('/media/cctv');		
		
		//Для каждой камеры запускаем свой дочерний процесс			
		while ($row = $camera->fetch_assoc()) {
		    $this->launchJob($row['id'],$row['source'],'/media/cctv');
		}
		
        // Гоняем бесконечный цикл
        while(TRUE) {			
            // Если уже запущено максимальное количество дочерних процессов
            while(count($this->currentJobs) >= $maxProcesses) {
				//Чистим старые записи				 
			    exec("find /media/cctv -type f -mtime +2 -delete > /dev/null &");
                sleep(600);
            }
			echo "Аварийная перезагрузка платформы: дочерних процессов меньше, чем было".PHP_EOL;
			exec('bash restart.sh '.$this->Classpid.' > /dev/null &');
        } 
    } 


	protected function launchJob($id,$source,$path) { 
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
            echo "Запущен процесс с ID ".getmypid().PHP_EOL;
            echo "Начинаю запись камеры с id ".$id.PHP_EOL;
			exec('mkdir '.$path.'/id'.$id);	
			while(TRUE) {
			exec('ffmpeg -i "'.$source.'" -c copy -map 0 -f segment -segment_time 900 -segment_atclocktime 1 -segment_format mp4 -strftime 1 "'.$path.'/id'.$id.'/%Y-%m-%d_%H-%M-%S.mp4"');
			sleep(30);
			echo "Прервалась запись камеры с id ".$id." ,перезапускаю...".PHP_EOL;
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
        } 
        else { 
            // А этот код выполнится дочерним процессом
            echo "Запущен ZeroMQ сервер...".PHP_EOL;
            
			$context = new ZMQContext(1);
			//  Socket to talk to clients
			$responder = new ZMQSocket($context, ZMQ::SOCKET_REP);
			$responder->bind("tcp://127.0.0.1:5555");
			while (true) {
				//  Wait for next request from client
				$request = $responder->recv();
				printf ("Received request: [%s]\n", $request);
				switch($request) {
					case 'status':
						$status = array('free_space' => disk_total_space($path), 'disk_free_space' => disk_free_space($path), 'path' => $path, array_keys($this->currentJobs), 'e' => 5);
						$responder->send(json_encode($status));
						break;						
					case 'kill':
						$responder->send("OK");   
						exec('bash restart.sh '.$this->Classpid.' > /dev/null &');
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
		
		$keys = array_keys($this->currentJobs);
		
        switch($signo) {
            case SIGTERM:
                echo 'Платформа получила сигнал SIGTERM, завершение работы...'.PHP_EOL;
				//КОСТЫЛЬ
				exec('killall ffmpeg');
				exec('killall php');		
                exit(1);
                break;
            case SIGKILL:
                echo 'Платформа получила сигнал SIGKILL, завершение работы...'.PHP_EOL;
				//КОСТЫЛЬ
				exec('killall -s 9 ffmpeg');
				exec('killall -s 9 php');					
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