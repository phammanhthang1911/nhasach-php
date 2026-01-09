<?php
require_once "../connection.php"; // Kết nối MongoDB TRUNG TÂM

use MongoDB\BSON\UTCDateTime;

// Đọc JSON từ chi nhánh gửi lên
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    echo "json_error: " . json_last_error_msg();
    exit;
}

if (!is_array($data) || empty($data)) {
    echo "no_data";
    exit;
}

$booksCol = $db->books;

$updated = 0;

foreach ($data as $b) {
    if (empty($b['bookCode'])) {
        continue;
    }

    $bookCode = $b['bookCode'];
    $location = $b['location'] ?? 'Hà Nội';

    $quantity    = (int)($b['quantity']    ?? 0);
    $status      = $b['status']            ?? 'active';
    $borrowCount = (int)($b['borrowCount'] ?? 0);

    // ✅ Trung tâm là master thông tin sách (tên, nhóm, giá...)
    // → chỉ cập nhật tồn, trạng thái, lượt mượn
    $result = $booksCol->updateOne(
        [
            'bookCode' => $bookCode,
            'location' => $location
        ],
        [
            '$set' => [
                'quantity'    => $quantity,
                'status'      => $status,
                'borrowCount' => $borrowCount,
                'updated_at'  => new UTCDateTime()
            ]
        ]
    );

    if ($result->getMatchedCount() > 0) {
        $updated++;
    }
}

// Chỉ cần trả text cho script bên HN check
echo "success: updated $updated books";
