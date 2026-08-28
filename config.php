<?php
$dbname = 'mirza_test';
$usernamedb = 'mirza';
$passworddb = 'mirzaTest123!';
$connect = mysqli_connect("localhost", $usernamedb, $passworddb, $dbname);
if ($connect->connect_error) { die("error" . $connect->connect_error); }
mysqli_set_charset($connect, "utf8mb4");
$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
$dsn = "mysql:host=localhost;dbname=$dbname;charset=utf8mb4";
try { $pdo = new PDO($dsn, $usernamedb, $passworddb, $options); } catch (\PDOException $e) { error_log("Database connection failed: " . $e->getMessage()); }
$APIKEY = '000000000:LOCALTEST_dummy_bot_token_for_dev_only';
$adminnumber = '000000000';
$domainhosts = 'localhost:8080';
$usernamebot = 'localtestbot';

$new_marzban = true;
?>