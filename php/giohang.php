<?php
session_start();
require __DIR__ . '/../connection.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

// Chỉ customer mới được vào giỏ hàng
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    header("Location: trangchu.php");
    exit();
}

// Lấy username (tuỳ login, nếu bạn dùng $_SESSION['user']['username'] thì sửa lại ở đây)
$currentUsername = $_SESSION['username'] ?? null;
if (!$currentUsername) {
    header("Location: dangnhap.php");
    exit();
}

$usersCol  = $db->users;
$booksCol  = $db->books;
$cartsCol  = $db->carts;
$ordersCol = $db->orders; // collection lưu lịch sử đơn

$message = "";

// ⭐ LẤY FLASH MESSAGE TỪ SESSION (SAU KHI REDIRECT)
if (!empty($_SESSION['cart_message'])) {
    $message = $_SESSION['cart_message'];
    unset($_SESSION['cart_message']);
}

// ------- LẤY USER -------
$user = $usersCol->findOne(['username' => $currentUsername]);
if (!$user) {
    die("Không tìm thấy tài khoản người dùng.");
}
$balance = (int)($user['balance'] ?? 0);

// ⭐ KIỂM TRA CÒN ĐƠN CHƯA KẾT THÚC KHÔNG (paid hoặc success)
// Nếu còn đơn như vậy → KHÔNG CHO ĐẶT ĐƠN MỚI
$hasUnreturned = $ordersCol->count([
    'user_id' => $user['_id'],
    'status'  => ['$in' => ['paid', 'success']]
]) > 0;

// ------- LẤY GIỎ HÀNG TỪ DB -------
$cartDoc   = $cartsCol->findOne(['user_id' => $user['_id']]);
$cartItems = $cartDoc['items'] ?? [];

// =========== HÀM TÍNH TỔNG THEO NGÀY (KHÔNG TÍNH SỐ NGÀY MƯỢN) ============
function calc_total_per_day($items) {
    $total = 0;
    foreach ($items as $item) {
        $price = (int)($item['pricePerDay'] ?? 0);
        $qty   = (int)($item['quantity'] ?? 1);
        $total += $price * $qty;
    }
    return $total;
}

// ⭐ HÀM TÍNH TỔNG THANH TOÁN (CÓ TÍNH SỐ NGÀY MỖI QUYỂN)
function calc_total_amount_with_days($items) {
    $total = 0;
    foreach ($items as $item) {
        $price = (int)($item['pricePerDay'] ?? 0);
        $qty   = (int)($item['quantity'] ?? 1);
        $days  = max(1, (int)($item['rent_days'] ?? 1));
        $total += $price * $qty * $days;
    }
    return $total;
}

// ⭐ TÍNH TỔNG SỐ LƯỢNG SÁCH TRONG ĐƠN
function calc_total_quantity($items) {
    $q = 0;
    foreach ($items as $item) {
        $q += (int)($item['quantity'] ?? 1);
    }
    return $q;
}

// =========== XÓA 1 MÓN (GET ?remove=bookId) ===========
if (isset($_GET['remove']) && $cartDoc) {
    $removeId = $_GET['remove'];
    $newItems = [];

    foreach ($cartItems as $it) {
        if ((string)$it['book_id'] !== (string)$removeId) {
            $newItems[] = $it;
        }
    }

    $cartsCol->updateOne(
        ['_id' => $cartDoc['_id']],
        ['$set' => [
            'items'      => $newItems,
            'updated_at' => new UTCDateTime()
        ]]
    );

    // dùng flash message + redirect để tránh resubmit
    $_SESSION['cart_message'] = "🗑️ Đã xóa sách khỏi giỏ mượn.";
    header("Location: giohang.php");
    exit();
}

// =========== XỬ LÝ POST: UPDATE / CLEAR / CONFIRM ===========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // --- CẬP NHẬT SỐ LƯỢNG + SỐ NGÀY MƯỢN ---
    if ($action === 'update' && $cartDoc) {
        $qtyArr   = $_POST['quantity']   ?? [];
        $daysArr  = $_POST['rent_days']  ?? [];
        $newItems = [];

        foreach ($cartItems as $it) {
            $bookIdStr = (string)$it['book_id'];

            // Số lượng
            $qty = isset($qtyArr[$bookIdStr]) ? (int)$qtyArr[$bookIdStr] : (int)($it['quantity'] ?? 1);
            if ($qty < 1) $qty = 1;

            // ⭐ Số ngày mượn cho từng quyển
            $days = isset($daysArr[$bookIdStr]) ? (int)$daysArr[$bookIdStr] : (int)($it['rent_days'] ?? 1);
            if ($days < 1) $days = 1;

            $it['quantity']  = $qty;
            $it['rent_days'] = $days;

            $newItems[] = $it;
        }

        $cartsCol->updateOne(
            ['_id' => $cartDoc['_id']],
            ['$set' => [
                'items'      => $newItems,
                'updated_at' => new UTCDateTime()
            ]]
        );

        $_SESSION['cart_message'] = "✅ Đã cập nhật số lượng và số ngày mượn trong giỏ.";
        header("Location: giohang.php");
        exit();

    // --- XÓA TOÀN BỘ GIỎ ---
    } elseif ($action === 'clear' && $cartDoc) {
        $cartsCol->updateOne(
            ['_id' => $cartDoc['_id']],
            ['$set' => [
                'items'      => [],
                'updated_at' => new UTCDateTime()
            ]]
        );

        $_SESSION['cart_message'] = "🧹 Đã xóa toàn bộ giỏ mượn.";
        header("Location: giohang.php");
        exit();

    // --- XÁC NHẬN ĐẶT MƯỢN ---
    } elseif ($action === 'confirm') {

        // ⭐ Lấy lại quantity + rent_days từ form để THUỘC VỀ MỖI QUYỂN,
        // không bắt user phải ấn "Cập nhật giỏ" trước
        $qtyArr  = $_POST['quantity']  ?? [];
        $daysArr = $_POST['rent_days'] ?? [];
        $cartNow = [];

        foreach ($cartItems as $it) {
            $bookIdStr = (string)$it['book_id'];

            $qty = isset($qtyArr[$bookIdStr]) ? (int)$qtyArr[$bookIdStr] : (int)($it['quantity'] ?? 1);
            if ($qty < 1) $qty = 1;

            $days = isset($daysArr[$bookIdStr]) ? (int)$daysArr[$bookIdStr] : (int)($it['rent_days'] ?? 1);
            if ($days < 1) $days = 1;

            $it['quantity']  = $qty;
            $it['rent_days'] = $days;

            $cartNow[] = $it;
        }

        $totalPerDay   = calc_total_per_day($cartNow);          // tổng tiền/ngày (tham khảo)
        $totalAmount   = calc_total_amount_with_days($cartNow); // tổng thanh toán thật (theo số ngày từng quyển)
        $totalQuantity = calc_total_quantity($cartNow);          // ⭐ tổng số sách trong đơn

        // ⭐ CHẶN NẾU CÒN ĐƠN CHƯA KẾT THÚC (paid / success)
        if ($hasUnreturned) {
            $message = "⚠ Bạn đang có đơn mượn chưa kết thúc (đang chờ duyệt hoặc đang mượn). Vui lòng xử lý xong đơn đó trước khi mượn thêm.";
        } elseif (empty($cartNow)) {
            $message = "⚠ Giỏ mượn đang trống, không có gì để thanh toán.";
        } elseif ($totalQuantity > 10) {
            $message = "⚠ Bạn chỉ được mượn tối đa 10 cuốn cho mỗi lần đặt. Hiện bạn đang chọn $totalQuantity cuốn. Vui lòng giảm bớt.";
        } elseif ($totalAmount <= 0) {
            $message = "⚠ Tổng tiền không hợp lệ.";
        } else {
            // Reload user & balance mới nhất
            $userReload = $usersCol->findOne(['_id' => $user['_id']]);
            if (!$userReload) {
                die("Không tìm thấy tài khoản người dùng.");
            }
            $balanceDb = (int)($userReload['balance'] ?? 0);

            if ($balanceDb >= $totalAmount) {
                // 1) KIỂM TRA TỒN KHO TRƯỚC
                $insufficient  = false;
                $errors        = [];
                $booksToUpdate = [];

                foreach ($cartNow as $it) {
                    $bookId = $it['book_id'];
                    $qty    = (int)($it['quantity'] ?? 1);

                    $book = $booksCol->findOne(['_id' => $bookId]);
                    if (!$book) {
                        $insufficient = true;
                        $errors[]     = "Có sách trong giỏ không còn tồn tại trong hệ thống.";
                        break;
                    }

                    $currentQty = (int)($book['quantity'] ?? 0);
                    $status     = $book['status'] ?? 'active';

                    if ($status === 'deleted') {
                        $insufficient = true;
                        $errors[]     = "Sách '" . ($book['bookName'] ?? '') . "' đã bị xóa, không thể mượn.";
                        break;
                    }

                    if ($currentQty < $qty) {
                        $insufficient = true;
                        $errors[]     = "Sách '" . ($book['bookName'] ?? '') . "' không đủ số lượng (còn $currentQty, yêu cầu $qty).";
                        break;
                    }

                    $booksToUpdate[(string)$bookId] = $book;
                }

                if ($insufficient) {
                    $message = "⚠ Không thể thanh toán: " . ($errors[0] ?? "Lỗi tồn kho.");
                } else {
                    // 2) TRỪ TIỀN TRONG users (theo TỔNG THANH TOÁN)
                    $usersCol->updateOne(
                        ['_id' => $userReload['_id']],
                        ['$inc' => ['balance' => -$totalAmount]]
                    );

                    // 3) TRỪ TỒN KHO TRONG books
                    foreach ($cartNow as $it) {
                        $bookIdStr = (string)$it['book_id'];
                        $qty       = (int)($it['quantity'] ?? 1);

                        if (!isset($booksToUpdate[$bookIdStr])) continue;
                        $book       = $booksToUpdate[$bookIdStr];
                        $currentQty = (int)($book['quantity'] ?? 0);
                        $newQty     = $currentQty - $qty;
                        if ($newQty < 0) $newQty = 0;

                        $newStatus = $newQty <= 0 ? 'out_of_stock' : ($book['status'] ?? 'active');

                        $booksCol->updateOne(
                            ['_id' => $book['_id']],
                            ['$set' => [
                                'quantity' => $newQty,
                                'status'   => $newStatus,
                            ]]
                        );
                    }

                    // 4) LƯU ĐƠN HÀNG VÀO orders (lưu rent_days cho TỪNG SÁCH)
                    $orderItems = [];
                    foreach ($cartNow as $it) {
                        $price = (int)($it['pricePerDay'] ?? 0);
                        $qty   = (int)($it['quantity'] ?? 1);
                        $days  = max(1, (int)($it['rent_days'] ?? 1));
                        $orderItems[] = [
                            'book_id'     => $it['book_id'],
                            'bookCode'    => $it['bookCode'] ?? '',
                            'bookName'    => $it['bookName'] ?? '',
                            'pricePerDay' => $price,
                            'quantity'    => $qty,
                            'rent_days'   => $days,
                            'subTotal'    => $price * $qty * $days
                        ];
                    }

                    $orderDoc = [
                        'user_id'        => $userReload['_id'],
                        'username'       => $userReload['username'] ?? '',
                        'total_per_day'  =>
                            $totalPerDay,        // thông tin tham khảo
                        'total_amount'   => $totalAmount,        // ⭐ tiền phải trả
                        'total_quantity' => $totalQuantity,      // ⭐ tổng số sách
                        'items'          => $orderItems,
                        'status'         => 'paid',
                        'created_at'     => new UTCDateTime()
                    ];
                    $ordersCol->insertOne($orderDoc);

                    // 5) CLEAR GIỎ (items = [])
                    if ($cartDoc) {
                        $cartsCol->updateOne(
                            ['_id' => $cartDoc['_id']],
                            ['$set' => [
                                'items'      => [],
                                'updated_at' => new UTCDateTime()
                            ]]
                        );
                    }

                    // 6) LẤY LẠI SỐ DƯ
                    $userReload2 = $usersCol->findOne(['_id' => $userReload['_id']]);
                    $balanceNew  = (int)($userReload2['balance'] ?? 0);

                    $message = "🎉 Thanh toán thành công! Số dư còn lại: " . number_format($balanceNew, 0, ',', '.') . " đ";
                }
            } else {
                $needMore = $totalAmount - $balanceDb;
                $message  = "⚠ Số dư của bạn không đủ. Bạn còn thiếu " . number_format($needMore, 0, ',', '.') . " đ. Vui lòng nạp thêm.";
            }
        }
    }

    // ⭐ LƯU MESSAGE VÀ REDIRECT ĐỂ TRÁNH RESUBMIT
    $_SESSION['cart_message'] = $message;
    header("Location: giohang.php");
    exit();
}

// ------- TÍNH TỔNG + TRẠNG THÁI THANH TOÁN -------
$totalPerDay          = calc_total_per_day($cartItems);          // tổng tiền/ngày (theo số lượng)
$totalAmount          = calc_total_amount_with_days($cartItems); // tổng thanh toán (theo số ngày từng quyển)
$totalQuantity        = calc_total_quantity($cartItems);         // tổng số sách trong giỏ hiện tại
$withinLimit          = ($totalQuantity > 0 && $totalQuantity <= 10);
$enoughBalance        = $totalAmount > 0 && $balance >= $totalAmount;
// ⚠️ Không cho confirm nếu còn đơn chưa kết thúc
$canConfirm           = $totalAmount > 0 && $enoughBalance && $withinLimit && !$hasUnreturned;
$needMoreDisplay      = (!$enoughBalance && $totalAmount > 0) ? ($totalAmount - $balance) : 0;
$totalQuantityDisplay = $totalQuantity;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Giỏ mượn sách</title>
    <link rel="stylesheet" href="../css/giohang.css">
</head>
<body>
<div class="page-overlay">
    <div class="container">

        <a href="danhsachsach.php" class="btn-back">⬅ Tiếp tục chọn sách</a>

        <h2>🛒 Giỏ mượn sách</h2>

        <p class="balance">
            Số dư của bạn: <strong><?= number_format($balance, 0, ',', '.'); ?> đ</strong>
        </p>

   <?php if ($hasUnreturned && !empty($cartItems)): ?>
    <p class="msg">
        ⚠ Bạn đang có ít nhất một đơn mượn <strong>chưa kết thúc</strong> (đã thanh toán hoặc đang mượn).
        Vui lòng xử lý xong đơn đó trước khi mượn thêm.
    </p>
<?php endif; ?>


        <?php if ($message !== ""): ?>
            <p class="msg"><?= htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></p>
        <?php endif; ?>

        <div class="table-wrapper">
            <?php if (empty($cartItems)): ?>
                <p>Giỏ mượn của bạn đang trống. Hãy quay lại danh sách sách để chọn nhé.</p>
            <?php else: ?>
                <form method="post">
                    <table>
                        <thead>
                        <tr>
                            <th>Mã sách</th>
                            <th>Tên sách</th>
                            <th>Giá thuê/ngày</th>
                            <th>Số lượng</th>
                            <th>Số ngày mượn</th>
                            <th>Thành tiền</th>
                            <th>Hành động</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cartItems as $it): ?>
                            <?php
                            $bookIdStr  = (string)$it['book_id'];
                            $bookCode   = $it['bookCode'] ?? '';
                            $bookName   = $it['bookName'] ?? '';
                            $price      = (int)($it['pricePerDay'] ?? 0);
                            $qty        = (int)($it['quantity'] ?? 1);
                            $rentDaysIt = max(1, (int)($it['rent_days'] ?? 1));
                            $subTotal   = $price * $qty * $rentDaysIt;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($bookCode, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($bookName, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                                <td><?= number_format($price, 0, ',', '.'); ?> đ</td>
                                <td>
                                    <input type="number"
                                           name="quantity[<?= htmlspecialchars($bookIdStr, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>]"
                                           value="<?= $qty; ?>"
                                           min="1">
                                </td>
                                <td>
                                    <input type="number"
                                           name="rent_days[<?= htmlspecialchars($bookIdStr, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>]"
                                           value="<?= $rentDaysIt; ?>"
                                           min="1"
                                           style="width:70px;">
                                </td>
                                <td><?= number_format($subTotal, 0, ',', '.'); ?> đ</td>
                                <td>
                                    <a class="btn-small delete"
                                       href="giohang.php?remove=<?= htmlspecialchars($bookIdStr, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>"
                                       onclick="return confirm('Xóa sách này khỏi giỏ mượn?');">
                                        Xóa
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="total-box">
                        Tổng thanh toán:
                        <strong><?= number_format($totalAmount, 0, ',', '.'); ?> đ</strong>
                    </p>

                    <!-- MỤC XÁC NHẬN ĐẶT MƯỢN -->
                    <div class="confirm-box">
                        <h3>✅ Xác nhận đặt mượn</h3>
                        <p>Tổng thanh toán 
                            <strong><?= number_format($totalAmount, 0, ',', '.'); ?> đ</strong>
                        </p>

                        <p>Tổng số sách:
                            <strong><?= (int)$totalQuantityDisplay; ?>/10</strong>
                            (tối đa 10 cuốn cho mỗi lần mượn)
                        </p>

                        <p>Số dư hiện tại: <strong><?= number_format($balance, 0, ',', '.'); ?> đ</strong></p>

                        <?php if ($totalAmount <= 0): ?>
                            <p class="confirm-status warning">Giỏ hàng hiện không có sản phẩm hợp lệ để thanh toán.</p>
                        <?php elseif ($hasUnreturned): ?>
                            <p class="confirm-status warning">
                                ⚠ Bạn đang có đơn mượn <strong>chưa kết thúc</strong>.
                                Vui lòng trả sách / chờ admin xử lý xong trước khi tạo đơn mới.
                            </p>
                        <?php elseif (!$withinLimit): ?>
                            <p class="confirm-status warning">
                                ⚠ Bạn đang chọn <strong><?= (int)$totalQuantityDisplay; ?></strong> cuốn.
                                Vui lòng giảm xuống còn tối đa <strong>10</strong> cuốn trước khi thanh toán.
                            </p>
                        <?php elseif ($enoughBalance): ?>
                            <p class="confirm-status success">✅ Bạn đủ số dư để thanh toán đơn mượn này.</p>
                        <?php else: ?>
                            <p class="confirm-status warning">
                                ⚠ Bạn còn thiếu
                                <strong><?= number_format($needMoreDisplay, 0, ',', '.'); ?> đ</strong>
                                để thanh toán. Vui lòng nạp thêm.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="cart-actions">
                        <button type="submit" name="action" value="update">
                            💾 Cập nhật giỏ
                        </button>
                        <button type="submit" name="action" value="clear"
                                onclick="return confirm('Bạn có chắc muốn xóa toàn bộ giỏ mượn?');">
                            🧹 Xóa toàn bộ giỏ
                        </button>

                        <!-- NÚT MỞ POPUP XÁC NHẬN -->
                        <button type="button"
                                id="btnOpenModal"
                            <?= !$canConfirm ? 'disabled style="opacity:0.6;cursor:not-allowed;"' : ''; ?>>
                            ✅ Xác nhận đặt mượn
                        </button>

                        <a href="profile.php" class="btn-topup">
                            ➕ Nạp tiền
                        </a>
                        <a href="trangchu.php" class="btn-topup">
                            🏠 Trang chủ
                        </a>
                    </div>

                    <!-- POPUP XÁC NHẬN THANH TOÁN -->
                    <div id="confirmModal" class="modal-overlay" style="display:none;">
                        <div class="modal-box">
                            <h3>Bạn có chắc muốn thanh toán?</h3>
                            <p>Tổng thanh toán 
                                <strong><?= number_format($totalAmount, 0, ',', '.'); ?> đ</strong>
                            </p>
                            <p>Số dư sau thanh toán (ước tính):
                                <strong><?= number_format(max($balance - $totalAmount, 0), 0, ',', '.'); ?> đ</strong>
                            </p>
                            <p>Tổng số sách trong đơn:
                                <strong><?= (int)$totalQuantityDisplay; ?>/10</strong>
                            </p>
                            <div class="modal-actions">
                                <!-- Nút này mới thực sự submit -->
                                <button type="submit" name="action" value="confirm" class="btn-confirm-final">
                                    ✅ Đồng ý thanh toán
                                </button>
                                <button type="button" class="btn-cancel-modal" id="btnCloseModal">
                                    ✖ Hủy
                                </button>
                            </div>
                        </div>
                    </div>

                </form>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var btnOpen  = document.getElementById('btnOpenModal');
    var btnClose = document.getElementById('btnCloseModal');
    var modal    = document.getElementById('confirmModal');

    if (!btnOpen || !modal) return;

    btnOpen.addEventListener('click', function () {
        if (btnOpen.disabled) return;
        modal.style.display = 'flex';
    });

    if (btnClose) {
        btnClose.addEventListener('click', function () {
            modal.style.display = 'none';
        });
    }

    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
});
</script>
</body>
</html>
