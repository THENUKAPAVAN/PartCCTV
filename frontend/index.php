<?php

require_once __DIR__.'/../vendor/autoload.php'; 

$app = new Silex\Application(); 

$app->get('/web_gui/{name}', function($name) use($app) { 
    return 'Hello GUI'.$app->escape($name); 
}); 

$app->get('/api/hello/{name}', function($name) use($app) { 
    return 'Hello '.$app->escape($name); 
}); 

$app->run(); 

?>