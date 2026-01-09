<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "../connection.php"; // Kết nối MongoDB trung tâm

use MongoDB\BSON\UTCDateTime;

date_default_timezone_set('Asia/Ho_Chi_Minh'); // ⭐ Giờ mặc định là VN

// Đọc JSON từ chi nhánh Hà Nội gửi lên
$raw = file_get_contents("php://input");

// Nếu muốn debug có thể tạm thời log ra file:
// file_put_contents('receive_log.txt', $raw . PHP_EOL, FILE_APPEND);

$data = json_decode($raw, true);

if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    echo "json_error: " . json_last_error_msg();
    exit;
}

if (!is_array($data) || empty($data)) {
    echo "no_data";
    exit;
}

// Lặp qua từng khách hàng
foreach ($data as $cus) {
    // Bắt buộc phải có username
    if (empty($cus['username'])) {
        continue;
    }

    $username  = $cus['username'];
    $branchId  = $cus['branch_id'] ?? 'HN';

    // ================== 1) LƯU / CẬP NHẬT THÔNG TIN KHÁCH HÀNG ==================
    $filterUser = [
        'username'  => $username,
        'branch_id' => $branchId
    ];

    $updateUser = [
        '$set' => [
            'username'     => $username,
            'display_name' => $cus['display_name'] ?? '',
            'role'         => $cus['role'] ?? 'customer',
            'balance'      => (int)($cus['balance'] ?? 0),
            'branch_id'    => $branchId,
            'synced'       => true,          // đánh dấu là đã đồng bộ lên trung tâm
            'updated_at'   => new UTCDateTime()
        ]
    ];

    $db->customers->updateOne($filterUser, $updateUser, ['upsert' => true]);

    // ================== 2) LƯU / CẬP NHẬT LỊCH SỬ ĐƠN HÀNG ==================
    if (!empty($cus['orders']) && is_array($cus['orders'])) {
        foreach ($cus['orders'] as $od) {

            // Mỗi đơn cần 1 "khóa" để định danh: order_code (ưu tiên) hoặc _id string
            $orderKey = $od['order_code'] ?? ($od['_id'] ?? null);
            if (!$orderKey) {
                continue;
            }

            $filterOrder = [
                'branch_id' => $branchId,
                'order_key' => $orderKey
            ];

            // Xử lý thời gian created_at
            $createdAt = null;
            if (!empty($od['created_at'])) {
                $ts = strtotime($od['created_at']); // hiểu theo timezone VN ở trên
                if ($ts !== false) {
                    $createdAt = new UTCDateTime($ts * 1000);
                }
            }

            // Xử lý thời gian returned_at (nếu có)
            $returnedAt = null;
            if (!empty($od['returned_at'])) {
                $ts2 = strtotime($od['returned_at']);
                if ($ts2 !== false) {
                    $returnedAt = new UTCDateTime($ts2 * 1000);
                }
            }

            $updateOrder = [
                '$set' => [
                    'order_key'      => $orderKey,
                    'order_code'     => $od['order_code'] ?? null,
                    'username'       => $username,
                    'branch_id'      => $branchId,
                    'total_per_day'  => (int)($od['total_per_day'] ?? 0),
                    'total_amount'   => (int)($od['total_amount'] ?? 0),
                    'total_quantity' => (int)($od['total_quantity'] ?? 0),
                    'status'         => $od['status'] ?? 'paid',  // giữ đúng status từ chi nhánh
                    'items'          => $od['items'] ?? [],
                    'created_at'     => $createdAt,
                    'returned_at'    => $returnedAt,
                    'synced'         => true,
                    'updated_at'     => new UTCDateTime()
                ]
            ];

            // Gợi ý: collection 'orders_central' để tách với orders ở chi nhánh
            $db->orders_central->updateOne($filterOrder, $updateOrder, ['upsert' => true]);
        }
    }
}

echo "success";
