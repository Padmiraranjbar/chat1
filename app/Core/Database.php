<?php
declare(strict_types=1);
namespace App\Core;
use PDO;
final class Database {
 public static function connect(): PDO {
  $file=dirname(__DIR__,2).'/config.php';
  if(!is_file($file)){http_response_code(500);exit('ابتدا config.example.php را به config.php کپی و تنظیم کنید.');}
  $c=require $file;
  return new PDO("mysql:host={$c['host']};dbname={$c['database']};charset=utf8mb4",$c['username'],$c['password'],[
   PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
 }
}