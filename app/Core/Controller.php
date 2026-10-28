<?php
declare(strict_types=1);
namespace App\Core;
abstract class Controller {
 protected function view(string $name,array $data=[]): void {
  extract($data,EXTR_SKIP);
  $file=dirname(__DIR__).'/Views/'.$name.'.php';
  if(!is_file($file)){http_response_code(500);exit('View not found');}
  require $file;
 }
 protected function redirect(string $url): never { header('Location: '.$url); exit; }
}