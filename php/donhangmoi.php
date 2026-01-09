<?php
session_start();
require '../connection.php';

use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\ObjectId;

// ====== CHECK QUYỀN ADMIN ======
$currentUsername = $_SESSION['username'] ?? null;
$currentRole     = $_SESSION['role']     ?? '';

if (!$currentUsername || $currentRole !== 'admin') {
    // Không phải admin thì đá về trang chủ hoặc trang đăng nhập
    header("Location: trangchu.php");
    exit();
}

$usersCol  = $db->users;
$ordersCol = $db->orders;

$message = "";

// ====== XỬ LÝ POST: ADMIN XÁC NHẬN ĐƠN ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']   ?? '';
    $orderId = $_POST['order_id'] ?? '';

    // XÁC NHẬN 1 ĐƠN
    if ($action === 'confirm' && !empty($orderId)) {
        try {
            $oid = new ObjectId($orderId);
        } catch (Exception $e) {
            $oid = null;
        }

        if ($oid) {
            // Chỉ lấy đơn đang ở trạng thái "paid"
            $order = $ordersCol->findOne([
                '_id'    => $oid,
                'status' => 'paid'
            ]);

            if (!$order) {
                $message = "⚠ Không tìm thấy đơn đang chờ xác nhận (hoặc đã được xử lý).";
            } else {
                // Cập nhật trạng thái thành 'success'
                $ordersCol->updateOne(
                    ['_id' => $order['_id']],
                    [
                        '$set' => [
                            'status'       => 'success',
                            'confirmed_at' => new UTCDateTime(),
                            'confirmed_by' => $currentUsername
                        ]
                    ]
                );

                $message = "✅ Đã xác nhận đơn " . (string)$order['_id'];
            }
        } else {
            $message = "⚠ Mã đơn không hợp lệ.";
        }

    // ✅ XÁC NHẬN TẤT CẢ CÁC ĐƠN ĐANG Ở TRẠNG THÁI paid
    } elseif ($action === 'confirm_all') {

        $now = new UTCDateTime();

        $result = $ordersCol->updateMany(
            ['status' => 'paid'],
            [
                '$set' => [
                    'status'       => 'success',
                    'confirmed_at' => $now,
                    'confirmed_by' => $currentUsername
                ]
            ]
        );

        $count = $result->getModifiedCount();

        if ($count > 0) {
            $message = "✅ Đã xác nhận thành công {$count} đơn đang ở trạng thái 'paid'.";
        } else {
            $message = "⚠ Không có đơn nào ở trạng thái 'paid' để xác nhận.";
        }
    }
}

// ====== ĐỌC THAM SỐ LỌC TỪ GET (TUỲ CHỌN) ======
$code     = trim($_GET['code']  ?? '');
$username = trim($_GET['user']  ?? '');

// ====== TẠO FILTER CHO MONGO: CHỈ LẤY ĐƠN ĐÃ THANH TOÁN (paid) ======
$filter = [
    'status' => 'paid'
];

// Lọc theo mã giao dịch
if ($code !== '') {
    $filter['$or'] = [
        ['order_code' => $code]
    ];
    try {
        $filter['$or'][] = ['_id' => new ObjectId($code)];
    } catch (Exception $e) {
        // bỏ qua nếu không phải ObjectId
    }
}

// Lọc theo username khách hàng
if ($username !== '') {
    $filter['username'] = $username;
}

// ====== PHÂN TRANG ======
$perPage = 10;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$skip    = ($page - 1) * $perPage;

$totalOrders = $ordersCol->count($filter);
$totalPages  = max(1, ceil($totalOrders / $perPage));

$cursor = $ordersCol->find(
    $filter,
    [
        'sort'  => ['created_at' => -1],
        'skip'  => $skip,
        'limit' => $perPage
    ]
);
$orders = $cursor->toArray();

/**
 * Format ngày giờ VN
 */
function formatDateVN($utc) {
    if ($utc instanceof UTCDateTime) {
        $dt = $utc->toDateTime();
        $dt->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
        return $dt->format('d/m/Y H:i');
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đơn mượn mới (Admin)</title>
    <link rel="stylesheet" href="../css/donhangmoi.css">
</head>
<body>
<div class="page-overlay">
    <div class="container">

        <a href="trangchu.php" class="btn-back">⬅ Về Trang chủ</a>

        <h2>📦 Đơn mượn mới cần xác nhận (Admin)</h2>

        <?php if ($message !== ""): ?>
            <p class="msg"><?= htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></p>
        <?php endif; ?>

        <!-- FORM LỌC -->
        <form method="get" class="filter-form" style="margin-bottom: 10px;">
            <input type="text" name="code" placeholder="Mã giao dịch / order_code"
                   value="<?= htmlspecialchars($code, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">

            <input type="text" name="user" placeholder="Username khách hàng"
                   value="<?= htmlspecialchars($username, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">

            <button type="submit">🔍 Lọc</button>
            <a href="donhangmoi.php" class="page-link">Xóa lọc</a>
        </form>

        <!-- NÚT XÁC NHẬN TẤT CẢ ĐƠN PAID -->
        <form method="post" style="margin-bottom: 15px;">
            <button type="submit"
                    name="action"
                    value="confirm_all"
                    onclick="return confirm('Xác nhận TẤT CẢ các đơn đang ở trạng thái paid? Khách hàng sẽ không thể hoàn trả các đơn này nữa.');">
                ✅ Xác nhận tất cả đơn paid
            </button>
        </form>

        <?php if (empty($orders)): ?>
            <p>Hiện không có đơn nào đang ở trạng thái <strong>đã thanh toán (paid)</strong> cần xác nhận.</p>
        <?php else: ?>

            <?php foreach ($orders as $order): ?>
                <?php
                $createdUtc  = $order['created_at'] ?? null;
                $created     = formatDateVN($createdUtc);

                $txnId       = $order['order_code'] ?? (string)($order['_id'] ?? '');
                $usernameCus = $order['username'] ?? '(không rõ)';

                $qtyTotal    = (int)($order['total_quantity'] ?? 0);
                $totalAmount = (int)($order['total_amount']   ?? 0);

                $items       = $order['items'] ?? [];
                ?>
                <div class="order-card">
                    <div class="order-header">
                        <div>
                            <span class="order-label">Mã giao dịch:</span>
                            <span class="order-value">
                                <?= htmlspecialchars($txnId, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div>
                            <span class="order-label">Thời gian đặt:</span>
                            <span class="order-value">
                                <?= htmlspecialchars($created, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div>
                            <span class="order-label">Khách hàng:</span>
                            <span class="order-value">
                                <?= htmlspecialchars($usernameCus, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div>
                            <span class="order-label">Trạng thái:</span>
                            <span class="order-status status-paid">paid</span>
                        </div>
                    </div>

                    <div class="order-summary">
                        <span>Tổng sách: <strong><?= $qtyTotal; ?></strong></span>
                        <span>Tổng tiền:
                            <strong><?= number_format($totalAmount, 0, ',', '.'); ?> đ</strong>
                        </span>

                        <!-- NÚT XÁC NHẬN ĐƠN -->
                        <span style="margin-left:auto;">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="order_id"
                                       value="<?= htmlspecialchars((string)$order['_id'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
                                <button type="submit"
                                        name="action"
                                        value="confirm"
                                        onclick="return confirm('Xác nhận đơn này? Sau khi xác nhận, khách hàng sẽ không thể hoàn trả nữa.');">
                                    ✅ Xác nhận đơn
                                </button>
                            </form>
                        </span>
                    </div>

                    <table class="order-items">
                        <thead>
                        <tr>
                            <th>Mã sách</th>
                            <th>Tên sách</th>
                            <th>Giá/ngày</th>
                            <th>Số lượng</th>
                            <th>Số ngày mượn</th>
                            <th>Thành tiền</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <?php
                            $codeBook = $it['bookCode'] ?? '';
                            $name     = $it['bookName'] ?? '';
                            $p        = (int)($it['pricePerDay'] ?? 0);
                            $q        = (int)($it['quantity'] ?? 1);
                            $days     = max(1, (int)($it['rent_days'] ?? 1));

                            $st       = (int)($it['subTotal'] ?? ($p * $q * $days));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($codeBook, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                                <td><?= number_format($p, 0, ',', '.'); ?> đ</td>
                                <td><?= $q; ?></td>
                                <td><?= $days; ?> ngày</td>
                                <td><?= number_format($st, 0, ',', '.'); ?> đ</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <!-- PHÂN TRANG -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $queryBase = $_GET;
                    ?>
                    <?php if ($page > 1): ?>
                        <?php $queryBase['page'] = $page - 1; ?>
                        <a class="page-link"
                           href="donhangmoi.php?<?= htmlspecialchars(http_build_query($queryBase), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">&laquo; Trước</a>
                    <?php endif; ?>

                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php $queryBase['page'] = $p; ?>
                        <a class="page-link <?= $p == $page ? 'active' : ''; ?>"
                           href="donhangmoi.php?<?= htmlspecialchars(http_build_query($queryBase), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
                            <?= $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <?php $queryBase['page'] = $page + 1; ?>
                        <a class="page-link"
                           href="donhangmoi.php?<?= htmlspecialchars(http_build_query($queryBase), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">Sau &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>
</body>
</html>
