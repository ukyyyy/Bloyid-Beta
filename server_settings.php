<?php
// Database connection
$host = "host";
$dbname = "db_name";
$user = "db_user";
$pass = "";

$conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: index.php"); // Redirect to index if not logged in
    exit;
}

$username = $_SESSION['username'];

// Fetch user's servers
$stmt = $conn->prepare("
    SELECT servers.* FROM servers
    JOIN server_members ON servers.id = server_members.server_id
    WHERE server_members.username = :username
");
$stmt->execute(['username' => $username]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle server deletion
if (isset($_POST['delete_server'])) {
    $server_id = $_POST['server_id'];

    // Check if the logged-in user is the owner of the server
    $stmt = $conn->prepare("SELECT owner FROM servers WHERE id = :server_id");
    $stmt->execute(['server_id' => $server_id]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($server && $server['owner'] === $username) {
        // Delete server
        $stmt = $conn->prepare("DELETE FROM servers WHERE id = :server_id");
        $stmt->execute(['server_id' => $server_id]);
        
        // Remove all members from the server
        $stmt = $conn->prepare("DELETE FROM server_members WHERE server_id = :server_id");
        $stmt->execute(['server_id' => $server_id]);

        echo "Server deleted successfully!";
        exit;
    } else {
        echo "You do not have permission to delete this server.";
    }
}

// Handle server renaming
if (isset($_POST['rename_server'])) {
    $server_id = $_POST['server_id'];
    $new_name = $_POST['new_name'];

    // Check if the logged-in user is the owner of the server
    $stmt = $conn->prepare("SELECT owner FROM servers WHERE id = :server_id");
    $stmt->execute(['server_id' => $server_id]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($server && $server['owner'] === $username) {
        // Update server name
        $stmt = $conn->prepare("UPDATE servers SET name = :new_name WHERE id = :server_id");
        $stmt->execute(['new_name' => $new_name, 'server_id' => $server_id]);

        echo "Server renamed successfully!";
        exit;
    } else {
        echo "You do not have permission to rename this server.";
    }
}

// Handle invite creation
if (isset($_POST['create_invite'])) {
    $server_id = $_POST['server_id'];

    // Check if the logged-in user is the owner of the server
    $stmt = $conn->prepare("SELECT owner FROM servers WHERE id = :server_id");
    $stmt->execute(['server_id' => $server_id]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($server && $server['owner'] === $username) {
        $invite_code = bin2hex(random_bytes(5)); // Simple random invite code

        // Create invite record
        $stmt = $conn->prepare("INSERT INTO invites (server_id, invite_code) VALUES (:server_id, :invite_code)");
        $stmt->execute(['server_id' => $server_id, 'invite_code' => $invite_code]);

        // Create the full invite link
        $invite_link = "https://bloyid.wuaze.com/join.php?code=" . $invite_code; // Replace with your actual domain

        echo "Invite created: <a href='" . htmlspecialchars($invite_link) . "'>" . htmlspecialchars($invite_link) . "</a>";
        exit;
    } else {
        echo "You do not have permission to create an invite for this server.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Settings</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #36393f;
            color: #ffffff;
            margin: 0;
            padding: 20px;
        }
        h1 {
            margin-bottom: 20px;
        }
        .server {
            margin-bottom: 20px;
            padding: 10px;
            background-color: #2f3136;
            border-radius: 5px;
        }
        form {
            margin-top: 10px;
        }
        input[type="text"], input[type="hidden"] {
            padding: 10px;
            margin-right: 5px;
            border: none;
            border-radius: 5px;
        }
        button {
            padding: 10px;
            border: none;
            border-radius: 5px;
            background-color: #7289da;
            color: white;
            cursor: pointer;
        }
    </style>
</head>
<body>

<h1>Server Settings</h1>

<?php foreach ($servers as $server): ?>
    <div class="server">
        <h2><?php echo htmlspecialchars($server['name']); ?></h2>
        <form method="POST">
            <input type="hidden" name="server_id" value="<?php echo $server['id']; ?>">
            <input type="text" name="new_name" placeholder="New server name" required>
            <button type="submit" name="rename_server">Rename Server</button>
        </form>
        <form method="POST">
            <input type="hidden" name="server_id" value="<?php echo $server['id']; ?>">
            <button type="submit" name="delete_server" style="background-color: #dc143c;">Delete Server</button>
        </form>
        <form method="POST">
            <input type="hidden" name="server_id" value="<?php echo $server['id']; ?>">
            <button type="submit" name="create_invite">Create Invite</button>
        </form>
    </div>
<?php endforeach; ?>

</body>
</html>
