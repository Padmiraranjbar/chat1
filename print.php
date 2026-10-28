<?php
declare(strict_types=1);
declare(strict_types=1);
$query=$_SERVER['QUERY_STRING']??'';
header('Location: index.php?route=print'.($query!==''?'&'.$query:''));
exit;
