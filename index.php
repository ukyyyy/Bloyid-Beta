<?php
session_start();

// Database connection
$host = "sql304.infinityfree.com";
$port = "3306";
$dbname = "if0_37536001_bloyid";
$user = "if0_37536001";
$pass = "298612Jasonn";

$conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function initializeDatabase(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS chat (
        id INT AUTO_INCREMENT PRIMARY KEY,
        server_id INT NOT NULL,
        username VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS servers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        owner_username VARCHAR(100) NOT NULL,
        invite_token VARCHAR(64) UNIQUE NOT NULL,
        icon_path VARCHAR(255) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS server_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        server_id INT NOT NULL,
        username VARCHAR(100) NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_member (server_id, username)
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS badges (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) UNIQUE NOT NULL,
        color VARCHAR(7) DEFAULT '#7289da',
        description VARCHAR(255) DEFAULT '',
        is_employee TINYINT(1) DEFAULT 0
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS user_badges (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        badge_id INT NOT NULL,
        awarded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_badge (username, badge_id)
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS direct_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender VARCHAR(100) NOT NULL,
        receiver VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS friendships (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requester VARCHAR(100) NOT NULL,
        receiver VARCHAR(100) NOT NULL,
        status ENUM('pending','accepted','declined') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_friendship (requester, receiver)
    )");

    $defaultBadges = [
        ['name' => 'Member', 'color' => '#7289da', 'description' => 'Standard community member', 'is_employee' => 0],
        ['name' => 'Founder', 'color' => '#f39c12', 'description' => 'Creator of a server', 'is_employee' => 0],
        ['name' => 'Employee', 'color' => '#2ecc71', 'description' => 'Bloyid staff access', 'is_employee' => 1]
    ];

    $insertBadge = $conn->prepare("INSERT IGNORE INTO badges (name, color, description, is_employee) VALUES (:name, :color, :description, :is_employee)");
    foreach ($defaultBadges as $badge) {
        $insertBadge->execute($badge);
    }
}

function getBadgeId(PDO $conn, string $name): ?int
{
    $stmt = $conn->prepare("SELECT id FROM badges WHERE name = :name");
    $stmt->execute(['name' => $name]);
    $badge = $stmt->fetch(PDO::FETCH_ASSOC);
    return $badge ? (int)$badge['id'] : null;
}

function assignBadge(PDO $conn, string $username, string $badgeName): void
{
    $badgeId = getBadgeId($conn, $badgeName);
    if ($badgeId === null) {
        return;
    }

    $stmt = $conn->prepare("INSERT IGNORE INTO user_badges (username, badge_id) VALUES (:username, :badge_id)");
    $stmt->execute(['username' => $username, 'badge_id' => $badgeId]);
}

function userHasEmployeeAccess(PDO $conn, string $username): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM user_badges 
        JOIN badges ON badges.id = user_badges.badge_id
        WHERE user_badges.username = :username AND badges.is_employee = 1");
    $stmt->execute(['username' => $username]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result && (int)$result['total'] > 0;
}

initializeDatabase($conn);

// Registration
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (:username, :password)");
    try {
        $stmt->execute(['username' => $username, 'password' => $password]);
        assignBadge($conn, $username, 'Member');
        echo "Registration successful!";
    } catch (PDOException $e) {
        echo "Username already exists.";
    }
    exit;
}

// Login
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['username'] = $username;
        echo "Login successful!";
    } else {
        echo "Incorrect username or password!";
    }
    exit;
}

// Logout
if (isset($_POST['logout'])) {
    session_destroy();
    echo "Logout successful!";
    exit;
}

// Current user context
if (isset($_GET['me'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['username'])) {
        echo json_encode(['logged_in' => false]);
        exit;
    }

    $username = $_SESSION['username'];
    $stmt = $conn->prepare("SELECT badges.name, badges.color, badges.description, badges.is_employee FROM user_badges
        JOIN badges ON badges.id = user_badges.badge_id
        WHERE user_badges.username = :username ORDER BY badges.is_employee DESC, badges.name ASC");
    $stmt->execute(['username' => $username]);
    $badges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'logged_in' => true,
        'username' => $username,
        'badges' => $badges,
        'is_employee' => userHasEmployeeAccess($conn, $username)
    ]);
    exit;
}

// Send chat message
if (isset($_POST['send_message'])) {
    if (!isset($_SESSION['username'])) {
        echo "Please log in!";
        exit;
    }

    $username = $_SESSION['username'];
    $server_id = (int)$_POST['server_id'];
    $message = trim($_POST['content']);

    if ($message === '') {
        echo "Message cannot be empty.";
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO chat (username, message, server_id) VALUES (:username, :message, :server_id)");
    $stmt->execute(['username' => $username, 'message' => $message, 'server_id' => $server_id]);
    echo "Message sent!";
    exit;
}

// Fetch messages
if (isset($_GET['fetch'])) {
    header('Content-Type: application/json');
    $server_id = (int)$_GET['server_id'];
    $stmt = $conn->prepare("SELECT username, message, created_at FROM chat WHERE server_id = :server_id ORDER BY id ASC LIMIT 200");
    $stmt->execute(['server_id' => $server_id]);
    $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($chats);
    exit;
}

// Fetch servers for the logged-in user
if (isset($_GET['servers'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['username'])) {
        echo json_encode([]);
        exit;
    }
    $username = $_SESSION['username'];

    $stmt = $conn->prepare("
        SELECT servers.*, (
            SELECT COUNT(*) FROM server_members WHERE server_members.server_id = servers.id
        ) as member_count
        FROM servers
        JOIN server_members ON servers.id = server_members.server_id
        WHERE server_members.username = :username
        ORDER BY servers.name ASC
    ");
    $stmt->execute(['username' => $username]);
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($servers);
    exit;
}

// Fetch server members
if (isset($_GET['members'])) {
    header('Content-Type: application/json');
    $server_id = (int)$_GET['server_id'];
    $stmt = $conn->prepare("SELECT username, joined_at FROM server_members WHERE server_id = :server_id ORDER BY username ASC");
    $stmt->execute(['server_id' => $server_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($members);
    exit;
}

// Create server
if (isset($_POST['create_server'])) {
    if (!isset($_SESSION['username'])) {
        echo "Please log in!";
        exit;
    }

    $server_name = trim($_POST['server_name']);
    $username = $_SESSION['username'];
    $icon_path = trim($_POST['icon_path']);

    if ($server_name === '') {
        echo "Server name cannot be empty.";
        exit;
    }

    $invite_token = bin2hex(random_bytes(6));

    $stmt = $conn->prepare("INSERT INTO servers (name, owner_username, invite_token, icon_path) VALUES (:name, :owner_username, :invite_token, :icon_path)");
    $stmt->execute(['name' => $server_name, 'owner_username' => $username, 'invite_token' => $invite_token, 'icon_path' => $icon_path]);

    $server_id = (int)$conn->lastInsertId();
    $stmt = $conn->prepare("INSERT IGNORE INTO server_members (server_id, username) VALUES (:server_id, :username)");
    $stmt->execute(['server_id' => $server_id, 'username' => $username]);

    assignBadge($conn, $username, 'Founder');

    echo "Server successfully created! Invite token: $invite_token";
    exit;
}

// Join server using invite token
if (isset($_POST['join_server'])) {
    if (!isset($_SESSION['username'])) {
        echo "Please log in!";
        exit;
    }
    $username = $_SESSION['username'];
    $invite_token = trim($_POST['invite_token']);

    $stmt = $conn->prepare("SELECT id FROM servers WHERE invite_token = :invite_token");
    $stmt->execute(['invite_token' => $invite_token]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($server) {
        $server_id = (int)$server['id'];
        $stmt = $conn->prepare("INSERT IGNORE INTO server_members (server_id, username) VALUES (:server_id, :username)");
        $stmt->execute(['server_id' => $server_id, 'username' => $username]);
        echo "Successfully joined the server!";
    } else {
        echo "Invalid invite token!";
    }
    exit;
}

// Explorer - list servers
if (isset($_GET['explore_servers'])) {
    header('Content-Type: application/json');
    $stmt = $conn->query("SELECT servers.id, servers.name, servers.icon_path, servers.invite_token,
        servers.owner_username,
        (SELECT COUNT(*) FROM server_members WHERE server_members.server_id = servers.id) as member_count
        FROM servers ORDER BY member_count DESC, servers.created_at DESC LIMIT 50");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Badges for current user
if (isset($_GET['badges'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['username'])) {
        echo json_encode([]);
        exit;
    }
    $username = $_SESSION['username'];
    $stmt = $conn->prepare("SELECT badges.name, badges.color, badges.description FROM user_badges
        JOIN badges ON badges.id = user_badges.badge_id
        WHERE user_badges.username = :username ORDER BY badges.name ASC");
    $stmt->execute(['username' => $username]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Direct messages list
if (isset($_GET['conversations'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['username'])) {
        echo json_encode([]);
        exit;
    }
    $username = $_SESSION['username'];
    $stmt = $conn->prepare("SELECT other_user FROM (
            SELECT CASE WHEN sender = :username THEN receiver ELSE sender END AS other_user,
            MAX(created_at) AS last_message
            FROM direct_messages
            WHERE sender = :username OR receiver = :username
            GROUP BY other_user
        ) conversations
        ORDER BY last_message DESC");
    $stmt->execute(['username' => $username]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Fetch direct messages
if (isset($_GET['direct_messages'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['username'])) {
        echo json_encode([]);
        exit;
    }
    $username = $_SESSION['username'];
    $other = $_GET['user'];
    $stmt = $conn->prepare("SELECT sender, receiver, message, created_at FROM direct_messages
        WHERE (sender = :username AND receiver = :other) OR (sender = :other AND receiver = :username)
        ORDER BY created_at ASC LIMIT 200");
    $stmt->execute(['username' => $username, 'other' => $other]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Send direct message
if (isset($_POST['send_dm'])) {
    if (!isset($_SESSION['username'])) {
        echo "Please log in!";
        exit;
    }
    $sender = $_SESSION['username'];
    $receiver = trim($_POST['receiver']);
    $message = trim($_POST['dm_message']);

    if ($message === '') {
        echo "Message cannot be empty.";
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO direct_messages (sender, receiver, message) VALUES (:sender, :receiver, :message)");
    $stmt->execute(['sender' => $sender, 'receiver' => $receiver, 'message' => $message]);
    echo "DM sent!";
    exit;
}

// Friend list
if (isset($_GET['friends'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['username'])) {
        echo json_encode(['friends' => [], 'requests' => []]);
        exit;
    }
    $username = $_SESSION['username'];

    $stmt = $conn->prepare("SELECT CASE WHEN requester = :username THEN receiver ELSE requester END AS friend,
        created_at FROM friendships WHERE (requester = :username OR receiver = :username) AND status = 'accepted'
        ORDER BY created_at DESC");
    $stmt->execute(['username' => $username]);
    $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT id, requester, created_at FROM friendships WHERE receiver = :username AND status = 'pending'");
    $stmt->execute(['username' => $username]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['friends' => $friends, 'requests' => $requests]);
    exit;
}

// Add friend
if (isset($_POST['add_friend'])) {
    if (!isset($_SESSION['username'])) {
        echo "Please log in!";
        exit;
    }
    $requester = $_SESSION['username'];
    $receiver = trim($_POST['friend_username']);

    if ($receiver === '' || $receiver === $requester) {
        echo "Invalid username.";
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE username = :username");
    $stmt->execute(['username' => $receiver]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "User not found.";
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO friendships (requester, receiver) VALUES (:requester, :receiver)");
    try {
        $stmt->execute(['requester' => $requester, 'receiver' => $receiver]);
        echo "Friend request sent!";
    } catch (PDOException $e) {
        echo "Request already exists.";
    }
    exit;
}

// Respond to friend request
if (isset($_POST['respond_friend'])) {
    if (!isset($_SESSION['username'])) {
        echo "Please log in!";
        exit;
    }
    $username = $_SESSION['username'];
    $request_id = (int)$_POST['request_id'];
    $action = $_POST['action'];

    $stmt = $conn->prepare("SELECT receiver FROM friendships WHERE id = :id");
    $stmt->execute(['id' => $request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request || $request['receiver'] !== $username) {
        echo "Invalid request.";
        exit;
    }

    $status = $action === 'accept' ? 'accepted' : 'declined';
    $stmt = $conn->prepare("UPDATE friendships SET status = :status WHERE id = :id");
    $stmt->execute(['status' => $status, 'id' => $request_id]);
    echo "Friend request updated.";
    exit;
}

// Admin dashboard stats
if (isset($_GET['admin_stats'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['username']) || !userHasEmployeeAccess($conn, $_SESSION['username'])) {
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $stats = [];
    $stats['user_count'] = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['server_count'] = (int)$conn->query("SELECT COUNT(*) FROM servers")->fetchColumn();
    $stats['message_count'] = (int)$conn->query("SELECT COUNT(*) FROM chat")->fetchColumn();
    $stats['dm_count'] = (int)$conn->query("SELECT COUNT(*) FROM direct_messages")->fetchColumn();

    $recentUsers = $conn->query("SELECT username, created_at FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $stats['recent_users'] = $recentUsers;

    echo json_encode($stats);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bloyid Nexus</title>
    <style>
        :root {
            --bg: #12141f;
            --bg-alt: #181b29;
            --bg-panel: #1e2133;
            --bg-panel-light: #242943;
            --accent: #6c5ce7;
            --accent-light: #8f7bff;
            --danger: #ff7675;
            --success: #00cec9;
            --text: #f5f7ff;
            --muted: #a2a8c9;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', Arial, sans-serif;
            background: radial-gradient(circle at top left, rgba(108, 92, 231, 0.15), transparent 45%),
                        radial-gradient(circle at bottom right, rgba(0, 206, 201, 0.12), transparent 35%),
                        var(--bg);
            color: var(--text);
            margin: 0;
            height: 100vh;
            display: flex;
        }

        .app-shell {
            display: grid;
            grid-template-columns: 90px 1fr 280px;
            width: 100%;
            height: 100%;
        }

        .server-nav {
            background: var(--bg-alt);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            padding: 18px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }

        .server-nav .logo {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 20px;
            letter-spacing: 1px;
            box-shadow: 0 12px 25px rgba(108, 92, 231, 0.25);
        }

        .nav-button, .server-item {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            background: var(--bg-panel);
            border: none;
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .nav-button span,
        .server-item span {
            font-size: 22px;
        }

        .nav-button.active,
        .server-item.active,
        .nav-button:hover,
        .server-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 25px rgba(108, 92, 231, 0.25);
        }

        .server-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .server-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
            width: 100%;
            overflow-y: auto;
            padding-right: 4px;
        }

        .server-item::after,
        .nav-button::after {
            content: attr(data-tooltip);
            position: absolute;
            left: 65px;
            background: rgba(0, 0, 0, 0.75);
            color: var(--text);
            padding: 6px 12px;
            border-radius: 8px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
            white-space: nowrap;
            font-size: 13px;
        }

        .server-item:hover::after,
        .nav-button:hover::after {
            opacity: 1;
        }

        .main-content {
            background: rgba(24, 27, 41, 0.86);
            backdrop-filter: blur(22px);
            display: flex;
            flex-direction: column;
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        .main-header {
            padding: 20px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .main-header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .badge-strip {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.12);
        }

        .logout-button {
            padding: 8px 14px;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, var(--danger), #d63031);
            color: var(--text);
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .logout-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 20px rgba(255, 118, 117, 0.3);
        }

        .content-area {
            flex: 1;
            display: grid;
            grid-template-rows: auto 1fr;
        }

        .toolbar {
            padding: 16px 28px;
            display: flex;
            gap: 12px;
        }

        .pill-button {
            padding: 10px 18px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text);
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .pill-button:hover {
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .views {
            padding: 0 28px 28px;
            overflow-y: auto;
        }

        .panel {
            display: none;
            background: rgba(30, 33, 51, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 24px;
            min-height: calc(100% - 24px);
            box-shadow: 0 20px 45px rgba(15, 20, 40, 0.45);
        }

        .panel.active {
            display: block;
        }

        #chat-box {
            height: 420px;
            overflow-y: auto;
            padding-right: 12px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .message {
            background: var(--bg-panel-light);
            border-radius: 16px;
            padding: 14px 18px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .message strong {
            font-size: 14px;
            letter-spacing: 0.3px;
        }

        .timestamp {
            font-size: 11px;
            color: var(--muted);
        }

        #chat-form {
            display: flex;
            gap: 12px;
            margin-top: 18px;
        }

        #chat-form input {
            flex: 1;
            padding: 16px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.07);
            background: rgba(0, 0, 0, 0.25);
            color: var(--text);
        }

        #chat-form button {
            padding: 0 26px;
            border-radius: 16px;
            border: none;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: var(--text);
            font-weight: 600;
            cursor: pointer;
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }

        #chat-form button:hover {
            box-shadow: 0 15px 30px rgba(108, 92, 231, 0.35);
            transform: translateY(-2px);
        }

        .member-panel {
            background: rgba(30, 33, 51, 0.9);
            backdrop-filter: blur(22px);
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .member-panel h3 {
            margin: 0;
            font-weight: 600;
        }

        .member-list {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .member {
            background: rgba(255, 255, 255, 0.04);
            padding: 12px;
            border-radius: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }

        .dm-list, .friend-list, .request-list, .explorer-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: 260px;
            overflow-y: auto;
            margin-bottom: 18px;
        }

        .list-card {
            background: rgba(255, 255, 255, 0.04);
            padding: 14px;
            border-radius: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .list-card h4 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
        }

        .list-card span {
            font-size: 13px;
            color: var(--muted);
        }

        .ghost-button {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: var(--text);
            padding: 8px 16px;
            border-radius: 999px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .ghost-button:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .dm-thread {
            max-height: 360px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .section-title {
            margin: 0 0 12px;
            font-size: 18px;
            font-weight: 600;
        }

        .admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            padding: 18px;
            border-radius: 18px;
        }

        .stat-card h4 {
            margin: 0 0 6px;
            font-size: 15px;
            font-weight: 500;
        }

        .stat-card strong {
            font-size: 24px;
        }

        .recent-users {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .recent-users li {
            list-style: none;
            background: rgba(255, 255, 255, 0.04);
            padding: 12px 14px;
            border-radius: 14px;
            display: flex;
            justify-content: space-between;
        }

        /* Modal styles */
        #login-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(12, 15, 30, 0.85);
            backdrop-filter: blur(12px);
            align-items: center;
            justify-content: center;
            z-index: 50;
        }

        #modal-content {
            background: linear-gradient(160deg, rgba(36, 41, 67, 0.95), rgba(18, 20, 31, 0.95));
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 32px;
            width: 340px;
            box-shadow: 0 25px 60px rgba(15, 20, 40, 0.55);
        }

        #modal-content h2 {
            margin-top: 0;
            font-size: 24px;
            font-weight: 600;
        }

        #modal-content form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        #modal-content input {
            padding: 14px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.25);
            color: var(--text);
        }

        #modal-content button {
            padding: 12px;
            border-radius: 14px;
            border: none;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: var(--text);
            font-weight: 600;
            cursor: pointer;
        }

        #modal-content p {
            font-size: 13px;
            color: var(--muted);
            text-align: center;
        }

        #modal-content span.switch {
            color: var(--accent-light);
            cursor: pointer;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }

        .empty-state strong {
            display: block;
            margin-bottom: 8px;
            font-size: 16px;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
<div class="app-shell">
    <aside class="server-nav">
        <div class="logo">BN</div>
        <button class="nav-button" id="friends-button" data-tooltip="Freunde"><span>🤝</span></button>
        <button class="nav-button" id="dms-button" data-tooltip="Direktnachrichten"><span>💬</span></button>
        <div class="server-list" id="server-list"></div>
        <button class="nav-button" id="show-create-server" data-tooltip="Server erstellen"><span>➕</span></button>
        <button class="nav-button" id="open-explorer" data-tooltip="Explorer"><span>🧭</span></button>
        <button class="nav-button" id="open-join" data-tooltip="Server beitreten"><span>🔗</span></button>
    </aside>

    <main class="main-content">
        <div class="main-header">
            <h2 id="view-title">Willkommen bei Bloyid Nexus</h2>
            <div class="user-info">
                <div class="badge-strip" id="badge-strip"></div>
                <span id="username-display"></span>
                <button class="logout-button" id="logout-button">Abmelden</button>
            </div>
        </div>
        <div class="content-area">
            <div class="toolbar">
                <button class="pill-button" id="refresh-content">Aktualisieren</button>
                <button class="pill-button" id="open-admin" style="display:none;">Admin Dashboard</button>
            </div>
            <div class="views">
                <section class="panel active" id="chat-panel">
                    <h3 class="section-title" id="chat-title">Server auswählen, um zu chatten</h3>
                    <div id="chat-box" class="empty-state">
                        <strong>Kein Server ausgewählt</strong>
                        Wähle links einen Server aus oder erstelle einen neuen.
                    </div>
                    <form id="chat-form" style="display:none;">
                        <input type="text" id="message" placeholder="Nachricht eingeben..." required>
                        <input type="hidden" id="current-server-id">
                        <button type="submit">Senden</button>
                    </form>
                </section>

                <section class="panel" id="dm-panel">
                    <h3 class="section-title">Direktnachrichten</h3>
                    <div class="dm-list" id="dm-list"></div>
                    <div class="dm-thread" id="dm-thread"></div>
                    <form id="dm-form" style="display:none; margin-top:16px; gap:12px;">
                        <input type="text" id="dm-message" placeholder="Nachricht an Freund" required>
                        <input type="hidden" id="current-dm-user">
                        <button type="submit" class="pill-button" style="margin:0;">DM senden</button>
                    </form>
                </section>

                <section class="panel" id="friends-panel">
                    <h3 class="section-title">Freunde verwalten</h3>
                    <form id="add-friend-form" style="display:flex; gap:12px; margin-bottom:18px;">
                        <input type="text" id="friend-username" placeholder="Benutzername" required>
                        <button type="submit" class="pill-button" style="margin:0;">Freund hinzufügen</button>
                    </form>
                    <div>
                        <h4>Freunde</h4>
                        <div class="friend-list" id="friend-list"></div>
                    </div>
                    <div>
                        <h4>Anfragen</h4>
                        <div class="request-list" id="request-list"></div>
                    </div>
                </section>

                <section class="panel" id="explorer-panel">
                    <h3 class="section-title">Server Explorer</h3>
                    <div class="explorer-list" id="explorer-list"></div>
                </section>

                <section class="panel" id="admin-panel">
                    <h3 class="section-title">Admin Dashboard</h3>
                    <div class="admin-grid">
                        <div class="stat-card">
                            <h4>Nutzer insgesamt</h4>
                            <strong id="stat-users">0</strong>
                        </div>
                        <div class="stat-card">
                            <h4>Server insgesamt</h4>
                            <strong id="stat-servers">0</strong>
                        </div>
                        <div class="stat-card">
                            <h4>Server Nachrichten</h4>
                            <strong id="stat-messages">0</strong>
                        </div>
                        <div class="stat-card">
                            <h4>Direktnachrichten</h4>
                            <strong id="stat-dms">0</strong>
                        </div>
                    </div>
                    <h4>Neue Mitglieder</h4>
                    <ul class="recent-users" id="recent-users"></ul>
                </section>
            </div>
        </div>
    </main>

    <aside class="member-panel">
        <h3>Mitglieder</h3>
        <div class="member-list" id="member-list-content"></div>
    </aside>
</div>

<!-- Login Modal -->
<div id="login-modal">
    <div id="modal-content">
        <span id="close-modal" style="cursor:pointer; float:right; font-size:20px;">&times;</span>
        <h2 id="modal-title">Anmelden</h2>
        <form id="login-form">
            <input type="text" id="login-username" placeholder="Benutzername" required>
            <input type="password" id="login-password" placeholder="Passwort" required>
            <button type="submit">Login</button>
            <p id="login-message"></p>
            <p>Noch kein Konto? <span class="switch" id="open-register">Jetzt registrieren</span></p>
        </form>
        <form id="register-form" style="display:none;">
            <input type="text" id="register-username" placeholder="Benutzername" required>
            <input type="password" id="register-password" placeholder="Passwort" required>
            <button type="submit">Registrieren</button>
            <p id="register-message"></p>
            <p>Schon registriert? <span class="switch" id="open-login">Zum Login</span></p>
        </form>
    </div>
</div>

<!-- Join Server Modal -->
<div id="join-server-modal" style="display:none; position:fixed; inset:0; background:rgba(12, 15, 30, 0.85); backdrop-filter:blur(12px); align-items:center; justify-content:center; z-index:60;">
    <div id="join-modal-content" style="background:rgba(30, 33, 51, 0.95); padding:28px; border-radius:20px; width:320px; border:1px solid rgba(255,255,255,0.08);">
        <span id="close-join-modal" style="cursor:pointer; float:right; font-size:20px;">&times;</span>
        <h2>Server beitreten</h2>
        <form id="join-server-form" style="display:flex; flex-direction:column; gap:12px;">
            <input type="text" id="invite-code" placeholder="Einladungscode" required style="padding:12px; border-radius:12px; border:1px solid rgba(255,255,255,0.1); background:rgba(0,0,0,0.25); color:var(--text);">
            <button type="submit" class="pill-button" style="width:100%; text-align:center;">Beitreten</button>
            <p id="join-message" style="text-align:center; color:var(--muted);"></p>
        </form>
    </div>
</div>

<script>
const fetchInterval = 4000;
let currentServerId = null;
let currentDMUser = null;
let messageInterval = null;
let dmInterval = null;
let isEmployee = false;

$(document).ready(function() {
    function switchPanel(panelId, title) {
        $('.panel').removeClass('active');
        $(panelId).addClass('active');
        $('#view-title').text(title);
        if (panelId !== '#dm-panel') {
            $('#dm-form').hide();
        }
    }

    function clearIntervals() {
        if (messageInterval) {
            clearInterval(messageInterval);
            messageInterval = null;
        }
        if (dmInterval) {
            clearInterval(dmInterval);
            dmInterval = null;
        }
    }

    function fetchServers() {
        $.get('index.php?servers=1', function(data) {
            const servers = data;
            const list = $('#server-list');
            list.empty();
            if (!servers.length) {
                const empty = $('<div class="empty-state" style="padding:20px; font-size:12px;">Noch keine Server</div>');
                list.append(empty);
                return;
            }
            servers.forEach(server => {
                const button = $('<button class="server-item" data-tooltip="' + server.name + '"></button>');
                if (server.icon_path) {
                    button.append('<img src="' + server.icon_path + '" alt="' + server.name + '">');
                } else {
                    button.append('<span>' + server.name.charAt(0).toUpperCase() + '</span>');
                }
                button.data('server-id', server.id);
                button.data('server-name', server.name);
                list.append(button);
            });
        }, 'json');
    }

    function renderMessages(messages) {
        const box = $('#chat-box');
        box.removeClass('empty-state');
        box.empty();
        if (!messages.length) {
            box.addClass('empty-state');
            box.html('<strong>Noch keine Nachrichten</strong>Starte die Unterhaltung mit einer ersten Nachricht.');
            return;
        }
        messages.forEach(msg => {
            const item = $('<div class="message"></div>');
            item.append('<strong>' + msg.username + '</strong>');
            item.append('<span>' + msg.message + '</span>');
            item.append('<span class="timestamp">' + msg.created_at + '</span>');
            box.append(item);
        });
        box.scrollTop(box[0].scrollHeight);
    }

    function fetchMessages(serverId) {
        $.get('index.php?fetch=1&server_id=' + serverId, function(data) {
            renderMessages(data);
        }, 'json');
    }

    function fetchMembers(serverId) {
        $.get('index.php?members=1&server_id=' + serverId, function(data) {
            const list = $('#member-list-content');
            list.empty();
            if (!data.length) {
                list.append('<div class="empty-state" style="padding:20px; font-size:12px;">Keine Mitglieder gefunden</div>');
                return;
            }
            data.forEach(member => {
                const card = $('<div class="member"></div>');
                card.append('<span>' + member.username + '</span>');
                card.append('<span class="timestamp">seit ' + member.joined_at + '</span>');
                list.append(card);
            });
        }, 'json');
    }

    function loadBadges() {
        $.get('index.php?badges=1', function(data) {
            const strip = $('#badge-strip');
            strip.empty();
            data.forEach(badge => {
                const badgeEl = $('<span class="badge"></span>');
                badgeEl.text(badge.name);
                badgeEl.css('background', badge.color);
                badgeEl.attr('title', badge.description);
                strip.append(badgeEl);
            });
        }, 'json');
    }

    function loadUserContext() {
        $.get('index.php?me=1', function(data) {
            if (!data.logged_in) {
                $('#login-modal').css('display', 'flex');
                $('#logout-button').hide();
                $('#username-display').text('');
                $('#badge-strip').empty();
                return;
            }

            $('#login-modal').hide();
            $('#logout-button').show();
            $('#username-display').text(data.username);
            isEmployee = data.is_employee;
            if (isEmployee) {
                $('#open-admin').show();
            } else {
                $('#open-admin').hide();
            }
            const strip = $('#badge-strip');
            strip.empty();
            data.badges.forEach(badge => {
                const badgeEl = $('<span class="badge"></span>');
                badgeEl.text(badge.name);
                badgeEl.css('background', badge.color);
                badgeEl.attr('title', badge.description);
                strip.append(badgeEl);
            });
            fetchServers();
            fetchFriends();
            fetchConversations();
        }, 'json');
    }

    function fetchConversations() {
        $.get('index.php?conversations=1', function(data) {
            const list = $('#dm-list');
            list.empty();
            if (!data.length) {
                list.append('<div class="empty-state" style="padding:20px; font-size:12px;">Noch keine Direktnachrichten</div>');
                return;
            }
            data.forEach(conv => {
                const card = $('<div class="list-card"></div>');
                card.append('<h4>' + conv.other_user + '</h4>');
                const button = $('<button class="ghost-button">Öffnen</button>');
                button.click(function() {
                    openDM(conv.other_user);
                });
                card.append(button);
                list.append(card);
            });
        }, 'json');
    }

    function renderDMThread(messages) {
        const thread = $('#dm-thread');
        thread.empty();
        if (!messages.length) {
            thread.append('<div class="empty-state" style="padding:20px; font-size:12px;">Schreibe die erste Nachricht</div>');
            return;
        }
        messages.forEach(msg => {
            const item = $('<div class="message"></div>');
            item.append('<strong>' + msg.sender + '</strong>');
            item.append('<span>' + msg.message + '</span>');
            item.append('<span class="timestamp">' + msg.created_at + '</span>');
            thread.append(item);
        });
        thread.scrollTop(thread[0].scrollHeight);
    }

    function fetchDMs(username) {
        $.get('index.php?direct_messages=1&user=' + encodeURIComponent(username), function(data) {
            renderDMThread(data);
        }, 'json');
    }

    function fetchFriends() {
        $.get('index.php?friends=1', function(data) {
            const friendList = $('#friend-list');
            const requestList = $('#request-list');
            friendList.empty();
            requestList.empty();

            if (!data.friends.length) {
                friendList.append('<div class="empty-state" style="padding:20px; font-size:12px;">Noch keine Freunde</div>');
            } else {
                data.friends.forEach(friend => {
                    const card = $('<div class="list-card"></div>');
                    card.append('<div><h4>' + friend.friend + '</h4><span>Seit ' + friend.created_at + '</span></div>');
                    const button = $('<button class="ghost-button">DM</button>');
                    button.click(function() {
                        openDM(friend.friend);
                    });
                    card.append(button);
                    friendList.append(card);
                });
            }

            if (!data.requests.length) {
                requestList.append('<div class="empty-state" style="padding:20px; font-size:12px;">Keine offenen Anfragen</div>');
            } else {
                data.requests.forEach(req => {
                    const card = $('<div class="list-card"></div>');
                    card.append('<div><h4>' + req.requester + '</h4><span>angefragt am ' + req.created_at + '</span></div>');
                    const actions = $('<div></div>');
                    const accept = $('<button class="ghost-button" style="border-color:var(--success); color:var(--success);">Annehmen</button>');
                    const decline = $('<button class="ghost-button" style="border-color:var(--danger); color:var(--danger);">Ablehnen</button>');
                    accept.click(function() {
                        respondFriend(req.id, 'accept');
                    });
                    decline.click(function() {
                        respondFriend(req.id, 'decline');
                    });
                    actions.append(accept).append(decline);
                    card.append(actions);
                    requestList.append(card);
                });
            }
        }, 'json');
    }

    function fetchExplorer() {
        $.get('index.php?explore_servers=1', function(data) {
            const list = $('#explorer-list');
            list.empty();
            if (!data.length) {
                list.append('<div class="empty-state"><strong>Keine Server gefunden</strong>Sei der Erste und erstelle einen neuen Server!</div>');
                return;
            }
            data.forEach(server => {
                const card = $('<div class="list-card"></div>');
                card.append('<div><h4>' + server.name + '</h4><span>' + server.member_count + ' Mitglieder</span></div>');
                const joinButton = $('<button class="ghost-button">Beitreten</button>');
                joinButton.click(function() {
                    $('#invite-code').val(server.invite_token);
                    $('#join-server-modal').css('display', 'flex');
                });
                card.append(joinButton);
                list.append(card);
            });
        }, 'json');
    }

    function fetchAdminStats() {
        if (!isEmployee) return;
        $.get('index.php?admin_stats=1', function(data) {
            if (data.error) {
                $('#admin-panel').html('<div class="empty-state"><strong>Zugriff verweigert</strong></div>');
                return;
            }
            $('#stat-users').text(data.user_count);
            $('#stat-servers').text(data.server_count);
            $('#stat-messages').text(data.message_count);
            $('#stat-dms').text(data.dm_count);
            const recent = $('#recent-users');
            recent.empty();
            data.recent_users.forEach(user => {
                recent.append('<li><span>' + user.username + '</span><span>' + user.created_at + '</span></li>');
            });
        }, 'json');
    }

    function openDM(username) {
        currentDMUser = username;
        $('#current-dm-user').val(username);
        $('#dm-form').css('display', 'flex');
        switchPanel('#dm-panel', 'Direktnachrichten mit ' + username);
        fetchDMs(username);
        clearIntervals();
        dmInterval = setInterval(function() {
            fetchDMs(username);
        }, fetchInterval);
    }

    function respondFriend(requestId, action) {
        $.post('index.php', { respond_friend: 1, request_id: requestId, action: action }, function(response) {
            alert(response);
            fetchFriends();
        });
    }

    $('#server-list').on('click', '.server-item', function() {
        const serverId = $(this).data('server-id');
        const serverName = $(this).data('server-name');
        currentServerId = serverId;
        $('#current-server-id').val(serverId);
        $('#chat-form').show();
        $('#chat-title').text(serverName);
        switchPanel('#chat-panel', serverName);
        fetchMessages(serverId);
        fetchMembers(serverId);
        clearIntervals();
        messageInterval = setInterval(function() {
            fetchMessages(serverId);
        }, fetchInterval);
    });

    $('#chat-form').submit(function(e) {
        e.preventDefault();
        if (!currentServerId) return;
        const message = $('#message').val();
        $.post('index.php', { send_message: 1, content: message, server_id: currentServerId }, function(response) {
            if (response === 'Message sent!') {
                $('#message').val('');
                fetchMessages(currentServerId);
            } else {
                alert(response);
            }
        });
    });

    $('#dm-form').submit(function(e) {
        e.preventDefault();
        const dmMessage = $('#dm-message').val();
        const receiver = $('#current-dm-user').val();
        $.post('index.php', { send_dm: 1, dm_message: dmMessage, receiver: receiver }, function(response) {
            if (response === 'DM sent!') {
                $('#dm-message').val('');
                fetchDMs(receiver);
            } else {
                alert(response);
            }
        });
    });

    $('#add-friend-form').submit(function(e) {
        e.preventDefault();
        const username = $('#friend-username').val();
        $.post('index.php', { add_friend: 1, friend_username: username }, function(response) {
            alert(response);
            $('#friend-username').val('');
            fetchFriends();
        });
    });

    $('#friends-button').click(function() {
        switchPanel('#friends-panel', 'Freunde');
        clearIntervals();
    });

    $('#dms-button').click(function() {
        switchPanel('#dm-panel', 'Direktnachrichten');
        clearIntervals();
        fetchConversations();
    });

    $('#open-explorer').click(function() {
        switchPanel('#explorer-panel', 'Server Explorer');
        fetchExplorer();
        clearIntervals();
    });

    $('#open-admin').click(function() {
        if (!isEmployee) return;
        switchPanel('#admin-panel', 'Admin Dashboard');
        fetchAdminStats();
        clearIntervals();
    });

    $('#refresh-content').click(function() {
        if ($('#chat-panel').hasClass('active') && currentServerId) {
            fetchMessages(currentServerId);
            fetchMembers(currentServerId);
        }
        if ($('#dm-panel').hasClass('active') && currentDMUser) {
            fetchDMs(currentDMUser);
        }
        fetchServers();
        fetchFriends();
        fetchConversations();
        if ($('#explorer-panel').hasClass('active')) {
            fetchExplorer();
        }
        if ($('#admin-panel').hasClass('active')) {
            fetchAdminStats();
        }
    });

    $('#logout-button').click(function() {
        $.post('index.php', { logout: 1 }, function(response) {
            alert(response);
            clearIntervals();
            currentServerId = null;
            currentDMUser = null;
            $('#dm-form').hide();
            $('#chat-box').addClass('empty-state').html('<strong>Kein Server ausgewählt</strong>Wähle links einen Server aus oder erstelle einen neuen.');
            $('#chat-form').hide();
            $('#member-list-content').empty();
            fetchServers();
            $('#login-modal').css('display', 'flex');
        });
    });

    $('#show-create-server').click(function() {
        const serverName = prompt('Servername eingeben:');
        const iconPath = prompt('Icon URL (optional):');
        if (!serverName) return;
        $.post('index.php', { create_server: 1, server_name: serverName, icon_path: iconPath || '' }, function(response) {
            alert(response);
            fetchServers();
        });
    });

    $('#open-join').click(function() {
        $('#join-server-modal').css('display', 'flex');
    });

    $('#close-join-modal').click(function() {
        $('#join-server-modal').hide();
    });

    $('#join-server-form').submit(function(e) {
        e.preventDefault();
        const code = $('#invite-code').val();
        $.post('index.php', { join_server: 1, invite_token: code }, function(response) {
            $('#join-message').text(response);
            if (response === 'Successfully joined the server!') {
                fetchServers();
                $('#join-server-modal').hide();
                $('#invite-code').val('');
            }
        });
    });

    $('#close-modal').click(function() {
        $('#login-modal').hide();
    });

    $('#open-register').click(function() {
        $('#login-form').hide();
        $('#register-form').show();
        $('#modal-title').text('Registrieren');
    });

    $('#open-login').click(function() {
        $('#register-form').hide();
        $('#login-form').show();
        $('#modal-title').text('Anmelden');
    });

    $('#login-form').submit(function(e) {
        e.preventDefault();
        const username = $('#login-username').val();
        const password = $('#login-password').val();
        $.post('index.php', { login: 1, username: username, password: password }, function(response) {
            if (response === 'Login successful!') {
                loadUserContext();
            } else {
                $('#login-message').text(response);
            }
        });
    });

    $('#register-form').submit(function(e) {
        e.preventDefault();
        const username = $('#register-username').val();
        const password = $('#register-password').val();
        $.post('index.php', { register: 1, username: username, password: password }, function(response) {
            $('#register-message').text(response);
            if (response === 'Registration successful!') {
                $('#register-form').hide();
                $('#login-form').show();
                $('#modal-title').text('Anmelden');
            }
        });
    });

    loadUserContext();
});
</script>
</body>
</html>
