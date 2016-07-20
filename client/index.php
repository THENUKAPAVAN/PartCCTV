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
$ZMQContext = new ZMQContext();
$ZMQRequester = new ZMQSocket($ZMQContext, ZMQ::SOCKET_REQ);
$ZMQRequester->setSockOpt(ZMQ::SOCKOPT_RCVTIMEO, 1500);
$ZMQRequester->connect("tcp://127.0.0.1:5555");

//PDO
try {
    $DBH = new PDO($PartCCTV_ini['db']['dsn'], $PartCCTV_ini['db']['user'], $PartCCTV_ini['db']['password']);
}
catch(PDOException $e) {
    $Exception = 'PDO ConnError : '.$e->getMessage();            
} 

if(isset($Exception)) {
    $app->before(function () use($Exception) {
        throw new Exception($Exception);
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

$app->get('/api/1.0/platform/status', function () use ($app, $ZMQRequester) {

	try {
		$ZMQRequester->send(json_encode(array (	'action' => 'core_status' )));
	} catch (ZMQException $e) {	
		$app->abort(500, $e->getMessage());
	}

	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->get('/api/1.0/platform/settings', function () use($app, $DBH) {

	$result = $DBH->query("SELECT * FROM `cam_settings`");
    $result->setFetchMode(PDO::FETCH_ASSOC);
	for ($set = array (); $row = $result->fetch(); $set[] = $row);
	unset($row);

	return $app->json($set);

});

$app->put('/api/1.0/platform/settings', function (Request $request) use($DBH) {

    $STH = $DBH->prepare("UPDATE `cam_settings` SET `value` = :value WHERE `cam_settings`.`param` = :param");
    
	foreach ($request as $key => $value) {
        $STH->bindParam(':value', $value);
        $STH->bindParam(':param', $key);
		$STH->execute();
	}

	return new Response('OK', 200, ['Content-Type' => 'application/json']);

});

$app->get('/api/1.0/platform/log', function () use ($app, $ZMQRequester) {

	try {
		$ZMQRequester->send(json_encode(array (	'action' => 'core_log' )));
	} catch (ZMQException $e) {	
		$app->abort(500, $e->getMessage());
	}
	
	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->post('/api/1.0/platform/reload', function () use($app, $ZMQRequester) {

	try {
		$ZMQRequester->send(json_encode(array (	'action' => 'core_reload' )));
	} catch (ZMQException $e) {	
		$app->abort(500, $e->getMessage());
	}
	
	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->post('/api/1.0/platform/restart', function () use($app, $ZMQRequester) {

	try {
		$ZMQRequester->send(json_encode(array (	'action' => 'core_restart' )));
	} catch (ZMQException $e) {	
		$app->abort(500, $e->getMessage());
	}
	
	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->post('/api/1.0/platform/stop', function () use($app, $ZMQRequester) {
	
	try {
		$ZMQRequester->send(json_encode(array (	'action' => 'core_stop' )));
	} catch (ZMQException $e) {	
		$app->abort(500, $e->getMessage());
	}

	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->get('/api/1.0/camera/list', function () use($app, $ZMQRequester, $DBH) {

	$result = $DBH->query("SELECT * FROM `cam_list`");
    $result->setFetchMode(PDO::FETCH_ASSOC);
	for ($set = array (); $row = $result->fetch(); $set[] = $row);

	return $app->json($set);

});

// Under construction
$app->get('/api/1.0/camera/list1', function () use($app, $ZMQRequester, $DBH) {

	try {
		$ZMQRequester->send(json_encode(array (	'action' => 'core_workerpids' )));
	} catch (ZMQException $e) {	
		$app->abort(500, $e->getMessage());
	}

	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->get('/api/1.0/camera/log', function () use ($app, $ZMQRequester) {

	try {
		$ZMQRequester->send(json_encode(array (	'action' => 'cam_log' )));
	} catch (ZMQException $e) {	
		$app->abort(500, $e->getMessage());
	}

	return new Response($ZMQRequester->recv(), 200, ['Content-Type' => 'application/json']);

});

$app->post('/api/1.0/camera/new', function (Request $request) use($app, $ZMQRequester, $DBH) {
// TBD
});

$app->put('/api/1.0/camera/{camera}', function (Request $request, $camera) use($app, $ZMQRequester, $DBH) {
// TBD
});

$app->delete('/api/1.0/camera/{camera}', function ($camera) use($app, $ZMQRequester, $DBH) {
// TBD
});

$app->get('/api/1.0/archive/list', function () use($app, $DBH) {
// TBD
});

$app->get('//api/1.0/archive/camera/{camera}', function ($camera) use($app, $DBH) {
// TBD
});

$app->run(); 
