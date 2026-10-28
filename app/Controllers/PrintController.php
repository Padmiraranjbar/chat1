<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Controller;
final class PrintController extends Controller {
 public function index(): void {
  require dirname(__DIR__,2).'/legacy/print.php';
 }
}