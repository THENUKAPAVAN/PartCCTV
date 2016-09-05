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

// Declare parameters.
$app['db'] = $PartCCTV_ini['db'];

// Declare a PDO service.
$app['dbh'] = function () use ($app) {
    return new PDO($app['db']['dsn'], $app['db']['user'], $app['db']['password']);
};

// Declare a ZMQ service.
$app['zmq'] = function () {
    $ZMQRequester = new ZMQSocket(new ZMQContext(), ZMQ::SOCKET_REQ);
    $ZMQRequester->setSockOpt(ZMQ::SOCKOPT_RCVTIMEO, 1500);
    $ZMQRequester->connect('tcp://127.0.0.1:5555');
    return $ZMQRequester;
};
    
$app->get('/', function () use ($app) {
    return $app->redirect('/web_gui/');
});

$app->get('/web_gui/', function() { 

	ob_start();
	require_once 'webgui.phtml';
	return ob_get_clean();

}); 

$app->get('/api/1.0/platform/status', function () use ($app) {

	$app['zmq']->send(json_encode(array (	'action' => 'core_status' )));
    $Response = $app['zmq']->recv();
    
    try{
    	return new Response($Response, 200, ['Content-Type' => 'application/json']);    
    }
    catch(UnexpectedValueException $e) {
        return new Response('Seems like PartCCTV Core is down!', 500, ['Content-Type' => 'application/json']);            
    } 

});

$app->get('/api/1.0/platform/settings', function () use($app) {

	$result = $app['dbh']->query('SELECT * FROM `core_settings`');
    $result->setFetchMode(PDO::FETCH_ASSOC);
	for ($set = array (); $row = $result->fetch(); $set[] = $row);

	return $app->json($set);

});

$app->post('/api/1.0/platform/settings', function (Request $request) use($app) {
	
	if(count($request->request) === 0) {
		$app->abort(400, '400 Bad Request');        
    }
	
    $STH = $app['dbh']->prepare('UPDATE core_settings SET value = :value WHERE param = :param');
    
    foreach ($request->request as $param => $value) {
        $STH->execute(array('value' => $value, 'param' => $param));
    }
	
    // RestartIsRequired flag
    try {
        $app['zmq']->send(json_encode(array (	'action' => 'core_restart_is_required' )));
        return new Response($app['zmq']->recv(), 200, ['Content-Type' => 'application/json']);
    } 
    catch(UnexpectedValueException $e) {
        return new Response('Partially OK', 200, ['Content-Type' => 'application/json']);            
    }        
    
});

$app->get('/api/1.0/platform/log', function () use ($app) {

	$app['zmq']->send(json_encode(array (	'action' => 'core_log' )));
	
	return new Response($app['zmq']->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->post('/api/1.0/platform/reload', function () use($app) {

	$app['zmq']->send(json_encode(array (	'action' => 'core_reload' )));
	
	return new Response($app['zmq']->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->post('/api/1.0/platform/restart', function () use($app) {

	$app['zmq']->send(json_encode(array (	'action' => 'core_restart' )));
	
	return new Response($app['zmq']->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->post('/api/1.0/platform/stop', function () use($app) {
	
    $app['zmq']->send(json_encode(array (	'action' => 'core_stop' )));

	return new Response($app['zmq']->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->get('/api/1.0/camera/list', function () use($app) {

    // Массив $ar1 из БД
	$result = $app['dbh']->query('SELECT * FROM `cam_list`');
    $result->setFetchMode(PDO::FETCH_ASSOC);
	for ($ar1 = array (); $row = $result->fetch(); $ar1[] = $row);

    // Массив $ar2 PIDов из ядра (через ZMQ)
	$app['zmq']->send(json_encode(array (	'action' => 'core_workerpids' )));	

    $ar2 = json_decode($app['zmq']->recv(), true);
    
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

$app->get('/api/1.0/camera/{camera}/', function ($camera) use($app) {

	$result = $app['dbh']->prepare('SELECT * FROM `cam_list` WHERE `id`= ?');
	$result->bindParam(1, $camera);
	$result->execute();
    $result->setFetchMode(PDO::FETCH_ASSOC);
	for ($ar = array (); $row = $result->fetch(); $ar[] = $row);

	return $app->json($ar);
    
});

$app->get('/api/1.0/camera/log', function () use ($app) {
    
	$app['zmq']->send(json_encode(array (	'action' => 'cam_log' )));

	return new Response($app['zmq']->recv(), 200, ['Content-Type' => 'application/json']);
    
});

$app->put('/api/1.0/camera/new', function (Request $request) use($app) {
		
	if(count($request->request) === 0) {
		$app->abort(400, '400 Bad Request');        
    }
	
	// Проверка: запрос должен быть полным
	if(!isset($request->request['title'], $request->request['source'])) {
		return new Response('Incomplete request!', 500, ['Content-Type' => 'application/json']);    
	}
	
	$STH = $app['dbh']->prepare('INSERT INTO cam_list (id, title, enabled, source) VALUES (NULL, :title, 0, :source)');
	$STH->execute(array('title' => $request->request['title'], 'source' => $request->request['source']));
	
	return new Response('OK', 200, ['Content-Type' => 'application/json']);       
    
});

$app->post('/api/1.0/camera/{camera}/', function (Request $request, $camera) use($app) {
	
	if(count($request->request) === 0) {
		$app->abort(400, '400 Bad Request');        
    }
	
	// Проверка: камера должна существовать
	$STH = $app['dbh']->prepare('SELECT COUNT(*) FROM cam_list WHERE id = :id');
	$STH->execute(array('id' => $camera));
	$CameraExists = $STH->fetch(PDO::FETCH_NUM)[0];
	if(!$CameraExists) {
		return new Response('Unknown Camera ID!', 500, ['Content-Type' => 'application/json']);    
	}
	
    $STH = $app['dbh']->prepare('UPDATE cam_list` SET :key = :value WHERE id = :id');
    
    foreach ($request->request as $key => $value) {
        $STH->execute(array('key' => $key, 'value' => $value, 'id' => $camera));
    }
    
    // RestartIsRequired flag
    try {
        $app['zmq']->send(json_encode(array (	'action' => 'core_restart_is_required' )));
        return new Response($app['zmq']->recv(), 200, ['Content-Type' => 'application/json']);
    } 
    catch(UnexpectedValueException $e) {
        return new Response('Partially OK', 200, ['Content-Type' => 'application/json']);            
    }
    
});

$app->delete('/api/1.0/camera/{camera}/', function ($camera) use($app) {
	
	// Проверка: камера должна существовать
	$STH = $app['dbh']->prepare('SELECT COUNT(*) FROM cam_list WHERE id = :id');
	$STH->execute(array('id' => $camera));
	$CameraExists = $STH->fetch(PDO::FETCH_NUM)[0];
	if(!$CameraExists) {
		return new Response('Unknown Camera ID!', 500, ['Content-Type' => 'application/json']);    
	}
	
	// Проверка: камера должна быть отключена
	$STH = $app['dbh']->prepare('SELECT COUNT(*) FROM cam_list WHERE id = :id AND enabled = 0');
	$STH->execute(array('id' => $camera));
	$CameraIsDisabled = $STH->fetch(PDO::FETCH_NUM)[0];
	if(!$CameraIsDisabled) {
		return new Response('Camera must be disabled!', 500, ['Content-Type' => 'application/json']);    
	}
    
	$STH = $app['dbh']->prepare('DELETE FROM cam_list WHERE id = :id AND enabled = 0');
	$STH->execute(array('id' => $camera));
	    
    // RestartIsRequired flag
    try {
        $app['zmq']->send(json_encode(array (	'action' => 'core_restart_is_required' )));    
        return new Response($app['zmq']->recv(), 200, ['Content-Type' => 'application/json']);
    } 
    catch(UnexpectedValueException $e) {
        return new Response('Partially OK', 200, ['Content-Type' => 'application/json']);            
    }
    
});

/* $app->get('/api/1.0/archive/list', function () use($app) {
    
    //TBD
    
});

$app->get('//api/1.0/archive/{camera}/', function ($camera) use($app) {
    
    //TBD
    
}); */

$app->run(); 
