<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// 1. Database Connection (Auto-Detect Environment)
$host = $_SERVER['HTTP_HOST'];

if ($host == 'localhost' || $host == '127.0.0.1') {
    // LOCALHOST (XAMPP) SETTINGS
    $servername = "localhost";
    $username = "root";
    $password = ""; 
    $dbname = "smarttailor_db"; // Your local XAMPP database name
} else {
    // HOSTINGER LIVE SETTINGS
    $servername = "localhost"; 
    $username = "u248499112_smarttailor"; // Your partner's DB Username
    $password = "Smarttailor123."; // Your partner's DB Password
    $dbname = "u248499112_smarttailor_db"; // The existing DB Name from the screenshot
}

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die(json_encode(["error" => "Connection failed"])); }   

// 2. AI Moderation Engine
function aiValidateReview($text) {
    $offensiveWords = ['stupid', 'scam', 'hate', 'fake', 'bad', 'terrible', 'horrible', 'worst', 'awful', 'trash', 'useless', 'idiot', 'disaster', 'cheat', 'liar', 'rude'];
    $positiveWords = ['good', 'great', 'love', 'perfect', 'excellent', 'amazing', 'best', 'nice'];
    
    $textLower = strtolower($text);
    $isOffensive = false;
    foreach($offensiveWords as $word) { if (strpos($textLower, $word) !== false) { $isOffensive = true; break; } }
    
    $score = 0.5;
    foreach($positiveWords as $word) { if (strpos($textLower, $word) !== false) { $score += 0.4; break; } }
    if ($isOffensive) $score = 0.0;

    return ['isValid' => !$isOffensive, 'sentimentScore' => $score];
}

// 3. Tailor Rating Recalculator
function recalculateTailorRating($conn, $tailorId) {
    $stmt = $conn->prepare("SELECT rating FROM reviews WHERE tailorId = ? AND status = 'Published'");
    $stmt->bind_param("i", $tailorId);
    $stmt->execute();
    $res = $stmt->get_result();
    $total = 0; $count = 0;
    while($row = $res->fetch_assoc()) { $total += $row['rating']; $count++; }
    $avg = $count > 0 ? round($total / $count, 2) : 0;
    
    $upd = $conn->prepare("UPDATE tailor_profiles SET rating = ? WHERE user_id = ?");
    $upd->bind_param("di", $avg, $tailorId);
    $upd->execute();
}

// 4. API Routing
$action = isset($_GET['action']) ? $_GET['action'] : '';
// SMART FIX: Handle BOTH JSON (for logins) AND FormData (for photo uploads)
$data = json_decode(file_get_contents('php://input'), true);
if (!$data && !empty($_POST)) {
    $data = $_POST; 
}

switch ($action) {
    case 'login':
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND password = ?");
        $stmt->bind_param("ss", $data['email'], $data['password']);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($user = $res->fetch_assoc()) {
            echo json_encode($user);
            exit;
        } else { 
            http_response_code(401); 
            echo json_encode(['error' => 'Invalid credentials']); 
            exit; 
        }
        break;   

    case 'register':
        $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $data['name'], $data['email'], $data['password'], $data['role']);
        if($stmt->execute()) echo json_encode(['success' => true]);
        else { http_response_code(400); echo json_encode(['error' => 'Registration failed']); }
        break;

    case 'get_jobs':
        $stmt = $conn->prepare("SELECT 
            j.id as jobId, 
            u.name as tailorName, 
            a.tailor_id as tailorId, 
            j.status, 
            j.title as garment_type, 
            j.created_at, 
            (SELECT COUNT(*) FROM reviews r WHERE r.jobId = j.id) as reviewCount, 
            (SELECT rating FROM reviews r WHERE r.jobId = j.id LIMIT 1) as givenRating,
            (SELECT status FROM reviews r WHERE r.jobId = j.id LIMIT 1) as reviewStatus 
            FROM job_postings j 
            INNER JOIN applications a ON j.id = a.job_id 
            INNER JOIN users u ON a.tailor_id = u.id 
            WHERE j.customer_id = ? 
            AND j.status LIKE '%ompleted%' 
            AND a.status LIKE '%ccepted%' 
            ORDER BY j.created_at DESC");
            
        $stmt->bind_param("i", $_GET['customerId']);
        $stmt->execute();
        $res = $stmt->get_result();
        $jobs = [];
        while($row = $res->fetch_assoc()) $jobs[] = $row;
        echo json_encode($jobs);
        break;  

    case 'submit_feedback':
        $stmt = $conn->prepare("SELECT id FROM reviews WHERE jobId = ?");
        $stmt->bind_param("i", $data['jobId']);
        $stmt->execute();
        if($stmt->get_result()->num_rows > 0) { echo json_encode(['error' => 'Already rated!']); break; }

        $ai = aiValidateReview($data['comment']);
        $status = $ai['isValid'] ? 'Published' : 'Held';
        
        // FEATURE 1: Photo Upload Logic (Max 3)
        $uploadedPhotos = [];
        if (isset($_FILES['photos'])) {
            $uploadDir = '../feedback_uploads/'; // Ensure you create an 'uploads' folder in your AwardSpace File Manager!
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $fileCount = count($_FILES['photos']['name']);
            for ($i = 0; $i < min(3, $fileCount); $i++) {
                if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                    // Create a unique name so images don't overwrite each other
                    $fileName = time() . '_' . basename($_FILES['photos']['name'][$i]);
                    $fileName = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $fileName); // Clean file name
                    
                    if (move_uploaded_file($_FILES['photos']['tmp_name'][$i], $uploadDir . $fileName)) {
                        $uploadedPhotos[] = $fileName;
                    }
                }
            }
        }
        $photosString = implode(',', $uploadedPhotos); // Saves as "img1.jpg,img2.jpg"

        $stmt = $conn->prepare("INSERT INTO reviews (jobId, tailorId, customerId, customerName, rating, comment, sentimentScore, status, photos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisssdss", $data['jobId'], $data['tailorId'], $data['customerId'], $data['customerName'], $data['rating'], $data['comment'], $ai['sentimentScore'], $status, $photosString);
        $stmt->execute();
        
        if ($status === 'Published') recalculateTailorRating($conn, $data['tailorId']);
        echo json_encode(['status' => $status]);
        break; 


    case 'get_tailor_stats':
        $stmt = $conn->prepare("SELECT rating as avgRating FROM tailor_profiles WHERE user_id = ?");
        $stmt->bind_param("i", $_GET['tailorId']);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        
        $stmt2 = $conn->prepare("SELECT * FROM reviews WHERE tailorId = ? AND status = 'Published' ORDER BY timestamp DESC");
        $stmt2->bind_param("i", $_GET['tailorId']);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $reviews = [];
        while($row = $res2->fetch_assoc()) $reviews[] = $row;
        
        echo json_encode(['stats' => ['avgRating' => $stats ? $stats['avgRating'] : 0, 'totalReviews' => count($reviews)], 'reviews' => $reviews]);
        break;

    case 'get_admin_flagged':
        $res = $conn->query("SELECT * FROM reviews WHERE status = 'Held'");
        $held = [];
        while($row = $res->fetch_assoc()) $held[] = $row;
        echo json_encode($held);
        break;

    // 🌐 NEW ROUTE: Fetch all recent reviews so the system log catches everything!
    case 'get_all_submissions':
        $res = $conn->query("SELECT jobId, customerName, status FROM reviews ORDER BY timestamp DESC LIMIT 20");
        $all = [];
        while($row = $res->fetch_assoc()) $all[] = $row;
        echo json_encode($all);
        break;   

    case 'moderate_review':
        $actionType = $data['action']; // Expected: 'Approve' or 'Reject'
        $reviewId = $data['reviewId'];
        
        // 1. Get info before changing database to know who to notify
        $info = $conn->query("SELECT r.customerId, u.name as tailorName FROM reviews r JOIN users u ON r.tailorId = u.id WHERE r.id = $reviewId")->fetch_assoc();
        $cId = $info['customerId'];
        $tName = $info['tailorName'];

        if ($actionType === 'Approve') {
            $conn->query("UPDATE reviews SET status = 'Published' WHERE id = $reviewId");
            
            // Notification for APPROVAL (Fixed to camelCase userId)
            $msg = "✅ Your review for <b>$tName</b> has been approved and published.";
            $conn->query("INSERT INTO feedback_notifications (userId, message) VALUES ($cId, '$msg')");

            // Recalculate rating
            $tId = $conn->query("SELECT tailorId FROM reviews WHERE id = $reviewId")->fetch_assoc()['tailorId'];
            recalculateTailorRating($conn, $tId);
            
        } else {
            // Notification for REJECTION (Fixed to camelCase userId)
            $msg = "⚠️ Your review for <b>$tName</b> was rejected. Please resubmit using constructive language.";
            $conn->query("INSERT INTO feedback_notifications (userId, message) VALUES ($cId, '$msg')");

            $conn->query("DELETE FROM reviews WHERE id = $reviewId");
        }
        echo json_encode(['success' => true]);
        break;   

    case 'get_notifications':
        $uId = $_GET['userId'];
        // Fixed to camelCase userId and timestamp
        $res = $conn->query("SELECT * FROM feedback_notifications WHERE userId = $uId ORDER BY timestamp DESC LIMIT 8");
        $notifs = [];
        while($row = $res->fetch_assoc()) $notifs[] = $row;
        echo json_encode($notifs);
        break;

    case 'mark_notifs_read':
        $uId = $data['userId'];
        // Fixed to camelCase isRead and userId
        $conn->query("UPDATE feedback_notifications SET isRead = 1 WHERE userId = $uId");
        echo json_encode(['success' => true]);
        break;   
}
$conn->close();  
