<?php
session_start();

// 数据库文件路径
$db_file = 'aftersales.db';
$db_exists = file_exists($db_file);

// 打开数据库，如果不存在会自动创建
$db = new SQLite3($db_file);

// --- 初始化数据库 ---
if(!$db_exists){
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        role TEXT,
        created_at TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_no TEXT,
        logistics_no TEXT,
        category TEXT,
        description TEXT,
        collector TEXT,
        manager TEXT,
        handler TEXT,
        status TEXT,
        created_at TEXT,
        handled_at TEXT,
        admin_modified TEXT,
        score INTEGER,
        last_modified TEXT,
        modified_by TEXT,
        platform TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS login_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        action TEXT,
        ip TEXT,
        created_at TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE,
        setting_value TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS ticket_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER,
        changed_by TEXT,
        change_description TEXT,
        changed_at TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS platforms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE,
        created_at TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS backups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT,
        size INTEGER,
        created_at TEXT
    )");

    // 默认管理员
    $admin_pass = md5('admin123');
    $db->exec("INSERT OR IGNORE INTO users (username,password,role,created_at) VALUES ('admin','$admin_pass','admin',datetime('now'))");

    // 默认系统设置
    $db->exec("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('site_name', '售后信息管理系统')");
    $db->exec("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('theme_color', '#3498db')");
    
    // 默认平台
    $default_platforms = ['淘宝', '京东', '拼多多', '天猫', '官网商城'];
    foreach($default_platforms as $platform){
        $db->exec("INSERT OR IGNORE INTO platforms (name, created_at) VALUES ('$platform', datetime('now'))");
    }
}

// 获取系统设置
function getSetting($key, $default=''){
    global $db;
    $res = $db->querySingle("SELECT setting_value FROM settings WHERE setting_key='$key'", true);
    return $res ? $res['setting_value'] : $default;
}

$site_name = getSetting('site_name','售后信息管理系统');
$theme_color = getSetting('theme_color','#3498db');

// --- 登录处理 ---
if(isset($_POST['login'])){
    $username = $_POST['username'];
    $password = md5($_POST['password']);
    $res = $db->querySingle("SELECT * FROM users WHERE username='$username' AND password='$password'", true);
    if($res){
        $_SESSION['user']=$res['username'];
        $_SESSION['role']=$res['role'];
        $db->exec("INSERT INTO login_log (username,action,ip,created_at) VALUES ('$username','login','".$_SERVER['REMOTE_ADDR']."',datetime('now'))");
        header("Location: index.php"); exit;
    } else { $error="用户名或密码错误"; }
}

// --- 登出 ---
if(isset($_GET['action']) && $_GET['action']=='logout'){ session_destroy(); header("Location: index.php"); exit; }

// --- 导出CSV ---
if(isset($_GET['export_csv']) && $_SESSION['role']=='admin'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=工单数据_'.date('YmdHis').'.csv');
    
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // 添加BOM头，解决中文乱码
    
    // CSV表头
    $headers = ['ID', '订单号', '物流号', '平台', '分类', '状态', '采集人', '经办人', '处理人', '创建时间', '最后修改时间', '最后修改人', '描述'];
    fputcsv($output, $headers);
    
    // 获取所有工单数据
    $where = "1=1";
    if(isset($_GET['status']) && !empty($_GET['status'])) $where .= " AND status='{$_GET['status']}'";
    if(isset($_GET['start_date']) && !empty($_GET['start_date'])) $where .= " AND date(created_at)>='{$_GET['start_date']}'";
    if(isset($_GET['end_date']) && !empty($_GET['end_date'])) $where .= " AND date(created_at)<='{$_GET['end_date']}'";
    if(isset($_GET['search']) && !empty($_GET['search'])) {
        $search = $_GET['search'];
        $where .= " AND (order_no LIKE '%$search%' OR logistics_no LIKE '%$search%')";
    }
    
    $tickets_res = $db->query("SELECT * FROM tickets WHERE $where ORDER BY created_at DESC");
    while($ticket = $tickets_res->fetchArray(SQLITE3_ASSOC)){
        $row = [
            $ticket['id'],
            $ticket['order_no'],
            $ticket['logistics_no'],
            $ticket['platform'],
            $ticket['category'],
            $ticket['status'],
            $ticket['collector'],
            $ticket['manager'],
            $ticket['handler'],
            $ticket['created_at'],
            $ticket['last_modified'],
            $ticket['modified_by'],
            $ticket['description']
        ];
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// --- 备份数据库 ---
if(isset($_POST['backup_db']) && $_SESSION['role']=='admin'){
    $backup_dir = 'backups/';
    if(!is_dir($backup_dir)){
        mkdir($backup_dir, 0755, true);
    }
    
    $backup_file = $backup_dir . 'aftersales_backup_' . date('YmdHis') . '.db';
    copy($db_file, $backup_file);
    
    // 记录备份信息
    $filename = basename($backup_file);
    $size = filesize($backup_file);
    $created_at = date('Y-m-d H:i:s');
    
    $db->exec("INSERT INTO backups (filename, size, created_at) VALUES ('$filename', $size, '$created_at')");
    
    header("Location: index.php?tab=settings&backup_success=1"); exit;
}

// --- 恢复数据库 ---
if(isset($_POST['restore_db']) && $_SESSION['role']=='admin' && !empty($_FILES['backup_file']['tmp_name'])){
    $backup_file = $_FILES['backup_file']['tmp_name'];
    
    // 检查文件是否为有效的SQLite数据库
    if(filesize($backup_file) > 0){
        // 先备份当前数据库
        $current_backup = 'backups/restore_backup_' . date('YmdHis') . '.db';
        copy($db_file, $current_backup);
        
        // 恢复数据库
        copy($backup_file, $db_file);
        
        header("Location: index.php?tab=settings&restore_success=1"); exit;
    }
}

// --- 删除备份 ---
if(isset($_GET['delete_backup']) && $_SESSION['role']=='admin'){
    $backup_id = intval($_GET['delete_backup']);
    $backup = $db->querySingle("SELECT filename FROM backups WHERE id=$backup_id", true);
    
    if($backup){
        $backup_file = 'backups/' . $backup['filename'];
        if(file_exists($backup_file)){
            unlink($backup_file);
        }
        $db->exec("DELETE FROM backups WHERE id=$backup_id");
    }
    
    header("Location: index.php?tab=settings"); exit;
}

// --- 新增/编辑工单 ---
if(isset($_POST['save_ticket'])){
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $order_no = $_POST['order_no'];
    $logistics_no = $_POST['logistics_no'];
    $category = $_POST['category'];
    $description = $_POST['description'];
    $collector = $_POST['collector'];
    $manager = $_POST['manager'];
    $handler = $_POST['handler'];
    $platform = $_POST['platform'];
    $status = isset($_POST['status'])?$_POST['status']:'未处理';
    $created_at = date('Y-m-d H:i:s');
    $handled_at = ($status=='已处理') ? date('Y-m-d H:i:s') : null;
    $score = ($status=='已处理') ? 100 : 0;
    $last_modified = date('Y-m-d H:i:s');
    $modified_by = $_SESSION['user'];
    
    // 记录修改历史
    $change_description = "";
    
    // 一般用户只能创建新工单，不能修改已有工单
    if($_SESSION['role']!='admin' && $id>0){
        die("权限不足或工单已完成");
    }

    // 已处理工单不可修改
    if($id>0){
        $existing = $db->querySingle("SELECT * FROM tickets WHERE id=$id",true);
        if($existing['status']=='已处理'){ die("已处理工单不可修改"); }
        
        // 记录修改历史
        $changes = [];
        if($existing['order_no'] != $order_no) $changes[] = "订单号: {$existing['order_no']} → {$order_no}";
        if($existing['logistics_no'] != $logistics_no) $changes[] = "物流号: {$existing['logistics_no']} → {$logistics_no}";
        if($existing['category'] != $category) $changes[] = "分类: {$existing['category']} → {$category}";
        if($existing['description'] != $description) $changes[] = "描述已修改";
        if($existing['collector'] != $collector) $changes[] = "采集人: {$existing['collector']} → {$collector}";
        if($existing['manager'] != $manager) $changes[] = "经办人: {$existing['manager']} → {$manager}";
        if($existing['handler'] != $handler) $changes[] = "处理人: {$existing['handler']} → {$handler}";
        if($existing['status'] != $status) $changes[] = "状态: {$existing['status']} → {$status}";
        if($existing['platform'] != $platform) $changes[] = "平台: {$existing['platform']} → {$platform}";
        
        if(!empty($changes)) {
            $change_description = implode("; ", $changes);
        }
    }

    if($id>0){
        $stmt = $db->prepare("UPDATE tickets SET order_no=:order_no,logistics_no=:logistics_no,category=:category,description=:description,collector=:collector,manager=:manager,handler=:handler,status=:status,handled_at=:handled_at,score=:score,last_modified=:last_modified,modified_by=:modified_by,platform=:platform WHERE id=:id");
        $stmt->bindValue(':id',$id,SQLITE3_INTEGER);
    } else {
        // 自动生成流水号 order_no
        if(empty($order_no)) $order_no = 'SN'.date('YmdHis');
        $stmt = $db->prepare("INSERT INTO tickets (order_no,logistics_no,category,description,collector,manager,handler,status,created_at,handled_at,score,last_modified,modified_by,platform) VALUES (:order_no,:logistics_no,:category,:description,:collector,:manager,:handler,:status,:created_at,:handled_at,:score,:last_modified,:modified_by,:platform)");
        $stmt->bindValue(':created_at',$created_at,SQLITE3_TEXT);
        $change_description = "创建新工单";
    }
    $stmt->bindValue(':order_no',$order_no,SQLITE3_TEXT);
    $stmt->bindValue(':logistics_no',$logistics_no,SQLITE3_TEXT);
    $stmt->bindValue(':category',$category,SQLITE3_TEXT);
    $stmt->bindValue(':description',$description,SQLITE3_TEXT);
    $stmt->bindValue(':collector',$collector,SQLITE3_TEXT);
    $stmt->bindValue(':manager',$manager,SQLITE3_TEXT);
    $stmt->bindValue(':handler',$handler,SQLITE3_TEXT);
    $stmt->bindValue(':status',$status,SQLITE3_TEXT);
    $stmt->bindValue(':handled_at',$handled_at,SQLITE3_TEXT);
    $stmt->bindValue(':score',$score,SQLITE3_INTEGER);
    $stmt->bindValue(':last_modified',$last_modified,SQLITE3_TEXT);
    $stmt->bindValue(':modified_by',$modified_by,SQLITE3_TEXT);
    $stmt->bindValue(':platform',$platform,SQLITE3_TEXT);
    $stmt->execute();
    
    // 如果是修改工单，记录修改历史
    if($id>0 && !empty($change_description)) {
        $ticket_id = $id;
        $changed_by = $_SESSION['user'];
        $changed_at = date('Y-m-d H:i:s');
        
        $history_stmt = $db->prepare("INSERT INTO ticket_history (ticket_id, changed_by, change_description, changed_at) VALUES (:ticket_id, :changed_by, :change_description, :changed_at)");
        $history_stmt->bindValue(':ticket_id', $ticket_id, SQLITE3_INTEGER);
        $history_stmt->bindValue(':changed_by', $changed_by, SQLITE3_TEXT);
        $history_stmt->bindValue(':change_description', $change_description, SQLITE3_TEXT);
        $history_stmt->bindValue(':changed_at', $changed_at, SQLITE3_TEXT);
        $history_stmt->execute();
    }

    header("Location: index.php?tab=view"); exit;
}

// --- 删除工单 ---
if(isset($_GET['del_ticket']) && $_SESSION['role']=='admin'){
    $id=intval($_GET['del_ticket']);
    $db->exec("DELETE FROM tickets WHERE id=$id");
    $db->exec("DELETE FROM ticket_history WHERE ticket_id=$id");
    header("Location: index.php?tab=view"); exit;
}

// --- 添加用户 ---
if(isset($_POST['add_user']) && $_SESSION['role']=='admin'){
    $username = $_POST['username'];
    $password = md5($_POST['password']);
    $role = $_POST['role'];
    $db->exec("INSERT INTO users (username,password,role,created_at) VALUES ('$username','$password','$role',datetime('now'))");
    header("Location: index.php?tab=users"); exit;
}

// --- 修改用户密码 ---
if(isset($_POST['change_password'])){
    $user_id = intval($_POST['user_id']);
    $new_password = md5($_POST['new_password']);
    
    // 管理员可以修改任何用户密码，普通用户只能修改自己的密码
    if($_SESSION['role'] == 'admin' || ($_SESSION['role'] == 'user' && $user_id == $_SESSION['user_id'])){
        $db->exec("UPDATE users SET password='$new_password' WHERE id=$user_id");
        $success_msg = "密码修改成功";
    } else {
        $error_msg = "权限不足";
    }
}

// --- 删除用户 ---
if(isset($_GET['del_user']) && $_SESSION['role']=='admin'){
    $id=intval($_GET['del_user']);
    $user = $db->querySingle("SELECT username FROM users WHERE id=$id",true);
    if($user && $user['username'] != $_SESSION['user']){
        $db->exec("DELETE FROM users WHERE id=$id");
    }
    header("Location: index.php?tab=users"); exit;
}

// --- 保存系统设置 ---
if(isset($_POST['save_settings']) && $_SESSION['role']=='admin'){
    $site_name = $_POST['site_name'];
    $theme_color = $_POST['theme_color'];
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (setting_key,setting_value) VALUES ('site_name',:site_name)");
    $stmt->bindValue(':site_name',$site_name,SQLITE3_TEXT); $stmt->execute();
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (setting_key,setting_value) VALUES ('theme_color',:theme_color)");
    $stmt->bindValue(':theme_color',$theme_color,SQLITE3_TEXT); $stmt->execute();
    header("Location: index.php?tab=settings"); exit;
}

// --- 添加工单来源平台 ---
if(isset($_POST['add_platform']) && $_SESSION['role']=='admin'){
    $platform_name = trim($_POST['platform_name']);
    if(!empty($platform_name)){
        $db->exec("INSERT OR IGNORE INTO platforms (name, created_at) VALUES ('$platform_name', datetime('now'))");
    }
    header("Location: index.php?tab=settings"); exit;
}

// --- 删除平台 ---
if(isset($_GET['del_platform']) && $_SESSION['role']=='admin'){
    $id=intval($_GET['del_platform']);
    $db->exec("DELETE FROM platforms WHERE id=$id");
    header("Location: index.php?tab=settings"); exit;
}

// --- 查询工单 ---
$where = "1=1";
$search_query = "";

if(isset($_GET['filter'])){
    if(!empty($_GET['status'])) $where .= " AND status='{$_GET['status']}'";
    if(!empty($_GET['start_date'])) $where .= " AND date(created_at)>='{$_GET['start_date']}'";
    if(!empty($_GET['end_date'])) $where .= " AND date(created_at)<='{$_GET['end_date']}'";
    if(!empty($_GET['search'])) {
        $search = SQLite3::escapeString($_GET['search']);
        $where .= " AND (order_no LIKE '%$search%' OR logistics_no LIKE '%$search%')";
        $search_query = "&search=" . urlencode($_GET['search']);
    }
}

// 分页设置
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20; // 每页显示20条记录
$offset = ($page - 1) * $per_page;

// 获取总记录数
$total_records = $db->querySingle("SELECT COUNT(*) FROM tickets WHERE $where");
$total_pages = ceil($total_records / $per_page);

// 获取当前页数据
$tickets_res = $db->query("SELECT * FROM tickets WHERE $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$tickets = []; 
while($row = $tickets_res->fetchArray(SQLITE3_ASSOC)) {
    $tickets[] = $row;
}

// --- 用户列表 ---
$users_res = $db->query("SELECT * FROM users");
$all_users = []; while($row=$users_res->fetchArray(SQLITE3_ASSOC)) $all_users[]=$row;
$regular_users = array_filter($all_users,function($u){return $u['role']=='user';});
$admin_users = array_filter($all_users,function($u){return $u['role']=='admin';});

// --- 平台列表 ---
$platforms_res = $db->query("SELECT * FROM platforms ORDER BY name");
$platforms = []; while($row=$platforms_res->fetchArray(SQLITE3_ASSOC)) $platforms[]=$row;

// --- 备份列表 ---
$backups_res = $db->query("SELECT * FROM backups ORDER BY created_at DESC");
$backups = []; while($row=$backups_res->fetchArray(SQLITE3_ASSOC)) $backups[]=$row;

// 获取当前用户ID
$current_user_id = 0;
$current_user_name = "";
 if (isset($_SESSION['user'])){
	 foreach($all_users as $user){
    if($user['username'] == $_SESSION['user']){
		$current_user_name = $user['username'];
        $current_user_id = $user['id'];
        break;
    }
}
}

// 获取当前标签
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'add';

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $site_name;?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
/* === 原始 CSS 风格保留 === */
*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;}
body{background:linear-gradient(135deg,#f5f7fa 0%,#c3cfe2 100%);min-height:100vh;padding:20px;display:flex;flex-direction:column;align-items:center;}
.container{width:100%;max-width:1400px;background:white;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.1);overflow:hidden;margin-top:20px;}
header{background:#2c3e50;color:white;padding:20px;display:flex;justify-content:space-between;align-items:center;}
.logo{display:flex;align-items:center;gap:10px;}
.logo i{font-size:24px;}
.user-info{display:flex;align-items:center;gap:15px;}
.user-info img{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid <?php echo $theme_color;?>;}
.logout-btn{background:#e74c3c;color:white;border:none;padding:8px 15px;border-radius:5px;cursor:pointer;display:flex;align-items:center;gap:5px;text-decoration:none;}
.logout-btn:hover{background:#c0392b;}
.tabs{display:flex;background:#34495e;overflow-x:auto;}
.tablink{background:#34495e;color:#ecf0f1;border:none;padding:15px 20px;cursor:pointer;font-size:16px;display:flex;align-items:center;gap:8px;transition:background 0.3s;white-space:nowrap;text-decoration:none;}
.tablink:hover{background:#2c3e50;}
.tablink.active{background:<?php echo $theme_color;?>;}
.tabcontent{display:none;padding:30px;animation:fadeEffect 0.5s;}
@keyframes fadeEffect{from{opacity:0;}to{opacity:1;}}
.form-group{margin-bottom:20px;}
.form-group label{display:block;margin-bottom:8px;font-weight:600;color:#2c3e50;}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:12px;border:1px solid #ddd;border-radius:5px;font-size:16px;}
.form-group textarea{min-height:120px;resize:vertical;}
.btn{background:<?php echo $theme_color;?>;color:white;border:none;padding:12px 20px;border-radius:5px;cursor:pointer;font-size:16px;display:inline-flex;align-items:center;gap:8px;transition:background 0.3s;text-decoration:none;}
.btn:hover{background:#2980b9;}
.btn-success{background:#2ecc71;}
.btn-success:hover{background:#27ae60;}
.btn-danger{background:#e74c3c;}
.btn-danger:hover{background:#c0392b;}
.btn-info{background:#17a2b8;}
.btn-info:hover{background:#138496;}
.btn-warning{background:#f39c12;}
.btn-warning:hover{background:#e67e22;}
.login-container{width:100%;max-width:400px;background:white;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.1);padding:30px;margin-top:50px;}
.login-container h2{text-align:center;margin-bottom:30px;color:#2c3e50;}
.alert{padding:15px;border-radius:5px;margin-bottom:20px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
.alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
.alert-info{background:#d1ecf1;color:#0c5460;border:1px solid #bee5eb;}
table{width:100%;border-collapse:collapse;table-layout:fixed;}
table th,table td{padding:12px;border:1px solid #ddd;text-align:left;word-wrap:break-word;}
table th{background:#f4f6f7;position:sticky;top:0;}
.history-container {margin-top: 20px;border-top: 1px solid #ddd;padding-top: 20px;}
.history-item {background: #f9f9f9;padding: 10px;margin-bottom: 10px;border-radius: 5px;border-left: 4px solid <?php echo $theme_color;?>;}
.history-meta {font-size: 12px;color: #777;margin-bottom: 5px;}
.history-content {font-size: 14px;}
.settings-section {margin-bottom: 30px;padding: 20px;border: 1px solid #ddd;border-radius: 5px;}
.settings-section h3 {margin-bottom: 15px;color: #2c3e50;}
.platform-list {display: flex;flex-wrap: wrap;gap: 10px;margin-top: 10px;}
.platform-item {background: #f0f0f0;padding: 8px 15px;border-radius: 20px;display: flex;align-items: center;gap: 5px;}
.platform-item .delete {color: #e74c3c;cursor: pointer;}
.change-password-form {background: #f9f9f9;padding: 20px;border-radius: 5px;margin-top: 20px;}
.backup-item {background: #f8f9fa;padding: 15px;border-radius: 5px;margin-bottom: 10px;display: flex;justify-content: space-between;align-items: center;}
.backup-info {flex: 1;}
.backup-actions {display: flex;gap: 10px;}

/* 表格列宽固定比例 */
.ticket-table th:nth-child(1), .ticket-table td:nth-child(1) {width: 4%;} /* ID */
.ticket-table th:nth-child(2), .ticket-table td:nth-child(2) {width: 10%;} /* 订单号 */
.ticket-table th:nth-child(3), .ticket-table td:nth-child(3) {width: 10%;} /* 物流号 */
.ticket-table th:nth-child(4), .ticket-table td:nth-child(4) {width: 8%;} /* 平台 */
.ticket-table th:nth-child(5), .ticket-table td:nth-child(5) {width: 10%;} /* 分类 */
.ticket-table th:nth-child(6), .ticket-table td:nth-child(6) {width: 8%;} /* 状态 */
.ticket-table th:nth-child(7), .ticket-table td:nth-child(7) {width: 8%;} /* 采集人 */
.ticket-table th:nth-child(8), .ticket-table td:nth-child(8) {width: 8%;} /* 经办人 */
.ticket-table th:nth-child(9), .ticket-table td:nth-child(9) {width: 8%;} /* 处理人 */
.ticket-table th:nth-child(10), .ticket-table td:nth-child(10) {width: 10%;} /* 创建时间 */
.ticket-table th:nth-child(11), .ticket-table td:nth-child(11) {width: 12%;} /* 最后修改 */
.ticket-table th:nth-child(12), .ticket-table td:nth-child(12) {width: 8%;} /* 操作 */

/* 长文本自动换行 */
.long-text {word-break: break-all;word-wrap: break-word;white-space: normal;}

/* 分页样式 */
.pagination {display: flex;justify-content: center;margin-top: 20px;gap: 5px;}
.pagination a, .pagination span {padding: 8px 12px;border: 1px solid #ddd;border-radius: 4px;text-decoration: none;color: #333;}
.pagination a:hover {background: <?php echo $theme_color;?>;color: white;}
.pagination .current {background: <?php echo $theme_color;?>;color: white;border-color: <?php echo $theme_color;?>;}
.pagination .disabled {color: #ccc;cursor: not-allowed;}

/* 分页信息 */
.page-info {text-align: center;margin-bottom: 15px;color: #666;font-size: 14px;}

/* 筛选表单 */
.filter-form {background: #f8f9fa;padding: 15px;border-radius: 5px;margin-bottom: 20px;display: flex;flex-wrap: wrap;gap: 15px;align-items: end;}
.filter-group {flex: 1;min-width: 200px;}
.filter-group label {display: block;margin-bottom: 5px;font-weight: 600;color: #2c3e50;}
.filter-group select, .filter-group input {width: 100%;padding: 8px;border: 1px solid #ddd;border-radius: 4px;}

/* 导出按钮 */
.export-btn {margin-bottom: 20px;}

/* 搜索框样式 */
.search-group {flex: 2;min-width: 300px;}
.search-group input {width: 100%;}

/* 响应式表格 */
@media (max-width: 1200px) {
    .container {max-width: 100%;margin: 10px;}
    .tabcontent {padding: 15px;}
    .ticket-table {font-size: 14px;}
    .ticket-table th, .ticket-table td {padding: 8px;}
    .filter-form {flex-direction: column;}
    .filter-group, .search-group {min-width: 100%;}
}

/* 表格滚动容器 */
.table-container {overflow-x: auto;max-width: 100%;margin-bottom: 20px;}

/* 文件上传样式 */
.file-upload {border: 2px dashed #ddd;padding: 20px;text-align: center;border-radius: 5px;margin-bottom: 15px;}
.file-upload:hover {border-color: <?php echo $theme_color;?>;}


 .description{text-align: center; margin-bottom: 50px; font-size: 1.1rem; line-height: 1.6; color: #555; max-width: 800px; margin-left: auto; margin-right: auto;}
 
/* 提示框基本样式 */
 .tooltip{position:relative;display:inline-block;border-bottom:2px dotted #3498db;cursor:pointer;font-weight:600;color:#2980b9;}
/* 提示框文本 */
 .tooltip .tooltiptext{visibility:hidden;width:200px;background-color:#333;color:#fff;text-align:center;border-radius:6px;padding:10px;position:absolute;z-index:1;bottom:125%;left:50%;margin-left:-100px;opacity:0;transition:opacity 0.3s,visibility 0.3s;font-weight:normal;font-size:0.9rem;line-height:1.4;}
/* 提示框箭头 */
 .tooltip .tooltiptext::after{content:"";position:absolute;top:100%;left:50%;margin-left:-5px;border-width:5px;border-style:solid;border-color:#333 transparent transparent transparent;}
/* 显示提示框文本 */
 .tooltip:hover .tooltiptext{visibility:visible;opacity:1;}
.tooltip-right .tooltiptext{top:-5px;bottom:auto;left:110%;right:auto;margin-left:0;}
.tooltip-right .tooltiptext::after{top:15px;right:100%;left:auto;border-color:transparent #333 transparent transparent;}

</style>
</head>
<body>
<?php if(!isset($_SESSION['user'])): ?>
<div class="login-container">
<h2>登录<?php echo $site_name;?></h2>
<?php if(isset($error)) echo "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i>$error</div>"; ?>
<form method="post">
<div class="form-group"><label>用户名</label><input type="text" name="username" required></div>
<div class="form-group"><label>密码</label><input type="password" name="password" required></div>
<button type="submit" name="login" class="btn"><i class="fas fa-sign-in-alt"></i> 登录</button>
</form>
</div>
<?php else: ?>
<div class="container">
<header>
<div class="logo"><i class="fas fa-cogs"></i><span><?php echo $site_name;?></span></div>
<div class="user-info">
<span><?php echo $_SESSION['user'];?> [<?php echo $_SESSION['role'];?>]</span>
<a href="?action=logout" class="logout-btn"><i class="fas fa-sign-out-alt"></i>退出</a>
</div>
</header>
<div class="tabs">
<a href="?tab=add" class="tablink <?php echo $active_tab=='add'?'active':'';?>"><i class="fas fa-plus-circle"></i>新增/修改工单</a>
<a href="?tab=view" class="tablink <?php echo $active_tab=='view'?'active':'';?>"><i class="fas fa-list"></i>工单查询</a>
<?php if($_SESSION['role']=='admin'){ ?>
<a href="?tab=users" class="tablink <?php echo $active_tab=='users'?'active':'';?>"><i class="fas fa-users-cog"></i>用户管理</a>
<a href="?tab=settings" class="tablink <?php echo $active_tab=='settings'?'active':'';?>"><i class="fas fa-sliders-h"></i>系统设置</a>
<?php } ?>
</div>

<!-- tab content -->
<div id="add" class="tabcontent" style="display:<?php echo $active_tab=='add'?'block':'none';?>;">
<h2><?php echo isset($_GET['id']) ? "修改工单" : "新增工单";?></h2>
<?php
$ticket = ['id'=>0,'order_no'=>'','logistics_no'=>'','category'=>'','description'=>'','collector'=>'','manager'=>'','handler'=>'','status'=>'未处理','platform'=>''];
if(isset($_GET['id']) && $_SESSION['role']=='admin'){
    $id=intval($_GET['id']);
    $ticket=$db->querySingle("SELECT * FROM tickets WHERE id=$id",true);
    if(!$ticket) { echo "<p>工单不存在</p>"; $ticket=['id'=>0]; }
}
?>
<form method="post">
<input type="hidden" name="id" value="<?php echo $ticket['id'];?>">
<div class="form-group"><label>订单号:</label><input type="text" name="order_no" value="<?php echo $ticket['order_no'];?>" required></div>
<div class="form-group"><label>物流号:</label><input type="text" name="logistics_no" value="<?php echo $ticket['logistics_no'];?>" required></div>
<div class="form-group"><label>来源平台:</label>
<select name="platform" required>
<option value="">请选择平台</option>
<?php foreach($platforms as $p){ $sel = ($ticket['platform']==$p['name'])?'selected':''; echo "<option value='{$p['name']}' $sel>{$p['name']}</option>"; } ?>
</select></div>
<div class="form-group"><label>分类原因:</label>
<select name="category" required>
<?php
$categories = ['客户下错单','厂家质量问题','快递损坏','其他'];
foreach($categories as $c){
    $sel = ($ticket['category']==$c)?'selected':'';
    echo "<option value='$c' $sel>$c</option>";
}
?>
</select></div>
<div class="form-group"><label>描述:</label>
<textarea name="description" required><?php echo $ticket['description'];?></textarea></div>

<div class="form-group"><label>采集人:</label>
 
 <input type="text" name="collector"  readonly=＂readonly＂ value="<?php echo isset($_GET['id']) ? $ticket['collector'] : $current_user_name;?>" required>
</div>
<div class="form-group"><label>经办人:</label>
<select name="manager" required>
<?php foreach($regular_users as $u){ $sel = ($ticket['manager']==$u['username'])?'selected':''; echo "<option value='{$u['username']}' $sel>{$u['username']}</option>"; } ?>
</select></div>
<?php  if ($_SESSION['role']=='admin')  {?>
<div class="form-group"><label>处理人:</label>
<select name="handler" <?php echo ($_SESSION['role']!='admin' && $ticket['id']>0) ? 'disabled' : 'required'; ?>>
<?php 
foreach($admin_users as $u){ 
    $sel = ($ticket['handler']==$u['username'])?'selected':'';
    echo "<option value='{$u['username']}' $sel>{$u['username']}</option>"; 
} 
?>
</select>

<?php if($_SESSION['role']!='admin' && $ticket['id']>0): ?>
<input type="hidden" name="handler" value="<?php echo $ticket['handler']; ?>">

<p style="color:#777;font-size:14px;margin-top:5px;">只有管理员可以修改处理人</p>
<?php endif; ?>
</div>
<?php } ?>
<?php if($_SESSION['role']=='admin'){ ?>
<div class="form-group"><label>状态:</label>
<select name="status" required>
<?php
$statuses = ['未处理','处理中','已处理'];
foreach($statuses as $s){
    $sel = ($ticket['status']==$s)?'selected':'';
    echo "<option value='$s' $sel>$s</option>";
}
?>
</select></div>
<?php } else { ?>
<input type="hidden" name="status" value="<?php echo $ticket['status'];?>">
<?php } ?>

<?php if(isset($ticket['status']) && $ticket['status']=='已处理') { echo "<p style='color:red'>已处理工单不可修改</p>"; } else { ?>
<button type="submit" name="save_ticket" class="btn"><i class="fas fa-save"></i> <?php echo isset($_GET['id']) ? '保存修改' : '新增工单';?></button>
<?php } ?>

<?php if($ticket['id'] > 0): ?>
<div class="history-container">
    <h3>修改历史</h3>
    <?php
    // 获取工单修改历史
    $history_res = $db->query("SELECT * FROM ticket_history WHERE ticket_id = {$ticket['id']} ORDER BY changed_at DESC");
    $has_history = false;
    while($history = $history_res->fetchArray(SQLITE3_ASSOC)): 
        $has_history = true;
    ?>
        <div class="history-item">
            <div class="history-meta">
                修改人: <?php echo $history['changed_by']; ?> | 
                修改时间: <?php echo $history['changed_at']; ?>
            </div>
            <div class="history-content">
                <?php echo $history['change_description']; ?>
            </div>
        </div>
    <?php endwhile; ?>
    
    <?php if(!$has_history): ?>
        <p>暂无修改记录</p>
    <?php endif; ?>
    
    <!-- 显示工单创建和最后修改信息 -->
    <div class="history-item">
        <div class="history-meta">
            工单创建时间: <?php echo $ticket['created_at']; ?>
        </div>
    </div>
    <?php if(!empty($ticket['last_modified'])): ?>
    <div class="history-item">
        <div class="history-meta">
            最后修改时间: <?php echo $ticket['last_modified']; ?> | 
            最后修改人: <?php echo $ticket['modified_by']; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
</form>
</div>

<div id="view" class="tabcontent" style="display:<?php echo $active_tab=='view'?'block':'none';?>;">
<h2>工单查询</h2>

<!-- 导出CSV按钮 -->
<?php if($_SESSION['role']=='admin'): ?>
<div class="export-btn">
    <a href="?tab=view&export_csv=1<?php echo isset($_GET['status'])?'&status='.$_GET['status']:'';?><?php echo isset($_GET['start_date'])?'&start_date='.$_GET['start_date']:'';?><?php echo isset($_GET['end_date'])?'&end_date='.$_GET['end_date']:'';?><?php echo isset($_GET['search'])?'&search='.urlencode($_GET['search']):'';?>" class="btn btn-success">
        <i class="fas fa-file-export"></i> 导出CSV
    </a>
</div>
<?php endif; ?>

<!-- 筛选表单 -->
<form method="get" class="filter-form">
    <input type="hidden" name="tab" value="view">
    
    <!-- 搜索框 -->
    <div class="search-group">
        <label>搜索订单号/物流号:</label>
        <input type="text" name="search" placeholder="输入订单号或物流号" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
    </div>
    
    <div class="filter-group">
        <label>状态筛选:</label>
        <select name="status">
            <option value="">全部状态</option>
            <option value="未处理" <?php echo (isset($_GET['status']) && $_GET['status']=='未处理')?'selected':'';?>>未处理</option>
            <option value="处理中" <?php echo (isset($_GET['status']) && $_GET['status']=='处理中')?'selected':'';?>>处理中</option>
            <option value="已处理" <?php echo (isset($_GET['status']) && $_GET['status']=='已处理')?'selected':'';?>>已处理</option>
        </select>
    </div>
    <div class="filter-group">
        <label>开始日期:</label>
        <input type="date" name="start_date" value="<?php echo isset($_GET['start_date'])?$_GET['start_date']:'';?>">
    </div>
    <div class="filter-group">
        <label>结束日期:</label>
        <input type="date" name="end_date" value="<?php echo isset($_GET['end_date'])?$_GET['end_date']:'';?>">
    </div>
    <div class="filter-group">
        <label>&nbsp;</label>
        <button type="submit" name="filter" class="btn"><i class="fas fa-filter"></i> 筛选</button>
        <a href="?tab=view" class="btn btn-info"><i class="fas fa-sync"></i> 重置</a>
    </div>
</form>

<!-- 分页信息 -->
<div class="page-info">
    共 <?php echo $total_records; ?> 条记录，第 <?php echo $page; ?> 页/共 <?php echo $total_pages; ?> 页
    <?php if(isset($_GET['search']) && !empty($_GET['search'])): ?>
        <br><span style="color: #3498db;">搜索关键词: "<?php echo htmlspecialchars($_GET['search']); ?>"</span>
    <?php endif; ?>
</div>

<!-- 表格容器 -->
<div class="table-container">
    <table class="ticket-table">
        <tr>
            <th>ID</th>
            <th>订单号</th>
            <th>物流号</th>
            <th>平台</th>
            <th>分类</th>
            <th>状态</th>
            <th>采集人</th>
            <th>经办人</th>
            <th>处理人</th>
            <th>创建时间</th>
            <th>最后修改</th>
            <th>操作</th>
        </tr>
        <?php if(empty($tickets)): ?>
        <tr>
            <td colspan="12" style="text-align:center;padding:20px;color:#666;">
                <?php if(isset($_GET['search']) && !empty($_GET['search'])): ?>
                    没有找到包含 "<?php echo htmlspecialchars($_GET['search']); ?>" 的工单
                <?php else: ?>
                    暂无工单数据
                <?php endif; ?>
            </td>
        </tr>
        <?php else: ?>
        <?php foreach($tickets as $t): ?>
        <tr>
            <td><span class="tooltip tooltip-right"><?php echo $t['id'];?><span class="tooltiptext"><?php echo $t['description'];?></span></span> </td>
            <td class="long-text"><?php echo htmlspecialchars($t['order_no']);?></td>
            <td class="long-text"><?php echo htmlspecialchars($t['logistics_no']);?></td>
            <td><?php echo htmlspecialchars($t['platform']);?></td>
            <td><?php echo htmlspecialchars($t['category']);?></td>
            <td>
                <span style="display:inline-block;padding:4px 8px;border-radius:12px;font-size:12px;
                    background:<?php echo $t['status']=='已处理'?'#d4edda':($t['status']=='处理中'?'#fff3cd':'#f8d7da');?>;
                    color:<?php echo $t['status']=='已处理'?'#155724':($t['status']=='处理中'?'#856404':'#721c24');?>;">
                    <?php echo $t['status'];?>
                </span>
            </td>
            <td><?php echo htmlspecialchars($t['collector']);?></td>
            <td><?php echo htmlspecialchars($t['manager']);?></td>
            <td><?php echo htmlspecialchars($t['handler']);?></td>
            <td><?php echo date('Y-m-d H:i', strtotime($t['created_at']));?></td>
            <td>
                <?php if(!empty($t['last_modified'])): ?>
                <?php echo date('Y-m-d H:i', strtotime($t['last_modified'])); ?><br>
                <small style="color:#666;">by <?php echo $t['modified_by']; ?></small>
                <?php else: ?>
                无
                <?php endif; ?>
            </td>
            <td>
                <?php if($_SESSION['role']=='admin' && $t['status']!='已处理'){ ?>
                <a href="?tab=add&id=<?php echo $t['id'];?>" class="btn btn-success" style="margin-bottom:5px;"><i class="fas fa-edit"></i>修改</a>
                <a href="?del_ticket=<?php echo $t['id'];?>" class="btn btn-danger" onclick="return confirm('确定删除吗?')"><i class="fas fa-trash-alt"></i>删除</a>
                <?php } else if ($t['status'] =='已处理') { 
				
	//			$datetime1 =new DateTime($t['created_at']);
    //            $datetime2 =new DateTime($t['last_modified']);
    //            $interval = $datetime1->diff($datetime2);
    
    //            $processing_time = $interval->format('%H:%I:%S');
				// 创建 DateTime 对象
$datetime1 = strtotime($t['created_at']);
$datetime2 = strtotime($t['last_modified']);

// 计算时间差
//$interval = $datetime1->diff($datetime2);

// 输出用时
echo "总用时: " . gmdate('H:i:s', $datetime2-$datetime1);
    
   //echo $processing_time;
    //echo "over";
				                 }  ?>

            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </table>
</div>

<!-- 分页导航 -->
<?php if($total_pages > 1): ?>
<div class="pagination">
    <!-- 上一页 -->
    <?php if($page > 1): ?>
        <a href="?tab=view&page=1<?php echo isset($_GET['status'])?'&status='.$_GET['status']:'';?><?php echo isset($_GET['start_date'])?'&start_date='.$_GET['start_date']:'';?><?php echo isset($_GET['end_date'])?'&end_date='.$_GET['end_date']:'';?><?php echo $search_query;?>"><i class="fas fa-angle-double-left"></i></a>
        <a href="?tab=view&page=<?php echo $page-1;?><?php echo isset($_GET['status'])?'&status='.$_GET['status']:'';?><?php echo isset($_GET['start_date'])?'&start_date='.$_GET['start_date']:'';?><?php echo isset($_GET['end_date'])?'&end_date='.$_GET['end_date']:'';?><?php echo $search_query;?>"><i class="fas fa-angle-left"></i></a>
    <?php else: ?>
        <span class="disabled"><i class="fas fa-angle-double-left"></i></span>
        <span class="disabled"><i class="fas fa-angle-left"></i></span>
    <?php endif; ?>

    <!-- 页码 -->
    <?php 
    $start_page = max(1, $page - 2);
    $end_page = min($total_pages, $start_page + 4);
    if($end_page - $start_page < 4) {
        $start_page = max(1, $end_page - 4);
    }
    
    for($i = $start_page; $i <= $end_page; $i++): 
    ?>
        <?php if($i == $page): ?>
            <span class="current"><?php echo $i; ?></span>
        <?php else: ?>
            <a href="?tab=view&page=<?php echo $i;?><?php echo isset($_GET['status'])?'&status='.$_GET['status']:'';?><?php echo isset($_GET['start_date'])?'&start_date='.$_GET['start_date']:'';?><?php echo isset($_GET['end_date'])?'&end_date='.$_GET['end_date']:'';?><?php echo $search_query;?>"><?php echo $i; ?></a>
    <?php endif; ?>
    <?php endfor; ?>

    <!-- 下一页 -->
    <?php if($page < $total_pages): ?>
        <a href="?tab=view&page=<?php echo $page+1;?><?php echo isset($_GET['status'])?'&status='.$_GET['status']:'';?><?php echo isset($_GET['start_date'])?'&start_date='.$_GET['start_date']:'';?><?php echo isset($_GET['end_date'])?'&end_date='.$_GET['end_date']:'';?><?php echo $search_query;?>"><i class="fas fa-angle-right"></i></a>
        <a href="?tab=view&page=<?php echo $total_pages;?><?php echo isset($_GET['status'])?'&status='.$_GET['status']:'';?><?php echo isset($_GET['start_date'])?'&start_date='.$_GET['start_date']:'';?><?php echo isset($_GET['end_date'])?'&end_date='.$_GET['end_date']:'';?><?php echo $search_query;?>"><i class="fas fa-angle-double-right"></i></a>
    <?php else: ?>
        <span class="disabled"><i class="fas fa-angle-right"></i></span>
        <span class="disabled"><i class="fas fa-angle-double-right"></i></span>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>

<?php if($_SESSION['role']=='admin'){ ?>
<div id="users" class="tabcontent" style="display:<?php echo $active_tab=='users'?'block':'none';?>;">
<h2>用户管理</h2>
<form method="post">
<div class="form-group"><label>用户名:</label><input type="text" name="username" required></div>
<div class="form-group"><label>密码:</label><input type="password" name="password" required></div>
<div class="form-group"><label>角色:</label>
<select name="role"><option value="user">用户</option><option value="admin">管理员</option></select></div>
<button type="submit" name="add_user" class="btn"><i class="fas fa-user-plus"></i> 添加用户</button>
</form>

<h3>现有用户</h3>
<table>
<tr><th>ID</th><th>用户名</th><th>角色</th><th>创建时间</th><th>操作</th></tr>
<?php foreach($all_users as $u){ ?>
<tr>
<td><?php echo $u['id'];?></td>
<td><?php echo $u['username'];?></td>
<td><?php echo $u['role'];?></td>
<td><?php echo $u['created_at'];?></td>
<td>
    <?php if($u['username']!=$_SESSION['user']){ ?>
    <a href="?del_user=<?php echo $u['id'];?>" class="btn btn-danger" onclick="return confirm('确定删除吗?')"><i class="fas fa-trash-alt"></i></a>
    <?php } ?>
    <button onclick="document.getElementById('change-password-<?php echo $u['id'];?>').style.display='block'" class="btn"><i class="fas fa-key"></i> 修改密码</button>
</td>
</tr>
<tr id="change-password-<?php echo $u['id'];?>" style="display:none;">
<td colspan="5">
    <div class="change-password-form">
        <h4>修改 <?php echo $u['username']; ?> 的密码</h4>
        <form method="post">
            <input type="hidden" name="user_id" value="<?php echo $u['id'];?>">
            <div class="form-group">
                <label>新密码:</label>
                <input type="password" name="new_password" required>
            </div>
            <button type="submit" name="change_password" class="btn"><i class="fas fa-save"></i> 保存密码</button>
            <button type="button" onclick="document.getElementById('change-password-<?php echo $u['id'];?>').style.display='none'" class="btn btn-danger">取消</button>
        </form>
    </div>
</td>
</tr>
<?php } ?>
</table>

<!-- 当前用户修改密码 -->
<div class="change-password-form">
    <h3>修改我的密码</h3>
    <form method="post">
        <input type="hidden" name="user_id" value="<?php echo $current_user_id;?>">
        <div class="form-group">
            <label>新密码:</label>
            <input type="password" name="new_password" required>
        </div>
        <button type="submit" name="change_password" class="btn"><i class="fas fa-key"></i> 修改密码</button>
    </form>
</div>
</div>

<div id="settings" class="tabcontent" style="display:<?php echo $active_tab=='settings'?'block':'none';?>;">
<h2>系统设置</h2>

<?php if(isset($_GET['backup_success'])): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i> 数据库备份成功！
</div>
<?php endif; ?>

<?php if(isset($_GET['restore_success'])): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i> 数据库恢复成功！
</div>
<?php endif; ?>

<div class="settings-section">
    <h3>基本设置</h3>
    <form method="post">
        <div class="form-group"><label>系统名称:</label><input type="text" name="site_name" value="<?php echo $site_name;?>" required></div>
        <div class="form-group"><label>主题颜色:</label><input type="color" name="theme_color" value="<?php echo $theme_color;?>"></div>
        <button type="submit" name="save_settings" class="btn"><i class="fas fa-save"></i> 保存设置</button>
    </form>
</div>

<div class="settings-section">
    <h3>工单来源平台管理</h3>
    <form method="post">
        <div class="form-group">
            <label>添加新平台:</label>
            <input type="text" name="platform_name" placeholder="输入平台名称" required>
        </div>
        <button type="submit" name="add_platform" class="btn"><i class="fas fa-plus"></i> 添加平台</button>
    </form>
    
    <h4>现有平台:</h4>
    <div class="platform-list">
        <?php foreach($platforms as $p): ?>
        <div class="platform-item">
            <?php echo $p['name']; ?>
            <a href="?del_platform=<?php echo $p['id'];?>" class="delete" onclick="return confirm('确定删除这个平台吗?')"><i class="fas fa-times"></i></a>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="settings-section">
    <h3>数据库备份与恢复</h3>
    
    <!-- 备份数据库 -->
    <div style="margin-bottom: 20px;">
        <h4>备份数据库</h4>
        <form method="post">
            <button type="submit" name="backup_db" class="btn btn-warning" onclick="return confirm('确定要备份数据库吗？')">
                <i class="fas fa-database"></i> 立即备份数据库
            </button>
        </form>
    </div>
    
    <!-- 恢复数据库 -->
    <div style="margin-bottom: 20px;">
        <h4>恢复数据库</h4>
        <form method="post" enctype="multipart/form-data">
            <div class="file-upload">
                <i class="fas fa-cloud-upload-alt" style="font-size: 24px; margin-bottom: 10px;"></i>
                <p>选择数据库备份文件 (.db)</p>
                <div class="form-group">
                    <input type="file" name="backup_file" accept=".db" required>
                </div>
            </div>
            <button type="submit" name="restore_db" class="btn btn-danger" onclick="return confirm('警告：恢复数据库将覆盖当前所有数据！确定要继续吗？')">
                <i class="fas fa-undo"></i> 恢复数据库
            </button>
        </form>
    </div>
    
    <!-- 备份列表 -->
    <div>
        <h4>备份列表</h4>
        <?php if(empty($backups)): ?>
            <p>暂无备份文件</p>
        <?php else: ?>
            <?php foreach($backups as $backup): ?>
                <div class="backup-item">
                    <div class="backup-info">
                        <strong><?php echo $backup['filename']; ?></strong>
                        <br>
                        <small>大小: <?php echo round($backup['size'] / 1024, 2); ?> KB</small>
                        <br>
                        <small>时间: <?php echo $backup['created_at']; ?></small>
                    </div>
                    <div class="backup-actions">
                        <a href="backups/<?php echo $backup['filename']; ?>" download class="btn btn-info">
                            <i class="fas fa-download"></i> 下载
                        </a>
                        <a href="?delete_backup=<?php echo $backup['id']; ?>" class="btn btn-danger" onclick="return confirm('确定要删除这个备份吗？')">
                            <i class="fas fa-trash"></i> 删除
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
</div>
</div>
<?php } ?>

</div>
<script>
// tab 切换
document.querySelectorAll('.tablink').forEach(el=>{
    el.addEventListener('click',function(e){
        e.preventDefault();
        document.querySelectorAll('.tabcontent').forEach(tc=>tc.style.display='none');
        let href = this.getAttribute('href').split('tab=')[1];
        document.getElementById(href).style.display='block';
        document.querySelectorAll('.tablink').forEach(tl=>tl.classList.remove('active'));
        this.classList.add('active');
    });
});

// 筛选表单提交
document.querySelector('.filter-form').addEventListener('submit', function(e) {
    // 保留现有的分页参数
    const urlParams = new URLSearchParams(window.location.search);
    const page = urlParams.get('page');
    if (page) {
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'page';
        hiddenInput.value = page;
        this.appendChild(hiddenInput);
    }
});

// 文件上传样式交互
const fileInputs = document.querySelectorAll('input[type="file"]');
fileInputs.forEach(input => {
    input.addEventListener('change', function() {
        const fileName = this.files[0]?.name;
        if (fileName) {
            this.parentElement.querySelector('p').textContent = `已选择: ${fileName}`;
        }
    });
});

// 搜索框回车键提交
document.querySelector('input[name="search"]').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        this.form.submit();
    }
});
</script>
<?php endif; ?>
</body>
</html>
