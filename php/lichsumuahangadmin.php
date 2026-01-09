<?php
session_start();
require '../connection.php';

use MongoDB\BSON\UTCDateTime;

// Chỉ admin
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: trangchu.php");
    exit();
}

$customersCol     = $db->customers;
$ordersCentralCol = $db->orders_central;

// Lấy thông tin từ GET
$username = trim($_GET['username'] ?? '');
$branchId = trim($_GET['branch']   ?? 'HN');

if ($username === '') {
    header("Location: quanlynguoidung.php");
    exit();
}


// Lấy khách hàng
$customer = $customersCol->findOne([
    'username'  => $username,
    'branch_id' => $branchId
]);

if (!$customer) die("Không tìm thấy khách hàng.");

$displayName = $customer['display_name'] ?? '';

function formatDateVN($utc) {
    if ($utc instanceof UTCDateTime) {
        $d = $utc->toDateTime();
        $d->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
        return $d->format('d/m/Y H:i');
    }
    return '';
}

// Lọc
$filter = [
    'username'  => $username,
    'branch_id' => $branchId
];

$cursor = $ordersCentralCol->find(
    $filter,
    ['sort' => ['created_at' => -1]]
);
$orders = $cursor->toArray();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Lịch sử mượn</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-image: url("../picture/trangchu.png");
            background-size: cover;
            background-repeat: no-repeat;
            background-position: center;
            background-attachment: fixed;
        }

        .page-overlay {
            min-height: 100vh;
            background: rgba(20, 10, 5, 0.6);
            padding: 30px 10px;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            background-color: rgba(255, 247, 230, 0.98);
            border-radius: 12px;
            padding: 20px 25px 30px;
            border: 1px solid #f3d7b5;
            box-shadow: 0 8px 25px rgba(0,0,0,0.4);
        }

        h2 {
            color: #5a2f1a;
            margin-top: 0;
        }

        .btn-back {
            display: inline-block;
            margin-bottom: 12px;
            padding: 8px 14px;
            background-color: #b36b3f;
            color: #fff7e6;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            transition: 0.2s;
            font-size: 13px;
        }
        .btn-back:hover {
            background-color: #8c4f2f;
        }

        .order-card {
            background-color: #fff;
            border-radius: 10px;
            border: 1px solid #f0d3b0;
            padding: 12px 14px 14px;
            margin-bottom: 14px;
            box-shadow: 0 3px 8px rgba(0,0,0,0.15);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 6px;
        }

        .order-label {
            font-weight: bold;
            color: #5a2f1a;
            font-size: 13px;
        }
        .order-value {
            font-size: 13px;
            color: #5a2f1a;
        }

        .order-status {
            font-size: 13px;
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 999px;
            display: inline-block;
        }

        .status-paid {
            background: #e5f3ff;
            color: #1c6bb0;
        }
        .status-success {
            background: #e4f7ea;
            color: #1a7c3a;
        }
        .status-returned {
            background: #f0ebff;
            color: #5d3fb3;
        }
        .status-cancelled {
            background: #ffe5e5;
            color: #c0392b;
        }

        .order-summary {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 6px;
            font-size: 13px;
            color: #5a2f1a;
        }

        .order-items {
            width: 100%;
            border-collapse: collapse;
            background-color: #fff;
        }
        .order-items th,
        .order-items td {
            padding: 7px 5px;
            border: 1px solid #e0c9a9;
            font-size: 12px;
        }
        .order-items th {
            background-color: #f5e0c8;
            color: #5a2f1a;
        }
    </style>
</head>
<body>
<div class="page-overlay">
    <div class="container">

        <a href="quanlynguoidung.php" class="btn-back">⬅ Quay lại</a>

        <h2>Lịch sử mượn – <?= htmlspecialchars($displayName ?: $username); ?> (<?= $branchId ?>)</h2>

        <?php if (empty($orders)): ?>
            <p>Không có đơn nào.</p>
        <?php else: ?>

            <?php foreach ($orders as $o): ?>
                <?php
                $status = $o['status'] ?? '';
                $mapStatusClass = [
                    'paid'      => 'status-paid',
                    'success'   => 'status-success',
                    'returned'  => 'status-returned',
                    'cancelled' => 'status-cancelled',
                ];
                $statusClass = $mapStatusClass[$status] ?? '';
                ?>
                <div class="order-card">

                    <div class="order-header">
                        <div>
                            <div class="order-label">Mã đơn</div>
                            <div class="order-value">
                                <?= htmlspecialchars($o['order_code'] ?? $o['order_key'] ?? '') ?>
                            </div>
                        </div>
                        <div>
                            <div class="order-label">Ngày tạo</div>
                            <div class="order-value">
                                <?= formatDateVN($o['created_at'] ?? null) ?>
                            </div>
                        </div>
                        <div>
                            <div class="order-label">Trạng thái</div>
                            <span class="order-status <?= $statusClass ?>">
                                <?= htmlspecialchars($status) ?>
                            </span>
                        </div>
                        <div>
                            <div class="order-label">Tổng tiền</div>
                            <div class="order-value">
                                <?= number_format($o['total_amount'] ?? 0) ?> đ
                            </div>
                        </div>
                    </div>

                    <table class="order-items">
                        <tr>
                            <th>Mã sách</th>
                            <th>Tên</th>
                            <th>Giá/ngày</th>
                            <th>SL</th>
                            <th>Ngày mượn</th>
                            <th>Thành tiền</th>
                        </tr>

                        <?php foreach ($o['items'] ?? [] as $it): ?>
                            <tr>
                                <td><?= htmlspecialchars($it['bookCode'] ?? '') ?></td>
                                <td><?= htmlspecialchars($it['bookName'] ?? '') ?></td>
                                <td><?= number_format($it['pricePerDay'] ?? 0) ?></td>
                                <td><?= $it['quantity'] ?? 1 ?></td>
                                <td><?= $it['rent_days'] ?? 1 ?></td>
                                <td><?= number_format($it['subTotal'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>

                    </table>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    </div>
</div>
</body>
</html>

