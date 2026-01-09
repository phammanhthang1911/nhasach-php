<?php
session_start();
require __DIR__ . '/../connection.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

// ✅ Chỉ cho admin vào
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: trangchu.php");
    exit();
}

$usersCol  = $db->users;
$ordersCol = $db->orders;
$booksCol  = $db->books;

$message = "";

/**
 * Định dạng ngày giờ VN
 */
function formatDateVN($utc) {
    if ($utc instanceof UTCDateTime) {
        $dt = $utc->toDateTime();
        $dt->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
        return $dt->format('d/m/Y H:i');
    }
    return '';
}

// ====== XỬ LÝ POST: XÁC NHẬN ĐÃ TRẢ SÁCH ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']   ?? '';
    $orderId = $_POST['order_id'] ?? '';

    if ($action === 'mark_returned' && !empty($orderId)) {
        try {
            $oid = new ObjectId($orderId);
        } catch (Exception $e) {
            $oid = null;
        }

        if ($oid) {
            // Chỉ xử lý đơn đang ở trạng thái 'success' (đang mượn)
            $order = $ordersCol->findOne([
                '_id'    => $oid,
                'status' => 'success'
            ]);

            if (!$order) {
                $message = "⚠ Không tìm thấy đơn hoặc đơn không ở trạng thái 'đang mượn' (success).";
            } else {
                $items = $order['items'] ?? [];

                // 1) Cộng lại tồn kho cho từng sách
                if (!empty($items)) {
                    foreach ($items as $it) {
                        $bookId = $it['book_id'] ?? null;
                        $qty    = (int)($it['quantity'] ?? 0);

                        if ($bookId && $qty > 0) {
                            $booksCol->updateOne(
                                ['_id' => $bookId],
                                [
                                    '$inc' => ['quantity' => $qty],
                                    // Nếu muốn tự bật lại status active khi có hàng:
                                    // '$set' => ['status' => 'active']
                                ]
                            );
                        }
                    }
                }

                // 2) Cập nhật trạng thái đơn → returned
                $ordersCol->updateOne(
                    ['_id' => $order['_id']],
                    [
                        '$set' => [
                            'status'      => 'returned',
                            'returned_at' => new UTCDateTime()
                        ]
                    ]
                );

                $message = "✅ Đã xác nhận trả đủ sách cho đơn " . (string)$order['_id'];
            }
        } else {
            $message = "⚠ Mã đơn không hợp lệ.";
        }
    }
}

// ====== ĐỌC THAM SỐ LỌC TỪ GET ======
$codeFilter   = trim($_GET['code'] ?? '');
$userFilter   = trim($_GET['user'] ?? '');

// ====== LẤY DANH SÁCH ĐƠN ĐANG MƯỢN (status = 'success') ======
$filter = ['status' => 'success'];

// Lọc theo mã giao dịch / order_code
if ($codeFilter !== '') {
    $filter['$or'] = [
        ['order_code' => $codeFilter]
    ];
    try {
        $filter['$or'][] = ['_id' => new ObjectId($codeFilter)];
    } catch (Exception $e) {
        // nếu không phải ObjectId thì bỏ qua
    }
}

// Lọc theo username khách hàng
if ($userFilter !== '') {
    $filter['username'] = $userFilter; // nếu muốn regex thì có thể đổi sau
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
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý trả sách - Admin</title>
    <link rel="stylesheet" href="../css/lichsumuahang.css">
</head>
<body>
<div class="page-overlay">
    <div class="container">

        <a href="trangchu.php" class="btn-back">⬅ Về Trang chủ Admin</a>

        <h2>📚 Quản lý trả sách (các đơn đang mượn)</h2>

        <?php if ($message !== ""): ?>
            <p class="msg"><?= htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></p>
        <?php endif; ?>

        <!-- FORM LỌC -->
        <form method="get" class="filter-form" style="margin-bottom: 15px;">
            <input type="text"
                   name="code"
                   placeholder="Mã giao dịch / order_code"
                   value="<?= htmlspecialchars($codeFilter, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">

            <input type="text"
                   name="user"
                   placeholder="Username khách hàng"
                   value="<?= htmlspecialchars($userFilter, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">

            <button type="submit">🔍 Lọc</button>
            <a href="quanlytrasach.php" class="page-link">Xóa lọc</a>
        </form>

        <?php if (empty($orders)): ?>
            <p>Hiện không có đơn nào đang mượn (status = success) theo điều kiện lọc.</p>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <?php
                $createdUtc  = $order['created_at'] ?? null;
                $created     = formatDateVN($createdUtc);

                $username    = $order['username'] ?? '';
                $items       = $order['items'] ?? [];
                $qtyTotal    = (int)($order['total_quantity'] ?? 0);
                $totalAmount = (int)($order['total_amount'] ?? 0);

                // Mã giao dịch: ưu tiên order_code, nếu chưa có thì dùng _id
                $txnId = $order['order_code'] ?? (string)($order['_id'] ?? '');
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
                            <span class="order-label">Khách hàng:</span>
                            <span class="order-value">
                                <?= htmlspecialchars($username, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div>
                            <span class="order-label">Thời gian mượn:</span>
                            <span class="order-value">
                                <?= htmlspecialchars($created, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div>
                            <span class="order-label">Tổng tiền:</span>
                            <span class="order-value">
                                <?= number_format($totalAmount, 0, ',', '.'); ?> đ
                            </span>
                        </div>
                    </div>

                    <div class="order-summary">
                        <span>Tổng sách: <strong><?= $qtyTotal; ?></strong></span>
                        <span>Trạng thái: <strong>success (đang mượn)</strong></span>

                        <span style="margin-left:auto;">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="order_id"
                                       value="<?= htmlspecialchars((string)$order['_id'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
                                <button type="submit"
                                        name="action"
                                        value="mark_returned"
                                        onclick="return confirm('Xác nhận user đã trả ĐỦ tất cả sách của đơn này?');">
                                    ✅ Xác nhận đã trả đủ
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
                    // giữ nguyên filter khi chuyển trang
                    $queryBase = $_GET;
                    ?>
                    <?php if ($page > 1): ?>
                        <?php $queryBase['page'] = $page - 1; ?>
                        <a class="page-link"
                           href="quanlytrasach.php?<?= htmlspecialchars(http_build_query($queryBase), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">&laquo; Trước</a>
                    <?php endif; ?>

                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php $queryBase['page'] = $p; ?>
                        <a class="page-link <?= $p == $page ? 'active' : ''; ?>"
                           href="quanlytrasach.php?<?= htmlspecialchars(http_build_query($queryBase), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
                            <?= $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <?php $queryBase['page'] = $page + 1; ?>
                        <a class="page-link"
                           href="quanlytrasach.php?<?= htmlspecialchars(http_build_query($queryBase), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">Sau &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>
</body>
</html>
