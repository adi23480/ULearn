<?php
require_once __DIR__.'/../config/bootstrap.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);exit('Method not allowed');}
csrf_check();$_SESSION=[];session_regenerate_id(true);go('auth/login.php');
