<?php
// Database connection
$host = "sql304.infinityfree.com";
$port = "3306";
$dbname = "if0_37536001_bloyid";
$user = "if0_37536001";
$pass = "298612Jasonn";

$conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);

// Registration
if (isset($_POST['register'])) {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (:username, :password)");
    $stmt->execute(['username' => $username, 'password' => $password]);
    echo "Registration successful!";
    exit;
}

// Login
if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        session_start();
        $_SESSION['username'] = $username;
        echo "Login successful!";
    } else {
        echo "Incorrect username or password!";
    }
    exit;
}

// Logout
if (isset($_POST['logout'])) {
    session_start();
    session_destroy();
    echo "Logout successful!";
    exit;
}

// Send chat message
if (isset($_POST['message'])) {
    session_start();
    if (!isset($_SESSION['username'])) {
        echo "Please log in!";
        exit;
    }

    $username = $_SESSION['username'];
    $server_id = $_POST['server_id'];
    $message = $_POST['message'];
    
    $stmt = $conn->prepare("INSERT INTO chat (username, message, server_id) VALUES (:username, :message, :server_id)");
    $stmt->execute(['username' => $username, 'message' => $message, 'server_id' => $server_id]);
    echo "Message sent!";
    exit;
}

// Fetch messages
if (isset($_GET['fetch'])) {
    $server_id = $_GET['server_id'];
    $stmt = $conn->prepare("SELECT * FROM chat WHERE server_id = :server_id ORDER BY id DESC LIMIT 50");
    $stmt->execute(['server_id' => $server_id]);
    $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($chats);
    exit;
}

// Fetch servers for the logged-in user
if (isset($_GET['servers'])) {
    session_start();
    if (!isset($_SESSION['username'])) {
        echo json_encode([]);
        exit;
    }
    $username = $_SESSION['username'];

    $stmt = $conn->prepare("
        SELECT servers.* FROM servers
        JOIN server_members ON servers.id = server_members.server_id
        WHERE server_members.username = :username
    ");
    $stmt->execute(['username' => $username]);
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($servers);
    exit;
}

// Create server
if (isset($_POST['create_server'])) {
    session_start();
    $server_name = $_POST['server_name'];
    $username = $_SESSION['username'];
    $icon_path = $_POST['icon_path'];

    // Generate unique invite token
    $invite_token = bin2hex(random_bytes(16));

    $stmt = $conn->prepare("INSERT INTO servers (name, owner_username, invite_token, icon_path) VALUES (:name, :owner_username, :invite_token, :icon_path)");
    $stmt->execute(['name' => $server_name, 'owner_username' => $username, 'invite_token' => $invite_token, 'icon_path' => $icon_path]);
    
    // Add creator as member
    $server_id = $conn->lastInsertId();
    $stmt = $conn->prepare("INSERT INTO server_members (server_id, username) VALUES (:server_id, :username)");
    $stmt->execute(['server_id' => $server_id, 'username' => $username]);
    
    echo "Server successfully created! Invite token: $invite_token"; // Show invite token to the creator
    exit;
}

// Join server using invite token
if (isset($_POST['join_server'])) {
    session_start();
    $username = $_SESSION['username'];
    $invite_token = $_POST['invite_token'];

    // Get server ID based on the invite token
    $stmt = $conn->prepare("SELECT id FROM servers WHERE invite_token = :invite_token");
    $stmt->execute(['invite_token' => $invite_token]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($server) {
        $server_id = $server['id'];
        $stmt = $conn->prepare("INSERT INTO server_members (server_id, username) VALUES (:server_id, :username)");
        $stmt->execute(['server_id' => $server_id, 'username' => $username]);
        echo "Successfully joined the server!";
    } else {
        echo "Invalid invite token!";
    }
    exit;
}

// Fetch server members
if (isset($_GET['members'])) {
    $server_id = $_GET['server_id'];
    $stmt = $conn->prepare("SELECT username FROM server_members WHERE server_id = :server_id");
    $stmt->execute(['server_id' => $server_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($members);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bloyid</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #36393f;
            color: #ffffff;
            margin: 0;
            display: flex;
            height: 100vh;
        }
        #server-list {
            width: 250px;
            background-color: #2f3136;
            padding: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }
        .server-item {
            display: flex;
            align-items: center;
            margin: 5px 0;
            cursor: pointer;
        }
        .server-icon {
            width: 40px; /* Größe des Icons */
            height: 40px; /* Größe des Icons */
            border-radius: 50%; /* Runde Form */
            margin-right: 10px;
        }
        #chat-container {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            background-color: #40444b;
            padding: 10px;
        }
        #chat-box {
            flex-grow: 1;
            overflow-y: auto;
            padding: 10px;
            border-radius: 5px;
            background-color: #282b30;
        }
        #member-list {
            width: 200px;
            background-color: #2f3136;
            padding: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }
        #chat-form {
            display: flex;
            margin-top: 10px;
        }
        #chat-form input {
            flex-grow: 1;
            padding: 10px;
            border: none;
            border-radius: 5px;
            margin-right: 5px;
        }
        #chat-form button {
            padding: 10px;
            border: none;
            border-radius: 5px;
            background-color: #7289da;
            color: white;
        }
        #logout-button {
            background-color: #dc143c;
            color: white;
            padding: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
        }
        h2, h3 {
            margin: 0;
        }
        /* Modal styles */
        #login-modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
            padding-top: 60px;
        }
        #modal-content {
            background-color: #282b30;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 300px;
            border-radius: 5px;
        }
        #register-link {
            cursor: pointer;
            color: #7289da;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>

<!-- Server List -->
<div id="server-list-container">
    <h3>Servers</h3>
    <div id="server-list"></div>
    <button id="show-create-server">Create Server</button>
    <button id="logout-button" style="display:none;">Logout</button>
    <p id="invite-link">Join Server</p>
</div>

<!-- Chat Container -->
<div id="chat-container">
    <h2>Chat</h2>
    <div id="chat-box"></div>
    <form id="chat-form">
        <input type="text" id="message" placeholder="Type your message..." required>
        <input type="hidden" id="current-server-id">
        <button type="submit">Send</button>
    </form>
</div>

<!-- Member List -->
<div id="member-list">
    <h3>Members</h3>
    <ul id="member-list-content"></ul>
</div>

<!-- Login Modal -->
<div id="login-modal">
    <div id="modal-content">
        <span id="close-modal" style="cursor:pointer; float:right;">&times;</span>
        <h2>Login</h2>
        <form id="login-form">
            <input type="text" id="login-username" placeholder="Username" required>
            <input type="password" id="login-password" placeholder="Password" required>
            <button type="submit">Login</button>
            <p id="login-message" style="color: red;"></p>
            <p id="register-link">Don't have an account? Register here.</p>
        </form>
        <form id="register-form" style="display: none;">
            <input type="text" id="register-username" placeholder="Username" required>
            <input type="password" id="register-password" placeholder="Password" required>
            <button type="submit">Register</button>
            <p id="register-message" style="color: red;"></p>
            <p id="login-link">Already have an account? Login here.</p>
        </form>
    </div>
</div>

<!-- Join Server Modal -->
<div id="join-server-modal" style="display:none;">
    <div id="modal-content">
        <span id="close-join-modal" style="cursor:pointer; float:right;">&times;</span>
        <h2>Join Server</h2>
        <form id="join-server-form">
            <input type="text" id="invite-code" placeholder="Enter invite token" required>
            <button type="submit">Join</button>
            <p id="join-message" style="color: red;"></p>
        </form>
    </div>
</div>

<script>
 const fetchInterval = 2000; // Interval for fetching messages
 let currentServerId = null;
$(document).ready(function() {
    // Fetch servers for the logged-in user
    function fetchServers() {
        $.get('index.php?servers=1', function(data) {
            const servers = JSON.parse(data);
            $('#server-list').empty();
            servers.forEach(server => {
                $('#server-list').append(`
                    <button class="server-item" data-id="${server.id}">
                        <img src="${server.icon_path}" class="server-icon" alt="${server.name} Icon"> 
                        ${server.name}
                    </button>
                `);
            });
        });
    }

    fetchServers(); // Initial fetch

    // Fetch chat messages for the selected server
    function fetchMessages(server_id) {
        $.get('index.php?fetch=1&server_id=' + server_id, function(data) {
            const chats = JSON.parse(data);
            $('#chat-box').empty();
            chats.forEach(chat => {
                $('#chat-box').append(`<p><strong>${chat.username}:</strong> ${chat.message}</p>`);
            });
            $('#chat-box').scrollTop($('#chat-box')[0].scrollHeight);
        });
    }

    // Fetch members for the selected server
    function fetchMembers(server_id) {
        $.get('index.php?members=1&server_id=' + server_id, function(data) {
            const members = JSON.parse(data);
            $('#member-list-content').empty();
            members.forEach(member => {
                $('#member-list-content').append(`<li>${member.username}</li>`);
            });
        });
    }

    // Handle server selection
    $('#server-list').on('click', '.server-item', function() {
        const server_id = $(this).data('id');
        $('#current-server-id').val(server_id);
        fetchMessages(server_id);
        fetchMembers(server_id);
    });

    // Send chat message
    $('#chat-form').submit(function(e) {
        e.preventDefault();
        const message = $('#message').val();
        const server_id = $('#current-server-id').val();

        $.post('index.php', { message: 1, message: message, server_id: server_id }, function(response) {
            if (response === "Message sent!") {
                $('#message').val('');
                fetchMessages(server_id); // Refresh messages
            }
        });
    });

    // Show create server form
    $('#show-create-server').click(function() {
        const server_name = prompt("Enter server name:");
        const icon_path = prompt("Enter icon URL:");
        if (server_name && icon_path) {
            $.post('index.php', { create_server: 1, server_name: server_name, icon_path: icon_path }, function(response) {
                alert(response);
                fetchServers(); // Refresh server list
            });
        }
    });

    // Join server form submission
    $('#join-server-form').submit(function(e) {
        e.preventDefault();
        const invite_token = $('#invite-code').val();

        $.post('index.php', { join_server: 1, invite_token: invite_token }, function(response) {
            $('#join-message').text(response);
            if (response === "Successfully joined the server!") {
                fetchServers(); // Refresh server list
                $('#join-server-modal').fadeOut();
            }
        });
    });

    // Show login modal
    $('#login-modal').fadeIn();

    // Close login modal
    $('#close-modal').click(function() {
        $('#login-modal').fadeOut();
    });

    // Handle login form submission
    $('#login-form').submit(function(e) {
        e.preventDefault();
        const username = $('#login-username').val();
        const password = $('#login-password').val();

        $.post('index.php', { login: 1, username: username, password: password }, function(response) {
            if (response === "Login successful!") {
                $('#login-modal').fadeOut();
                $('#logout-button').show();
                fetchServers(); // Refresh server list
            } else {
                $('#login-message').text(response);
            }
        });
    });

    // Show register form
    $('#register-link').click(function() {
        $('#login-form').hide();
        $('#register-form').show();
    });

    // Handle register form submission
    $('#register-form').submit(function(e) {
        e.preventDefault();
        const username = $('#register-username').val();
        const password = $('#register-password').val();

        $.post('index.php', { register: 1, username: username, password: password }, function(response) {
            $('#register-message').text(response);
            if (response === "Registration successful!") {
                $('#register-form').hide();
                $('#login-form').show();
            }
        });
    });

    // Show login form
    $('#login-link').click(function() {
        $('#register-form').hide();
        $('#login-form').show();
    });

    // Logout button click
    $('#logout-button').click(function() {
        $.post('index.php', { logout: 1 }, function(response) {
            alert(response);
            $('#logout-button').hide();
            $('#server-list').empty();
            $('#chat-box').empty();
            $('#member-list-content').empty();
        });
    });
});
</script>

</body>
</html>
