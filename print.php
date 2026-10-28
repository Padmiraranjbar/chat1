<?php
declare(strict_types=1);
$query=$_SERVER['QUERY_STRING']??'';
parse_str($query,$params);
unset($params['route']);
header('Location: index.php?'.http_build_query(array_merge(['route'=>'print'],$params)));
exit;
