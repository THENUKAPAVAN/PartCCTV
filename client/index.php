<?php
// ------
// RESTful API Backend
// index.php
// (c) 2016 m1ron0xFF
// @license: CC BY-NC-SA 4.0
// ------

require_once __DIR__.'/../vendor/autoload.php'; 
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

$PartCCTV_ini = parse_ini_file(__DIR__.'/../PartCCTV.ini', true);

$app = new Silex\Application();
$app['debug'] = $PartCCTV_ini['silex']['debug'];

// ZeroMQ
try {
    $ZMQContext = new ZMQContext();
    $ZMQRequester = new ZMQSocket($ZMQContext, ZMQ::SOCKET_REQ);
    $ZMQRequester->setSockOpt(ZMQ::SOCKOPT_RCVTIMEO, 1500);
    $ZMQRequester->connect("tcp://127.0.0.1:5555");
}
catch(ZMQException $e) {
    $Exception = $e->getMessage();    
    $app->before(function () use($Exception) {
        throw new ZMQException($Exception);
    });
}

//PDO
try {
    $DBH = new PDO($PartCCTV_ini['db']['dsn'], $PartCCTV_ini['db']['user'], $PartCCTV_ini['db']['password']);
}
catch(PDOException $e) {
    $Exception = $e->getMessage();    
    $app->before(function () use($Exception) {
        throw new PDOException($Exception);
    });
}
    
$app->get('/', function () use ($app) {
    return $app->redirect('/web_gui/');
});

$app->get('/web_gui/', function() { 

	ob_start();
	include 'webgui.phtml';
	return ob_get_clean();

}); 

$app->get('/api/1.0/platform/status', function () use ($ZMQRequester) {

	$ZMQRequester->send(json_encode(array (	'action' => 'core_status' )));
    $Response = $ZMQRequester->recv();
    
    if($Response === false) {
        throw new Exception('Seems like PartCCTV Core is down!');
    }

	return new Response($Response, 200, ['Content-Type' => 'application/json']);

});

$app->get('/api/1.0/platform/settings', function () use($app, $DBH) {

	$result = $DBH->query("SELECT * FROM `cam_settings`");
    $result->setFetchMode(PDO::FETCH_ASSOC);
	for ($set = array (); $row = $result->fetch(); $set[] = $row);
	unset($row);

	return $app->json($set);

});

// To Be Tested
$app->put('/api/1.0/platform/settings', function (Request $request) use($app, $DBH) {
    
    if(empty($request->request)) {
		$app->abort(400, '400 Bad Request');        
    } else {

        $STH = $DBH->prepare("UPDATE `cam_settings` SET `value` = :value WHERE `cam_settings`.`param` = :param");
        
        foreach ($request->request as $key => $value) {
            $STH->bindParam(':value', $value);
            $STH->bindParam(':param', $key);
            $STH->execute();
        }

        return new Response('OK', 200, ['Content-Type' => 'application/json']);

    }
});

$app->get('/api/1.0/platform/log', function () use ($ZMQRequester) {

	$ZMQRequester->send(json_encode(array (	'action' => 'core_log' )));
	
	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->post('/api/1.0/platform/reload', function () use($ZMQRequester) {

	$ZMQRequester->send(json_encode(array (	'action' => 'core_reload' )));
	
	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->post('/api/1.0/platform/restart', function () use($ZMQRequester) {

	$ZMQRequester->send(json_encode(array (	'action' => 'core_restart' )));
	
	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->post('/api/1.0/platform/stop', function () use($ZMQRequester) {
	
    $ZMQRequester->send(json_encode(array (	'action' => 'core_stop' )));

	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->get('/api/1.0/camera/list', function () use($app, $ZMQRequester, $DBH) {

    // Массив $ar1 из БД
	$result = $DBH->query("SELECT * FROM `cam_list`");
    $result->setFetchMode(PDO::FETCH_ASSOC);
	for ($ar1 = array (); $row = $result->fetch(); $ar1[] = $row);

    // Массив $ar2 PIDов из ядра (через ZMQ)
	$ZMQRequester->send(json_encode(array (	'action' => 'core_workerpids' )));	

    $ar2 = json_decode($ZMQRequester->recv(), true);
    
    $ar2 = array_flip($ar2);
    foreach($ar1 as &$i) {
       // Если во втором массиве есть элемент с соответствующим id
       if (isset($ar2[$i['id']])) {
          // Добавляем его в первый массив
          $i['pid'] = $ar2[$i['id']];
       } else {
          //PIDа нет, добавляем null
          $i['pid'] = null;
       }
    }  

	return $app->json($ar1);
    
});

$app->get('/api/1.0/camera/log', function () use ($ZMQRequester) {
    
	$ZMQRequester->send(json_encode(array (	'action' => 'cam_log' )));

	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);
    
});

$app->post('/api/1.0/camera/new', function (Request $request) use($ZMQRequester, $DBH) {
    //TBD
    
    // RestartIsRequired flag
    try {
        $ZMQRequester->send(json_encode(array (	'action' => 'core_restart_is_required' )));
        $Response = $ZMQRequester->recv();
        
        if($Response === false) {
            throw new Exception('Seems like PartCCTV Core is down!');
        }

        return new Response($Response, 200, ['Content-Type' => 'application/json']);
    } 
    catch(Exception $e) {
        return new Response('Partialy OK', 200, ['Content-Type' => 'application/json']);            
    }
});

$app->put('/api/1.0/camera/{camera}', function (Request $request, $camera) use($ZMQRequester, $DBH) {
    //TBD
    
    // RestartIsRequired flag
    try {
        $ZMQRequester->send(json_encode(array (	'action' => 'core_restart_is_required' )));
        $Response = $ZMQRequester->recv();
        
        if($Response === false) {
            throw new Exception('Seems like PartCCTV Core is down!');
        }

        return new Response($Response, 200, ['Content-Type' => 'application/json']);
    } 
    catch(Exception $e) {
        return new Response('Partialy OK', 200, ['Content-Type' => 'application/json']);            
    }
});

$app->delete('/api/1.0/camera/{camera}', function ($camera) use($ZMQRequester, $DBH) {
    //TBD
    
    // RestartIsRequired flag
    try {
        $ZMQRequester->send(json_encode(array (	'action' => 'core_restart_is_required' )));
        $Response = $ZMQRequester->recv();
        
        if($Response === false) {
            throw new Exception('Seems like PartCCTV Core is down!');
        }

        return new Response($Response, 200, ['Content-Type' => 'application/json']);
    } 
    catch(Exception $e) {
        return new Response('Partialy OK', 200, ['Content-Type' => 'application/json']);            
    }
});

$app->get('/api/1.0/archive/list', function () use($app, $DBH) {
    //TBD
});

$app->get('//api/1.0/archive/{camera}', function ($camera) use($app, $DBH) {
    //TBD
});

$app->run(); 
