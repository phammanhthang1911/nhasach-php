<?php
session_start();
require '../connection.php'; // Kết nối MongoDB TRUNG TÂM

use MongoDB\BSON\ObjectId;

// Chỉ admin
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: trangchu.php");
    exit();
}

$booksCol = $db->books;

/**
 * LẤY SÁCH CỦA KHU VỰC HCM
 * 
 * 👉 CHÚ Ý:
 *  - 'Hồ Chí Minh' phải trùng đúng với field location trong DB của mày
 *  - Nếu mày đang dùng 'TP. Hồ Chí Minh' hay 'HCM' thì sửa lại cho khớp
 */
$cursor = $booksCol->find([
    'location' => 'Hồ Chí Minh'
]);

$data = [];
foreach ($cursor as $b) {
    $data[] = [
        'bookCode'    => $b['bookCode']    ?? '',
        'bookGroup'   => $b['bookGroup']   ?? '',
        'bookName'    => $b['bookName']    ?? '',
        'location'    => $b['location']    ?? 'Hồ Chí Minh',
        'quantity'    => (int)($b['quantity']    ?? 0),
        'pricePerDay' => (int)($b['pricePerDay'] ?? 0),
        'borrowCount' => (int)($b['borrowCount'] ?? 0),
        'status'      => $b['status']      ?? 'active',
    ];
}

if (empty($data)) {
    echo "<script>alert('Không có sách nào của khu vực Hồ Chí Minh để đồng bộ.');window.location='quanlysach.php';</script>";
    exit;
}

$json_data = json_encode($data, JSON_UNESCAPED_UNICODE);



$url = "http://localhost/NhasachHoChiMinh/api/receive_books_from_center.php";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    // Lỗi cURL (không gọi được sang HCM)
    $msg = "❌ Lỗi khi đồng bộ xuống chi nhánh HCM: " . addslashes($error);
    echo "<script>alert('$msg');window.location='quanlysach.php';</script>";
    exit;
}

$responseTrim = trim((string)$response);

// Bên HCM nên echo kiểu: success: processed X books
if (strpos($responseTrim, 'success') === 0) {
    echo "<script>alert('✅ Đồng bộ sách xuống chi nhánh Hồ Chí Minh thành công!');window.location='quanlysach.php';</script>";
} elseif ($responseTrim === 'no_data') {
    echo "<script>alert('⚠ Chi nhánh Hồ Chí Minh trả về: no_data');window.location='quanlysach.php';</script>";
} else {
    $msg = "⚠ Đồng bộ không thành công. Phản hồi từ HCM: " . addslashes($responseTrim);
    echo "<script>alert('$msg');window.location='quanlysach.php';</script>";
}
