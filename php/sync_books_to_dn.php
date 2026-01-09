<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../connection.php'; // Kết nối MongoDB TRUNG TÂM

use MongoDB\BSON\ObjectId;

if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: trangchu.php");
    exit();
}

$booksCol = $db->books;

// Lấy TẤT CẢ sách của khu vực Đà Nẵng
$cursor = $booksCol->find([
    'location' => 'Đà Nẵng'
]);

$data = [];
foreach ($cursor as $b) {
    $data[] = [
        'bookCode'    => $b['bookCode']    ?? '',
        'bookGroup'   => $b['bookGroup']   ?? '',
        'bookName'    => $b['bookName']    ?? '',
        'location'    => $b['location']    ?? 'Đà Nẵng',
        'quantity'    => (int)($b['quantity']    ?? 0),
        'pricePerDay' => (int)($b['pricePerDay'] ?? 0),
        'borrowCount' => (int)($b['borrowCount'] ?? 0),
        'status'      => $b['status']      ?? 'active',
    ];
}

if (empty($data)) {
    echo "<script>alert('Không có sách nào của khu vực Đà Nẵng để đồng bộ.');window.location='quanlysach.php';</script>";
    exit;
}

$json_data = json_encode($data, JSON_UNESCAPED_UNICODE);

// ✅ URL API bên CHI NHÁNH ĐÀ NẴNG
// Đảm bảo đúng tên folder project ĐN
$url = "http://localhost/NhasachDaNang/api/receive_books_from_center.php";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    $msg = "❌ Lỗi khi đồng bộ xuống chi nhánh Đà Nẵng: " . addslashes($error);
    echo "<script>alert('$msg');window.location='quanlysach.php';</script>";
    exit;
}

$responseTrim = trim((string)$response);

if (strpos($responseTrim, 'success') === 0) {
    echo "<script>alert('✅ Đồng bộ sách xuống chi nhánh Đà Nẵng thành công!');window.location='quanlysach.php';</script>";
} elseif ($responseTrim === 'no_data') {
    echo "<script>alert('⚠ Chi nhánh Đà Nẵng trả về: no_data');window.location='quanlysach.php';</script>";
} else {
    $msg = "⚠ Đồng bộ không thành công. Phản hồi từ Đà Nẵng: " . addslashes($responseTrim);
    echo "<script>alert('$msg');window.location='quanlysach.php';</script>";
}
