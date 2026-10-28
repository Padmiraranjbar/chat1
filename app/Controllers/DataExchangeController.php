<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Controller;
final class DataExchangeController extends Controller {
 public function index(): void { require dirname(__DIR__,2).'/legacy/data_exchange.php'; }
}
