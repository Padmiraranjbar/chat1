<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Controller;
final class HomeController extends Controller {
 public function index(): void {
  // نسخه قدیمی تا زمان انتقال فرم‌های خریدوفروش به Model/View مستقل حفظ می‌شود.
  require dirname(__DIR__,2).'/legacy/index.php';
 }
}