<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Controller;
final class FiscalYearController extends Controller {
 public function index(): void {
  // منطق افتتاح و اختتام به‌صورت مرحله‌ای به Model و View جدا منتقل خواهد شد.
  require dirname(__DIR__,2).'/legacy/fiscal_years.php';
 }
}