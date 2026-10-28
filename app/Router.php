<?php
declare(strict_types=1);
namespace App;
use App\Controllers\HomeController;
use App\Controllers\FiscalYearController;
use App\Controllers\PrintController;
use App\Controllers\AccountsController;
final class Router {
 public function dispatch(): void {
  $route=(string)($_GET['route']??'home');
  switch($route){
   case 'home': (new HomeController())->index(); return;
   case 'fiscal-years': (new FiscalYearController())->index(); return;
   case 'print': (new PrintController())->index(); return;
   case 'accounts': (new AccountsController())->index(); return;\n   case 'data-exchange': (new DataExchangeController())->index(); return;
   default: http_response_code(404); echo 'صفحه پیدا نشد.';
  }
 }
}