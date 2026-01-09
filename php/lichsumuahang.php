<?php
session_start();
require '../connection.php';

use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\ObjectId;

// Nếu chưa đăng nhập → đá ra trang đăng nhập
$currentUsername = $_SESSION['username'] ?? null;
if (!$currentUsername) {
    header("Location: dangnhap.php");
    exit();
}

$usersCol  = $db->users;
$ordersCol = $db->orders;
$booksCol  = $db->books; // dùng để cộng lại tồn kho khi hủy đơn

// Lấy thông tin user hiện tại
$user = $usersCol->findOne(['username' => $currentUsername]);
if (!$user) {
    die("Không tìm thấy tài khoản người dùng.");
}

$message = "";

// ====== XỬ LÝ POST: HỦY ĐƠN (HOÀN TIỀN + TRẢ SÁCH VỀ KHO) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']   ?? '';
    $orderId = $_POST['order_id'] ?? '';

    if ($action === 'cancel' && !empty($orderId)) {
        try {
            $oid = new ObjectId($orderId);
        } catch (Exception $e) {
            $oid = null;
        }

        if ($oid) {
            // Lấy đơn thuộc về chính user đang đăng nhập
            $order = $ordersCol->findOne([
                '_id'     => $oid,
                'user_id' => $user['_id']
            ]);

            if (!$order) {
                $message = "⚠ Không tìm thấy đơn mượn hoặc bạn không có quyền trên đơn này.";
            } else {
                $currentStatus = $order['status'] ?? 'paid';

                // ✅ Chỉ cho HỦY khi đơn đang ở trạng thái 'paid'
                // (đã thanh toán nhưng chưa admin duyệt / chưa mượn)
                if ($currentStatus !== 'paid') {
                    $message = "⚠ Chỉ có thể hủy các đơn đang ở trạng thái 'đã thanh toán' (chưa duyệt).";
                } else {
                    // Số tiền hoàn trả
                    $refundAmount = (int)($order['total_amount'] ?? 0);
                    $items        = $order['items'] ?? [];

                    // fallback: nếu total_amount <= 0 thì thử cộng lại từ items
                    if ($refundAmount <= 0 && !empty($items)) {
                        $refundAmount = 0;
                        foreach ($items as $it) {
                            $p     = (int)($it['pricePerDay'] ?? 0);
                            $q     = (int)($it['quantity'] ?? 1);
                            $days  = max(1, (int)($it['rent_days'] ?? 1));
                            $sub   = (int)($it['subTotal'] ?? ($p * $q * $days));
                            $refundAmount += $sub;
                        }
                    }

                    if ($refundAmount <= 0) {
                        $message = "⚠ Đơn hàng không có số tiền hợp lệ để hoàn.";
                    } else {

                        // 1) Đổi trạng thái đơn → cancelled
                        $ordersCol->updateOne(
                            ['_id' => $order['_id']],
                            [
                                '$set' => [
                                    'status'       => 'cancelled',
                                    'cancelled_at' => new UTCDateTime()
                                ]
                            ]
                        );

                        // 2) Cộng lại tiền cho user
                        $usersCol->updateOne(
                            ['_id' => $user['_id']],
                            ['$inc' => ['balance' => $refundAmount]]
                        );

                        // 3) Cộng lại tồn kho sách
                        if (!empty($items)) {
                            foreach ($items as $it) {
                                $bookId = $it['book_id'] ?? null;
                                $qty    = (int)($it['quantity'] ?? 0);
                                if ($bookId && $qty > 0) {
                                    $booksCol->updateOne(
                                        ['_id' => $bookId],
                                        [
                                            '$inc' => ['quantity' => $qty],
                                            // tuỳ bạn: nếu muốn bật lại trạng thái active thì dùng thêm:
                                            // '$set' => ['status' => 'active']
                                        ]
                                    );
                                }
                            }
                        }

                        // 4) Lấy lại user để cập nhật balance mới nhất (nếu sau này dùng)
                        $user = $usersCol->findOne(['_id' => $user['_id']]);

                        $message = "✅ Hủy đơn thành công. Đã hoàn lại " . number_format($refundAmount, 0, ',', '.') . " đ vào tài khoản của bạn.";
                    }
                }
            }
        } else {
            $message = "⚠ Mã đơn không hợp lệ.";
        }
    }
}

// ====== ĐỌC THAM SỐ LỌC TỪ GET ======
$code       = trim($_GET['code']       ?? '');
$fromDate   = trim($_GET['from']       ?? '');
$toDate     = trim($_GET['to']         ?? '');
$status     = trim($_GET['status']     ?? 'all');
$minAmount  = trim($_GET['min_amount'] ?? '');
$maxAmount  = trim($_GET['max_amount'] ?? '');

// ====== TẠO FILTER CHO MONGO ======
$filter = [
    'user_id' => $user['_id'], // luôn giới hạn trong user này
];

// Lọc theo mã giao dịch (_id) hoặc order_code
if ($code !== '') {
    // Ưu tiên order_code nếu có
    $filter['$or'] = [
        ['order_code' => $code]
    ];
    // Thử thêm _id nếu code trông giống ObjectId
    try {
        $filter['$or'][] = ['_id' => new ObjectId($code)];
    } catch (Exception $e) {
        // bỏ qua nếu không phải ObjectId
    }
}

// Lọc theo khoảng ngày
if ($fromDate !== '' || $toDate !== '') {
    $dateFilter = [];
    if ($fromDate !== '') {
        $tsFrom = strtotime($fromDate . ' 00:00:00');
        if ($tsFrom !== false) {
            $dateFilter['$gte'] = new UTCDateTime($tsFrom * 1000);
        }
    }
    if ($toDate !== '') {
        $tsTo = strtotime($toDate . ' 23:59:59');
        if ($tsTo !== false) {
            $dateFilter['$lte'] = new UTCDateTime($tsTo * 1000);
        }
    }
    if (!empty($dateFilter)) {
        $filter['created_at'] = $dateFilter;
    }
}

// Lọc theo trạng thái
if ($status !== '' && $status !== 'all') {
    $filter['status'] = $status;
}

// Lọc theo khoảng tiền
$amountFilter = [];
if ($minAmount !== '' && is_numeric($minAmount)) {
    $amountFilter['$gte'] = (int)$minAmount;
}
if ($maxAmount !== '' && is_numeric($maxAmount)) {
    $amountFilter['$lte'] = (int)$maxAmount;
}
if (!empty($amountFilter)) {
    $filter['total_amount'] = $amountFilter;
}

// ====== PHÂN TRANG ======
$perPage = 10; // 10 giao dịch / 1 trang
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$skip    = ($page - 1) * $perPage;

// Tổng số đơn của user (theo filter)
$totalOrders = $ordersCol->count($filter);
$totalPages  = max(1, ceil($totalOrders / $perPage));

// CHỈ LẤY ĐƠN HÀNG CỦA CHÍNH USER NÀY (có phân trang + filter)
$cursor = $ordersCol->find(
    $filter,
    [
        'sort'  => ['created_at' => -1], // mới → cũ
        'skip'  => $skip,
        'limit' => $perPage
    ]
);
$orders = $cursor->toArray();

/**
 * Format ngày giờ VN, có thể cộng thêm số ngày (dùng để tính ngày trả sách)
 */
function formatDateVNPlus($utc, int $plusDays = 0) {
    if ($utc instanceof UTCDateTime) {
        $dt = $utc->toDateTime();
        $dt->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
        if ($plusDays > 0) {
            $dt->modify('+' . $plusDays . ' days');
        }
        return $dt->format('d/m/Y H:i');
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Lịch sử đơn mượn</title>
    <link rel="stylesheet" href="../css/lichsumuahang.css">
</head>
<body>
<div class="page-overlay">
    <div class="container">

        <a href="trangchu.php" class="btn-back">⬅ Về Trang chủ</a>

        <h2>📜 Lịch sử đơn mượn</h2>

        <?php if ($message !== ""): ?>
            <p class="msg"><?= htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></p>
        <?php endif; ?>

        <!-- FORM LỌC -->
        <form method="get" class="filter-form" style="margin-bottom: 15px;">
            <input type="text" name="code" placeholder="Mã giao dịch hoặc order_code..."
                   value="<?= htmlspecialchars($code, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">

            <input type="date" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
            <input type="date" name="to"   value="<?= htmlspecialchars($toDate,   ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">

            <select name="status">
                <option value="all"      <?= $status === 'all'       ? 'selected' : ''; ?>>-- Tất cả trạng thái --</option>
                <option value="paid"     <?= $status === 'paid'      ? 'selected' : ''; ?>>Đã thanh toán</option>
                <option value="success"  <?= $status === 'success'   ? 'selected' : ''; ?>>Đã duyệt / đang mượn</option>
                <option value="returned" <?= $status === 'returned'  ? 'selected' : ''; ?>>Đã trả</option>
                <option value="cancelled"<?= $status === 'cancelled' ? 'selected' : ''; ?>>Đã hủy</option>
            </select>

            <input type="number" name="min_amount" placeholder="Tối thiểu"
                   value="<?= htmlspecialchars($minAmount, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>"
                   style="width:100px;">
            <input type="number" name="max_amount" placeholder="Tối đa"
                   value="<?= htmlspecialchars($maxAmount, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>"
                   style="width:100px;">

            <button type="submit">🔍 Lọc</button>
            <a href="lichsumuahang.php" class="page-link">Xóa lọc</a>
        </form>

        <?php if (empty($orders)): ?>
            <p>Hiện không có đơn mượn nào theo điều kiện lọc.</p>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <?php
                $createdUtc  = $order['created_at'] ?? null;

                // ⭐ Mã giao dịch: ưu tiên order_code, nếu chưa có thì dùng _id
                $txnId = $order['order_code'] ?? (string)($order['_id'] ?? '');

                // ngày giờ đặt mượn (hiển thị)
                $created     = formatDateVNPlus($createdUtc, 0);

                // tổng tiền/ngày (nếu có)
                $totalPerDay = (int)($order['total_per_day'] ?? 0);

                // tổng số sách
                $qtyTotal    = (int)($order['total_quantity'] ?? 0);

                // tổng tiền toàn đơn (nếu có lưu sẵn thì dùng, không thì tự tính)
                $rentDaysOrder = max(1, (int)($order['rent_days'] ?? 1));
                $items         = $order['items'] ?? [];

                $totalAmount   = (int)($order['total_amount'] ?? 0);
                if ($totalAmount <= 0 && !empty($items)) {
                    $totalAmount = 0;
                    foreach ($items as $it) {
                        $p     = (int)($it['pricePerDay'] ?? 0);
                        $q     = (int)($it['quantity'] ?? 1);
                        $days  = max(1, (int)($it['rent_days'] ?? $rentDaysOrder));
                        $sub   = (int)($it['subTotal'] ?? ($p * $q * $days));
                        $totalAmount += $sub;
                    }
                }

                $statusOrder  = $order['status'] ?? 'paid';

                // Cho phép hủy khi đơn đang "paid" và có tiền
                $canCancel = ($statusOrder === 'paid' && $totalAmount > 0);
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
                            <span class="order-label">Trạng thái:</span>
                            <span class="order-status">
                                <?= htmlspecialchars($statusOrder, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <div class="order-summary">
                        <span>Tổng sách: <strong><?= $qtyTotal; ?></strong></span>
                        <span>Tổng tiền phải trả:
                            <strong><?= number_format($totalAmount, 0, ',', '.'); ?> đ</strong>
                        </span>

                        <!-- NÚT HỦY ĐƠN (KHÔNG PHẢI TRẢ SÁCH) -->
                        <span style="margin-left:auto;">
                            <?php if ($canCancel): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="order_id"
                                           value="<?= htmlspecialchars((string)$order['_id'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
                                    <button type="submit"
                                            name="action"
                                            value="cancel"
                                            onclick="return confirm('Bạn có chắc muốn HỦY đơn này? Số tiền sẽ được hoàn lại và sách sẽ được trả về kho.');">
                                        ❌ Hủy đơn
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" disabled style="opacity:0.6; cursor:not-allowed;">
                                    ❌ Không thể hủy
                                </button>
                            <?php endif; ?>
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
                            <th>Ngày trả dự kiến</th>
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

                            // nếu sau này mỗi sách có rent_days riêng thì lấy, không thì fallback về rentDaysOrder
                            $rentDaysItem = max(1, (int)($it['rent_days'] ?? $rentDaysOrder));

                            // ngày trả dự kiến = ngày đặt + số ngày mượn của sách
                            $returnDate   = formatDateVNPlus($createdUtc, $rentDaysItem);

                            // Thành tiền: nếu subTotal đã lưu thì dùng, không thì tự tính p * q * rentDaysItem
                            $st   = (int)($it['subTotal'] ?? ($p * $q * $rentDaysItem));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($codeBook, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                                <td><?= number_format($p, 0, ',', '.'); ?> đ</td>
                                <td><?= $q; ?></td>
                                <td><?= $rentDaysItem; ?> ngày</td>
                                <td><?= htmlspecialchars($returnDate, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
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
                           href="lichsumuahang.php?<?= htmlspecialchars(http_build_query($queryBase), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">&laquo; Trước</a>
                    <?php endif; ?>

                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php $queryBase['page'] = $p; ?>
                        <a class="page-link <?= $p == $page ? 'active' : ''; ?>"
                           href="lichsumuahang.php?<?= htmlspecialchars(http_build_query($queryBase), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
                            <?= $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <?php $queryBase['page'] = $page + 1; ?>
                        <a class="page-link"
                           href="lichsumuahang.php?<?= htmlspecialchars(http_build_query($queryBase), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">Sau &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>
</body>
</html>
