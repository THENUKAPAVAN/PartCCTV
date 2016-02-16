<?php
//бОльшая часть кода оттуда: http://habrahabr.ru/post/134620/

// Без этой директивы PHP не будет перехватывать сигналы
declare(ticks=1); 



class PartCCTVClass {
    // Максимальное количество дочерних процессов (зависит от количества включенных камер)
    // Когда установится в TRUE, демон завершит работу
    protected $stop_server = FALSE;
    // Здесь будем хранить запущенные дочерние процессы
    protected $currentJobs = array();

    public function __construct() {
        echo "Сonstructed daemon controller".PHP_EOL;
        // Ждем сигналы SIGTERM и SIGCHLD
        pcntl_signal(SIGTERM, array($this, "childSignalHandler"));
        pcntl_signal(SIGCHLD, array($this, "childSignalHandler"));
    }

    public function run() {
        echo "Запуск платформы PartCCTV...".PHP_EOL;
		$mysql = mysqli_connect('localhost', 'root', 'cctv', 'cctv');
		$maxProcesses = mysqli_num_rows(mysqli_query($mysql, "SELECT * FROM `cam_list` WHERE `enabled` = '1'"));
		echo "Максимум процессов: $maxProcesses".PHP_EOL;
        // Гоняем бесконечный цикл
        while(TRUE) {
            // Если уже запущено максимальное количество дочерних процессов, ждем их завершения
            while(count($this->currentJobs) >= $maxProcesses) {
                 sleep(60);
            }

			$camera = mysqli_query($mysql, "SELECT * FROM `cam_list` WHERE `enabled` = '1'");
			mysqli_close($mysql);  

            //Для каждой камеры запускаем свой дочерний процесс			
			while ($row = $camera->fetch_assoc()) {
				echo "Запускаем процесс...".PHP_EOL;
			    $this->launchJob($row[id],$row[source]);
			}
        } 
    } 


protected function launchJob($id,$source) { 
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
			while(TRUE) {
			exec('ffmpeg -i "'.$source.'" -c copy -map 0 -f segment -segment_time 3600 -segment_atclocktime 1 -segment_format mp4 "id'.$id.'-%03d.mp4"' . ' > /dev/null');
			sleep(60);
			echo "Прервалась запись камеры с id ".$id." ,перезапускаю...".PHP_EOL;
			}
        } 
        return TRUE; 
    } 
	
    public function childSignalHandler($signo, $pid = null, $status = null) {
        switch($signo) {
            case SIGTERM:
                echo 'Платформа получила сигнал SIGTERM, завершение работы...'.PHP_EOL;
                exec('kill -'.getmypid());
                break;
            case SIGKILL:
                echo 'Платформа получила сигнал SIGKILL, завершение работы...'.PHP_EOL;
                exec('kill -'.getmypid());
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
}	}
?>