<?php
require 'vendor/autoload.php'; // Composer autoload

use MongoDB\Client;

// Cấu hình kết nối MongoDB
$Servername = "mongodb://localhost:27017"; // MongoDB server
$Database = "Nhasach";                     // Database cần dùng

try {
    // Tạo client và chọn database
    $conn = new Client($Servername);
    $db = $conn->$Database;

} catch (Exception $e) {
    die("Không thể kết nối MongoDB: " . $e->getMessage());
}
?>
