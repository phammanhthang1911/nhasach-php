<?php
session_start();
require __DIR__ . '/../Connection.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

// Chỉ admin mới được vào
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: trangchu.php");
    exit();
}

$message     = "";
$isEditing   = false;
$editingBook = null;

$collection  = $db->books;

// Các giá trị dùng chung
$BOOK_GROUPS = ["Kinh dị", "Trinh thám", "Khoa học", "Tình cảm", "Thiếu nhi"];
$LOCATIONS   = ["Hà Nội", "Đà Nẵng", "Hồ Chí Minh"];
$STATUS_LIST = [
    'active'       => 'Hoạt động',
    'out_of_stock' => 'Hết hàng',
    'deleted'      => 'Đã xóa'
];
// Trạng thái cho form chỉnh sửa (chỉ cho chọn Hoạt động / Đã xóa)
$EDITABLE_STATUS = [
    'active'  => 'Hoạt động',
    'deleted' => 'Đã xóa'
];

// ================== XỬ LÝ POST: THÊM / CẬP NHẬT ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action'] ?? 'add';
    $id          = $_POST['id'] ?? null;
    $bookCode    = trim($_POST['bookCode'] ?? '');
    $bookGroup   = trim($_POST['bookGroup'] ?? '');
    $bookName    = trim($_POST['bookName'] ?? '');
    $location    = trim($_POST['location'] ?? '');
    $quantity    = (int)($_POST['quantity'] ?? 0);
    $pricePerDay = (int)($_POST['pricePerDay'] ?? 0);

    // Nếu đang UPDATE thì mới có status
    $statusRaw = $_POST['status'] ?? null;

    // Trạng thái cuối cùng
    if ($action === 'add') {
        // Khi thêm mới → status luôn = active
        if ($quantity <= 0) {
            $message = "Số lượng khi thêm mới phải lớn hơn 0!";
        }
        $finalStatus = 'active';
    } else {
        // UPDATE
        if ($statusRaw === 'deleted') {
            $finalStatus = 'deleted';
        } else {
            if ($quantity <= 0) {
                $finalStatus = 'out_of_stock';
            } else {
                $finalStatus = 'active';
            }
        }
    }

    if ($bookCode === "" || $bookName === "" || $quantity < 0 || $pricePerDay < 0) {
        $message = "Vui lòng nhập đầy đủ thông tin hợp lệ!";
    } else {

        // ================== ADD ==================
        if ($action === 'add') {

            if ($quantity <= 0) {
                $message = "❌ Khi thêm sách, số lượng phải lớn hơn 0!";
            } else {
                // Kiểm tra trùng mã
                $existsCode = $collection->findOne(['bookCode' => $bookCode]);
                // Kiểm tra trùng tên trong cùng khu vực
                $existsName = $collection->findOne([
                    'bookName' => $bookName,
                    'location' => $location
                ]);

                if ($existsCode) {
                    $message = "⚠ Mã sách đã tồn tại!";
                } elseif ($existsName) {
                    $message = "⚠ Trong chi nhánh $location đã có sách '$bookName'!";
                } else {
                    $insert = $collection->insertOne([
                        'bookCode'    => $bookCode,
                        'bookGroup'   => $bookGroup,
                        'bookName'    => $bookName,
                        'location'    => $location,
                        'quantity'    => $quantity,
                        'pricePerDay' => $pricePerDay,
                        'borrowCount' => 0,
                        'status'      => 'active', // ✔ luôn active
                        'created_at'  => new UTCDateTime()
                    ]);
                    $message = "✅ Thêm sách thành công!";
                }
            }

        }

        // ================== UPDATE ==================
        elseif ($action === 'update' && $id) {
            try {
                $objectId = new ObjectId($id);

                $existsCode = $collection->findOne([
                    'bookCode' => $bookCode,
                    '_id'      => ['$ne' => $objectId]
                ]);

                $existsName = $collection->findOne([
                    'bookName' => $bookName,
                    'location' => $location,
                    '_id'      => ['$ne' => $objectId]
                ]);

                if ($existsCode) {
                    $message = "⚠ Mã sách đã thuộc về sách khác!";
                } elseif ($existsName) {
                    $message = "⚠ Trong chi nhánh $location đã có sách '$bookName'!";
                } else {
                    $update = $collection->updateOne(
                        ['_id' => $objectId],
                        ['$set' => [
                            'bookCode'    => $bookCode,
                            'bookGroup'   => $bookGroup,
                            'bookName'    => $bookName,
                            'location'    => $location,
                            'quantity'    => $quantity,
                            'pricePerDay' => $pricePerDay,
                            'status'      => $finalStatus,
                            'updated_at'  => new UTCDateTime()
                        ]]
                    );
                    $message = "✅ Cập nhật sách thành công!";
                }

            } catch (Exception $e) {
                $message = "Lỗi khi cập nhật sách.";
            }
        }
    }
}


// ============ XỬ LÝ “XÓA” → SOFT DELETE ============
if (isset($_GET['delete'])) {
    try {
        $id = $_GET['delete'];
        $collection->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => [
                'status'     => 'deleted',
                'updated_at' => new UTCDateTime()
            ]]
        );
        $message = "🗑️ Đã chuyển sách sang trạng thái 'Đã xóa'.";
    } catch (Exception $e) {
        $message = "Lỗi khi cập nhật trạng thái xóa.";
    }
}

// ============ XỬ LÝ LOAD SÁCH ĐỂ SỬA ============
if (isset($_GET['edit'])) {
    try {
        $id = $_GET['edit'];
        $editingBook = $collection->findOne(['_id' => new ObjectId($id)]);
        if ($editingBook) {
            $isEditing = true;
        }
    } catch (Exception $e) {
        $message = "Không tìm thấy sách cần sửa.";
    }
}

// ================== LỌC / TÌM KIẾM ==================
$searchName   = trim($_GET['searchName']   ?? '');
$searchGroup  = trim($_GET['searchGroup']  ?? '');
$searchLoc    = trim($_GET['searchLoc']    ?? '');
$searchStatus = trim($_GET['searchStatus'] ?? '');

$filter = [];

if ($searchName !== '') {
    // Tìm kiếm gần đúng (full-text search)
    $filter['$text'] = ['$search' => $searchName];
}

if ($searchGroup !== '' && $searchGroup !== 'all') {
    $filter['bookGroup'] = $searchGroup;
}
if ($searchLoc !== '' && $searchLoc !== 'all') {
    $filter['location'] = $searchLoc;
}
if ($searchStatus !== '' && $searchStatus !== 'all') {
    $filter['status'] = $searchStatus;
}

// ================== PHÂN TRANG ==================
$perPage = 10;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$skip    = ($page - 1) * $perPage;

$totalBooks = $collection->count($filter);

$totalPages = max(1, ceil($totalBooks / $perPage));

$options = [
    'skip'  => $skip,
    'limit' => $perPage
];

// Nếu có từ khóa tìm kiếm → dùng textScore để sort theo độ khớp
if ($searchName !== '') {
    $options['projection'] = ['score' => ['$meta' => 'textScore']];
    $options['sort']       = ['score' => ['$meta' => 'textScore']];
} else {
    // Không tìm kiếm → sort theo thời gian tạo
    $options['sort'] = ['created_at' => -1];
}

$booksCursor = $collection->find($filter, $options);
// DÙNG toArray() THAY CHO iterator_to_array() ĐỂ TRÁNH LỖI CURSOR CANNOT REWIND
$books = $booksCursor->toArray();




// Giá trị mặc định cho form
$statusCurrent = $isEditing ? ($editingBook['status'] ?? 'active') : 'active';
$currentGroup  = $isEditing ? ($editingBook['bookGroup'] ?? '') : '';
$currentLoc    = $isEditing ? ($editingBook['location'] ?? 'Hà Nội') : 'Hà Nội';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý sách</title>
    <link rel="stylesheet" href="../css/quanlysach.css">
</head>
<!-- CSS cho 2 nút đồng bộ -->
    <style>
        .sync-bar {
            display: flex;
            gap: 10px;
            margin: 12px 0 18px;
            flex-wrap: wrap;
        }
        .btn-sync {
            display: inline-block;
            padding: 8px 14px;
            background-color: #b36b3f;
            color: #fff7e6;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
            border: 2px solid #f0d3b0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.25);
            transition: 0.2s;
        }
        .btn-sync:hover {
            background-color: #f0d3b0;
            color: #5a2f1a;
            transform: translateY(-1px);
        }
        .btn-sync:active {
            transform: translateY(0);
        }
    </style>
<body>
<div class="page-overlay">
    <div class="container">
        <a href="trangchu.php" class="btn-back">⬅ Quay về Trang chủ</a>


        <h2>📚 Quản lý sách</h2>

        <?php if ($message !== ""): ?>
            <p class="msg"><?= htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></p>
        <?php endif; ?>

        <!-- THANH TÌM KIẾM / LỌC -->
        <div class="filter-wrapper">
            <form method="get" class="filter-form">
                <input type="text" name="searchName" placeholder="Tìm theo tên sách..."
                       value="<?= htmlspecialchars($searchName, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">

                <select name="searchGroup">
                    <option value="all">-- Nhóm sách --</option>
                    <?php foreach ($BOOK_GROUPS as $g): ?>
                        <option value="<?= $g; ?>" <?= $searchGroup === $g ? 'selected' : ''; ?>>
                            <?= $g; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="searchLoc">
                    <option value="all">-- Khu vực --</option>
                    <?php foreach ($LOCATIONS as $loc): ?>
                        <option value="<?= $loc; ?>" <?= $searchLoc === $loc ? 'selected' : ''; ?>>
                            <?= $loc; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="searchStatus">
                    <option value="all">-- Trạng thái --</option>
                    <?php foreach ($STATUS_LIST as $key => $label): ?>
                        <option value="<?= $key; ?>" <?= $searchStatus === $key ? 'selected' : ''; ?>>
                            <?= $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit">🔍 Lọc</button>
                <a href="quanlysach.php" class="btn-reset">Xóa lọc</a>
            </form>
        </div>

        <!-- FORM THÊM / SỬA (ĐƯỢC BO TRONG 1 KHỐI) -->
        <div class="form-wrapper">
            <h3><?= $isEditing ? "✏️ Sửa sách" : "➕ Thêm sách mới"; ?></h3>
            <form method="post" class="form-add">
                <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'add'; ?>">
                <?php if ($isEditing): ?>
                    <input type="hidden" name="id" value="<?= (string)$editingBook['_id']; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-col">
                        <label>Mã sách:</label>
                        <input type="text" name="bookCode" required
                               value="<?= $isEditing ? htmlspecialchars($editingBook['bookCode'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : ''; ?>">
                    </div>
                    <div class="form-col">
                        <label>Nhóm sách:</label>
                        <select name="bookGroup" required>
                            <?php foreach ($BOOK_GROUPS as $g): ?>
                                <option value="<?= $g; ?>" <?= $g === $currentGroup ? 'selected' : ''; ?>>
                                    <?= $g; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col">
                        <label>Tên sách:</label>
                        <input type="text" name="bookName" required
                               value="<?= $isEditing ? htmlspecialchars($editingBook['bookName'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : ''; ?>">
                    </div>
                    <div class="form-col">
                        <label>Khu vực:</label>
                        <select name="location" required>
                            <?php foreach ($LOCATIONS as $loc): ?>
                                <option value="<?= $loc; ?>" <?= $loc === $currentLoc ? 'selected' : ''; ?>>
                                    <?= $loc; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col">
                        <label>Số lượng tồn:</label>
                        <input type="number" name="quantity" min="0" required
                               value="<?= $isEditing ? (int)$editingBook['quantity'] : 1; ?>">
                    </div>
                    <div class="form-col">
                        <label>Giá thuê / ngày:</label>
                        <input type="number" name="pricePerDay" min="0" required
                               value="<?= $isEditing ? (int)$editingBook['pricePerDay'] : 10000; ?>">
                    </div>
        <?php if ($isEditing): ?>
<div class="form-col">
    <label>Trạng thái:</label>
    <select name="status">
        <?php foreach ($EDITABLE_STATUS as $key => $label): ?>
            <option value="<?= $key; ?>" <?= $statusCurrent === $key ? 'selected' : ''; ?>>
                <?= $label; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if ($statusCurrent === 'out_of_stock'): ?>
        <small style="color:#888;">
            Hiện tại sách đang ở trạng thái <b>Hết hàng</b> (tự động khi số lượng = 0).
            Nếu muốn kích hoạt lại, hãy tăng số lượng & để trạng thái là Hoạt động.
        </small>
    <?php endif; ?>
</div>
<?php endif; ?>

                </div>

                <div class="form-actions">
                    <button type="submit">
                        <?= $isEditing ? "Lưu thay đổi" : "Thêm sách"; ?>
                    </button>

                    <?php if ($isEditing): ?>
                        <a class="btn-cancel" href="quanlysach.php">Hủy sửa</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

<div class="sync-bar">
    <a href="sync_books_to_hn.php" class="btn-sync">
        ⬇ Đồng bộ Hà Nội
    </a>

    <a href="sync_books_to_dn.php" class="btn-sync">
        ⬇ Đồng bộ Đà Nẵng
    </a>
     <a href="sync_books_to_hcm.php" class="btn-sync">
        ⬇ Đồng bộ Hồ Chí Minh 
    </a>

</div>




        <!-- DANH SÁCH SÁCH -->
        <div class="table-wrapper">
            <h3>Danh sách sách hiện có</h3>
            <table>
                <thead>
                    <tr>
                        <th>BookCode</th>
                        <th>Nhóm</th>
                        <th>Tên sách</th>
                        <th>Khu vực</th>
                        <th>Tồn</th>
                        <th>Giá/ngày</th>
                        <th>Lượt mượn</th>
                        <th>Trạng thái</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($books) === 0): ?>
                    <tr><td colspan="9" style="text-align:center;">Không tìm thấy sách nào.</td></tr>
                <?php else: ?>
                    <?php foreach ($books as $b): ?>
                        <?php
                        $statusKey   = $b['status'] ?? 'active';
                        $statusLabel = $STATUS_LIST[$statusKey] ?? 'Hoạt động';
                        $statusClass = 'status-active';
                        if ($statusKey === 'out_of_stock') $statusClass = 'status-out';
                        if ($statusKey === 'deleted')      $statusClass = 'status-deleted';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($b['bookCode'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($b['bookGroup'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($b['bookName'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($b['location'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></td>
                            <td><?= (int)($b['quantity'] ?? 0); ?></td>
                            <td><?= number_format((int)($b['pricePerDay'] ?? 0), 0, ',', '.'); ?></td>
                            <td><?= (int)($b['borrowCount'] ?? 0); ?></td>
                            <td>
                                <span class="status-badge <?= $statusClass; ?>">
                                    <?= $statusLabel; ?>
                                </span>
                            </td>
                            <td>
                                <a class="btn-small edit" href="quanlysach.php?edit=<?= (string)$b['_id']; ?>">Sửa</a>
                                <a class="btn-small delete"
                                   href="quanlysach.php?delete=<?= (string)$b['_id']; ?>"
                                   onclick="return confirm('Đánh dấu sách này là Đã xóa?');">
                                   Xóa
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- PHÂN TRANG -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    // Prev
                    if ($page > 1):
                        $q = $_GET;
                        $q['page'] = $page - 1;
                        ?>
                        <a class="page-link" href="quanlysach.php?<?= htmlspecialchars(http_build_query($q), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">&laquo; Trước</a>
                    <?php endif; ?>

                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php
                        $q = $_GET;
                        $q['page'] = $p;
                        ?>
                        <a class="page-link <?= $p == $page ? 'active' : ''; ?>"
                           href="quanlysach.php?<?= htmlspecialchars(http_build_query($q), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
                            <?= $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages):
                        $q = $_GET;
                        $q['page'] = $page + 1;
                        ?>
                        <a class="page-link" href="quanlysach.php?<?= htmlspecialchars(http_build_query($q), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">Sau &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

    </div>
</div>
</body>
</html>
