<?php

require_once __DIR__.'/../vendor/autoload.php'; 

$app = new Silex\Application(); 

$app->get('/web_gui/', function() { 

	ob_start();
	include 'webgui.phtml';
	return (ob_get_clean());

}); 

$app->get('/api/1.0/platform/status', function (Silex\Application $app) {

});

$app->get('/api/1.0/platform/reload', function (Silex\Application $app) {

});

$app->get('/api/1.0/platform/restart', function (Silex\Application $app) {

});

$app->get('/api/1.0/camera/list', function (Silex\Application $app) {

});

$app->get('/api/1.0/camera/{camera}/settings', function (Silex\Application $app, $camera) {

});

$app->post('/api/1.0/camera/new', function (Silex\Application $app) {

});

$app->put('/api/1.0/camera/{camera}', function (Silex\Application $app, $camera) {

});

$app->delete('/api/1.0/camera/{camera}', function (Silex\Application $app, $camera) {

});

$app->get('/api/1.0/archive/list', function (Silex\Application $app) {

});

$app->get('//api/1.0/archive/camera/{camera}/list', function (Silex\Application $app, $camera) {

});

$app->get('/api/hello/{name}', function($name) use($app) { 
    return 'Hello '.$app->escape($name); 
}); 

$app->run(); 

?>