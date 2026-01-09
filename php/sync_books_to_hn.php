<?php
session_start();

// BẬT LỖI CHO DỄ DEBUG (lúc xong có thể tắt)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../connection.php'; // Kết nối MongoDB TRUNG TÂM

use MongoDB\BSON\ObjectId;

// Chỉ admin
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: trangchu.php");
    exit();
}

$booksCol = $db->books;

// Lấy TẤT CẢ sách của khu vực Hà Nội
$cursor = $booksCol->find([
    'location' => 'Hà Nội'
]);

$data = [];
foreach ($cursor as $b) {
    $data[] = [
        'bookCode'    => $b['bookCode']    ?? '',
        'bookGroup'   => $b['bookGroup']   ?? '',
        'bookName'    => $b['bookName']    ?? '',
        'location'    => $b['location']    ?? 'Hà Nội',
        'quantity'    => (int)($b['quantity']    ?? 0),
        'pricePerDay' => (int)($b['pricePerDay'] ?? 0),
        'borrowCount' => (int)($b['borrowCount'] ?? 0),
        'status'      => $b['status']      ?? 'active',
    ];
}

if (empty($data)) {
    echo "<script>alert('Không có sách nào của khu vực Hà Nội để đồng bộ.');window.location='quanlysach.php';</script>";
    exit;
}

$json_data = json_encode($data, JSON_UNESCAPED_UNICODE);

// ✅ URL API bên CHI NHÁNH HÀ NỘI
// NHỚ kiểm tra đúng tên folder project HN
$url = "http://localhost/NhasachHaNoi/api/receive_books_from_center.php";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    $msg = "❌ Lỗi khi đồng bộ xuống chi nhánh Hà Nội: " . addslashes($error);
    echo "<script>alert('$msg');window.location='quanlysach.php';</script>";
    exit;
}

$responseTrim = trim((string)$response);

// Bên HN echo kiểu: success: processed X books
if (strpos($responseTrim, 'success') === 0) {
    echo "<script>alert('✅ Đồng bộ sách xuống chi nhánh Hà Nội thành công!');window.location='quanlysach.php';</script>";
} elseif ($responseTrim === 'no_data') {
    echo "<script>alert('⚠ Chi nhánh Hà Nội trả về: no_data');window.location='quanlysach.php';</script>";
} else {
    $msg = "⚠ Đồng bộ không thành công. Phản hồi từ HN: " . addslashes($responseTrim);
    echo "<script>alert('$msg');window.location='quanlysach.php';</script>";
}
