<?php
//MySQL
$mysql = new mysqli('localhost', 'root', 'cctv', 'cctv');

//ZeroMQ
$context = new ZMQContext();
$requester = new ZMQSocket($context, ZMQ::SOCKET_REQ);
$requester->connect("tcp://127.0.0.1:5555");

if (isset($_GET['action'])) {
	switch($_GET['action']) {
		case 'restart':
			$requester->send("kill");
			$reply = $requester->recv();
			if ($reply == "OK") {
				echo "<script>if(!alert('Платформа перезапущена!')){window.location='/list.php';}</script>";
				exit;
			}
			break;
		default:
	}
}

$cam_list = $mysql->query("SELECT * FROM `cam_list`"); 
$settings = $mysql->query("SELECT * FROM `cam_list`"); 

//STATUS
$requester->send("status");
$status = json_decode($requester->recv(), true);
//LOG
$requester->send("log");
$log = $requester->recv();

?>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PartCCTV</title>
<!-- Latest compiled and minified CSS -->
<link rel="stylesheet" href="https://yastatic.net/bootstrap/3.3.6/css/bootstrap.min.css" integrity="sha384-1q8mTJOASx8j1Au+a5WDVnPi2lkFfwwEAa8hDDdjZlpLegxhjVME1fgjWPGmkzs7" crossorigin="anonymous">
<!-- Optional theme -->
<link rel="stylesheet" href="https://yastatic.net/bootstrap/3.3.6/css/bootstrap-theme.min.css" integrity="sha384-fLW2N01lMqjakBkx3l/M9EahuwpSfeNvV63J5ezn3uZzapT0u7EYsXMjQV+0En5r" crossorigin="anonymous">

<script src="https://yandex.st/jquery/1.12.0/jquery.min.js"></script>
<script src="https://yastatic.net/bootstrap/3.3.6/js/bootstrap.min.js" integrity="sha384-0mSbJDEHialfmuBBQP6A4Qrprq5OVfW37PRR3j5ELqxss1yVqOtnepnHVP9aJ7xS" crossorigin="anonymous"></script>
</head>
<body>

<div class="navbar navbar-default navbar-static-top">
	<div class="container">
		<div class="navbar-header">
			<button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#navbar-ex-collapse">
			<span class="sr-only">Toggle navigation</span>
			<span class="icon-bar"></span>
			<span class="icon-bar"></span>
			<span class="icon-bar"></span>
			</button>
			<a class="navbar-brand" href="/list.php"><span>PartCCTV</span></a>
		</div>

		<div class="collapse navbar-collapse" id="navbar-ex-collapse">
			<ul class="nav navbar-nav navbar-right"><li class="active"><a href="/list.php">Камеры</a></li><li><a href="/archive/">Архив</a></li></ul>
		</div>
	</div>
</div>

<div class="section">
	<div class="container">
		<div class="row">
			<div class="col-md-12">
				<div class="progress progress-striped">
					<div class="progress-bar progress-bar-success progress-bar-striped active" role="progressbar" style="width: <?=(100-$status['free_space']/$status['total_space']*100)?>%;">На <?=$status['path']?> свободно <?=$status['free_space']?>Гб из <?=$status['total_space']?>Гб</div>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-md-12">
				<a class="btn btn-lg btn-success" data-toggle="modal" data-target="#newcam">Добавить новую камеру</a>
				<a class="btn btn-lg btn-danger" href="/list.php?action=restart">Перезагрузка платформы</a>			
				<a class="btn btn-lg btn-info" data-toggle="modal" data-target="#settings">Настройки платформы</a>
				<a class="btn btn-lg btn-default" data-toggle="modal" data-target="#log">Лог</a>						
			</div>
		</div>
		<div class="row">
			<div class="col-md-12">
				<hr>
			</div>
		</div>
	</div>
	<div class="container">
		<div class="row">
		
			<?php
			while ($cam = $cam_list->fetch_assoc()) {
				echo'			<div class="col-md-12">
				<div class="panel panel-warning">
					<div class="panel-heading">
						<h6 class="panel-title text-warning"><b>'.$cam["title"].' (id'.$cam["id"].')</b></h6>
					</div>
					<div class="panel-body">
						<div class="alert alert-dismissable alert-success">
							<b>Ошибок не обнаружено...</b>
						</div>
						<div class="btn-group btn-group-lg">
							<a data-toggle="modal" data-target="#cam_settings" class="btn btn-warning">Настройки</a>
							<a href="/archive/id'.$cam["id"].'" class="btn btn-primary">Архив</a>
						</div>
					</div>
				</div>
			</div>';
			}
			?>
		</div>
	</div>
</div>

<!-- New Cam Modal -->
<div class="modal fade" id="newcam" tabindex="-1" role="dialog" aria-labelledby="newcam">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="newcam">Новая камера</h4>
      </div>
      <div class="modal-body">
		<form action="" method="post">
		<fieldset>
         
            <div class="control-group">
              <label class="control-label" for="name">Название</label>
              <div class="controls">
                <input type="text" id="name" name="name" required class="form-control input-lg">
              </div>
            </div>
		
            <div class="control-group">
              <label class="control-label" for="source">Источник</label>
              <div class="controls">
                <input type="text" id="source" name="source" required class="form-control input-lg">
              </div>
            </div>
		    <br>
			<input class="btn btn-primary" type="submit" value="Сохранить">
		</fieldset>
		</form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Добавить</button>
      </div>
    </div>
  </div>
</div>

<!-- Camera Settings Modal -->
<div class="modal fade" id="cam_settings" tabindex="-1" role="dialog" aria-labelledby="cam_settings">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="cam_settings">Настройки камеры</h4>
      </div>
      <div class="modal-body">
		<form action="" method="post">
		<fieldset>
            <div class="control-group">
              <label class="control-label" for="id">ID</label>
              <div class="controls">
                <input type="text" id="id" name="id" required class="form-control input-lg">
              </div>
            </div>
         
            <div class="control-group">
              <label class="control-label" for="name">Название</label>
              <div class="controls">
                <input type="text" id="name" name="name" required class="form-control input-lg">
              </div>
            </div>
		
            <div class="control-group">
              <label class="control-label" for="source">Источник</label>
              <div class="controls">
                <input type="text" id="source" name="source" required class="form-control input-lg">
              </div>
            </div>
		
			<div class="checkbox">
				<label>
				<input type="checkbox"> Камера включена
				</label>
			</div>
		
			<input class="btn btn-primary" type="submit" value="Сохранить">
		</fieldset>
		</form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Отмена</button>
      </div>
    </div>
  </div>
</div>

<!-- Settings Modal -->
<div class="modal fade" id="settings" tabindex="-1" role="dialog" aria-labelledby="settings">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="settings">Настройки платформы</h4>
      </div>
      <div class="modal-body">
		<form action="" method="post">
		<fieldset>
            <div class="control-group">
              <label class="control-label" for="id">ID</label>
              <div class="controls">
                <input type="text" id="id" name="id" required class="form-control input-lg">
              </div>
            </div>
         
            <div class="control-group">
              <label class="control-label" for="name">Название</label>
              <div class="controls">
                <input type="text" id="name" name="name" required class="form-control input-lg">
              </div>
            </div>
		
            <div class="control-group">
              <label class="control-label" for="source">Источник</label>
              <div class="controls">
                <input type="text" id="source" name="source" required class="form-control input-lg">
              </div>
            </div>
		
			<div class="checkbox">
				<label>
				<input type="checkbox"> Камера включена
				</label>
			</div>
		
			<input class="btn btn-primary" type="submit" value="Сохранить">
		</fieldset>
		</form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Отмена</button>
      </div>
    </div>
  </div>
</div>

<!-- Log Modal -->
<div class="modal fade" id="log" tabindex="-1" role="dialog" aria-labelledby="log">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="log">Лог</h4>
      </div>
      <div class="modal-body">
		<pre><?=$log?></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Закрыть</button>
      </div>
    </div>
  </div>
</div>

</body>
</html>