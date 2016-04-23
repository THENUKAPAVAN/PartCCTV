<?php
namespace PartCCTV\Module\MySQLi;

class createCon  {
    var $host = 'localhost';
    var $user = 'root';
    var $pass = 'cctv';
    var $db = 'cctv';
    var $myconn;

    function connect() {
        $con = mysqli_connect($this->host, $this->user, $this->pass, $this->db);
        if (!$con) {
            \PartCCTVCore::log('Could not connect to database!');
			exit;
        } else {
            $this->myconn = $con;
            \PartCCTVCore::log('Connection established!');}
        return $this->myconn;
    }

    function close() {
        mysqli_close($this->myconn);
        \PartCCTVCore::log('Connection closed!');
    }

}
?>