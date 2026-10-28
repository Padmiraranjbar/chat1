<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\Database;
use PDO;
abstract class Model {
 protected PDO $db;
 public function __construct(?PDO $db=null){$this->db=$db??Database::connect();}
}