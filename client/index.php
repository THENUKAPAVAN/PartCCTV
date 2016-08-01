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
$app['db'] = $PartCCTV_ini;

// Declare a PDO service.
$app['dbh'] = function () use ($app) {
    return new PDO($app['db']['db']['dsn'], $app['db']['db']['user'], $app['db']['db']['password']);
};

// Declare a ZMQ service.
$app['zmq'] = function () {
    $ZMQRequester = new ZMQSocket(new ZMQContext(), ZMQ::SOCKET_REQ);
    $ZMQRequester->setSockOpt(ZMQ::SOCKOPT_RCVTIMEO, 1500);
    $ZMQRequester->connect("tcp://127.0.0.1:5555");
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

	$result = $app['dbh']->query("SELECT * FROM `cam_settings`");
    $result->setFetchMode(PDO::FETCH_ASSOC);
	for ($set = array (); $row = $result->fetch(); $set[] = $row);

	return $app->json($set);

});

// To Be Tested
$app->put('/api/1.0/platform/settings', function (Request $request) use($app) {
    
    if(empty($request->request)) {
		$app->abort(400, '400 Bad Request');        
    }

    $STH = $app['dbh']->prepare("UPDATE `cam_settings` SET `value` = :value WHERE `cam_settings`.`param` = :param");
    
    foreach ($request->request as $key => $value) {
        $STH->bindParam(':value', $value);
        $STH->bindParam(':param', $key);
        $STH->execute();
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
	$result = $app['dbh']->query("SELECT * FROM `cam_list`");
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

	$result = $app['dbh']->prepare("SELECT * FROM `cam_list` WHERE `id`= ?");
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

$app->post('/api/1.0/camera/new', function (Request $request) use($app) {
    //TBD
    
    // RestartIsRequired flag
    try {
        $app['zmq']->send(json_encode(array (	'action' => 'core_restart_is_required' )));
        return new Response($app['zmq']->recv(), 200, ['Content-Type' => 'application/json']);
    } 
    catch(UnexpectedValueException $e) {
        return new Response('Partially OK', 200, ['Content-Type' => 'application/json']);            
    }
    
});

$app->put('/api/1.0/camera/{camera}/', function (Request $request, $camera) use($app) {
    //TBD
    
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
    
    //TBD
    
    // RestartIsRequired flag
    try {
        $app['zmq']->send(json_encode(array (	'action' => 'core_restart_is_required' )));    
        return new Response($app['zmq']->recv(), 200, ['Content-Type' => 'application/json']);
    } 
    catch(UnexpectedValueException $e) {
        return new Response('Partially OK', 200, ['Content-Type' => 'application/json']);            
    }
    
});

$app->get('/api/1.0/archive/list', function () use($app) {
    
    //TBD
    
});

$app->get('//api/1.0/archive/{camera}/', function ($camera) use($app) {
    
    //TBD
    
});

$app->run(); 
