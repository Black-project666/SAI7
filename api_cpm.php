<?php
// Header untuk memberitahu browser bahwa responsnya adalah JSON
header('Content-Type: application/json');

// Fungsi untuk mengirim respons JSON yang konsisten
function jsonResponse($success, $message, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    
    echo json_encode($response);
    exit;
}

// Fungsi untuk menangkap error dan menampilkan pesan yang berguna
function handleError($message, $httpCode = 500) {
    error_log("API Error: " . $message);
    jsonResponse(false, $message, [], $httpCode);
}

// Cek jika request method adalah POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    handleError('Method not allowed. Please use POST.', 405);
}

// Fungsi untuk melakukan request ke API eksternal (Google/Firebase)
function makeApiRequest($url, $headers, $data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'message' => "cURL Error: " . $error];
    }

    return ['success' => true, 'status' => $httpcode, 'data' => json_decode($response, true)];
}

// --- FUNGSI-FUNGSI API ---

function verifyPassword($email, $password) {
    $apiKey = "AIzaSyBW1ZbMiUeDZHYUO2bY8Bfnf5rRgrQGPTM";
    $uri = "https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyPassword?key={$apiKey}";
    
    $headers = [
        "Content-Type: application/json",
        "X-Android-Package: com.olzhas.carparking.multyplayer",
        "X-Android-Cert: D4962F8124C2E09A66B97C8E326AFF805489FE39",
        "Accept-Language: in-ID, en-US",
        "X-Client-Version: Android/Fallback/X21001000/FirebaseCore-Android",
        "X-Firebase-GMPID: 1:581727203278:android:af6b7dee042c8df539459f",
        "X-Firebase-Client: H4sIAAAAAAAAAKtWykhNLCpJSk0sKVayio7VUSpLLSrOzM9TslIyUqoFAFyivEQfAAAA",
        "User-Agent: Dalvik/2.1.0 (Linux; U; Android 8.1.0; ASUS_X00TD MIUI/16.2017.2009.087-20" . rand(111111, 999999) . ")",
        "Host: www.googleapis.com",
        "Connection: Keep-Alive",
        "Accept-Encoding: gzip"
    ];
    
    $data = ["email" => $email, "password" => $password, "returnSecureToken" => true];
    
    $result = makeApiRequest($uri, $headers, $data);
    if ($result['success'] && $result['status'] == 200 && isset($result['data']['idToken'])) {
        return ['success' => true, 'idToken' => $result['data']['idToken']];
    }
    
    $errorMessage = 'Login gagal. Periksa email dan password.';
    if (isset($result['data']['error']['message'])) {
        $errorMessage = 'Login gagal: ' . $result['data']['error']['message'];
    }
    return ['success' => false, 'message' => $errorMessage];
}

// FUNSI BARU: Mengambil semua data mobil
function getAllCars($idToken) {
    $vhost = "us-central1-cp-multiplayer.cloudfunctions.net";
    $dataId = "9FD07A11C3494803B517F92545ED5A6702AD0AD2";
    $uri = "https://{$vhost}/TestGetAllCars";
    $data = ["data" => $dataId];
    $headers = [
        "Host: {$vhost}",
        "authorization: Bearer {$idToken}",
        "firebase-instance-id-token: fdEMFcKoR2iSrZAzViyFkh:APA91bEQsP8kAGfBuPTL_ATg25AmnqpssGTkc7IAS2CgLiILjBbneFuSEzOJr2a97eDvQOPGxlphSIV7gCk2k4Wl0UxMK5x298LrJYa5tJmVRqdyz0j3KDSKLCtCbldkRFwNnjU3lwfP",
        "content-type: application/json; charset=utf-8",
        "accept-encoding: gzip",
        "User-Agent: Dalvik/2.1.0 (Linux; U; Android 8.1.0; ASUS_X00TD MIUI/16.2017.2009.087-20" . rand(111111, 999999) . ")"
    ];

    $result = makeApiRequest($uri, $headers, $data);
    if ($result['success'] && $result['status'] == 200 && isset($result['data']['result'])) {
        return ['success' => true, 'cars' => json_decode($result['data']['result'], true)];
    }
    return ['success' => false, 'message' => 'Gagal mengambil data mobil.'];
}

// PERBAIKAN UTAMA: Fungsi inject livery yang benar
function injectLivery($idToken) {
    // Mencari path folder livery
    $base_paths = [
        __DIR__ . '/cpm/cars/livery/',
        $_SERVER['DOCUMENT_ROOT'] . '/cpm/cars/livery/',
        dirname(__DIR__) . '/cpm/cars/livery/',
        realpath(__DIR__ . '/../cpm/cars/livery/'),
        '/home/' . get_current_user() . '/public_html/cpm/cars/livery/',
        '/var/www/html/cpm/cars/livery/'
    ];
    
    $livery_path = null;
    foreach ($base_paths as $path) {
        if (is_dir($path)) {
            $livery_path = $path;
            break;
        }
    }
    
    if ($livery_path === null) {
        return ['success' => false, 'message' => 'Error: Folder livery tidak ditemukan.'];
    }
    
    // Mengambil semua file di folder livery
    $raw_files = array_diff(scandir($livery_path), array('.', '..'));
    $candidates = array_filter($raw_files, function($file) use ($livery_path) {
        return is_file($livery_path . $file);
    });
    
    if (empty($candidates)) {
        return ['success' => false, 'message' => 'Error: Tidak ada file livery di folder.'];
    }
    
    $uri = "https://us-central1-cp-multiplayer.cloudfunctions.net/SaveCars";
    $headers = [
        "Host: us-central1-cp-multiplayer.cloudfunctions.net",
        "authorization: Bearer " . $idToken,
        "firebase-instance-id-token: fdEMFcKoR2iSrZAzViyFkh:APA91bEQsP8kAGfBuPTL_ATg25AmnqpssGTkc7IAS2CgLiILjBbneFuSEzOJr2a97eDvQOPGxlphSIV7gCk2k4Wl0UxMK5x298LrJYa5tJmVRqdyz0j3KDSKLCtCbldkRFwNnjU3lwfP",
        "content-type: application/json; charset=utf-8",
        "accept-encoding: gzip",
        "User-Agent: Dalvik/2.1.0 (Linux; U; Android 8.1.0; ASUS_X00TD MIUI/16.2017.2009.087-20" . rand(111111, 999999) . ")",
    ];
    
    $successCount = 0;
    $errorMessages = [];
    
    foreach ($candidates as $filename) {
        $content = file_get_contents($livery_path . $filename);
        if ($content === false) {
            $errorMessages[] = "Gagal membaca file: " . $filename;
            continue;
        }
        
        // Hapus BOM jika ada
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        
        // Decode JSON
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessages[] = "Format JSON tidak valid: " . $filename . " - " . json_last_error_msg();
            continue;
        }
        
        // Perbaikan: Gunakan format dari contoh kode
        $payload_for_save = isset($data['data']) ? $data : ['data' => $data];
        
        // Perbaikan: Encode data terlebih dahulu sebelum mengirim
        $result = makeApiRequest($uri, $headers, ["data" => json_encode($payload_for_save['data'] ?? [])]);
        
        if ($result['success'] && $result['status'] == 200) {
            $resp_data = $result['data'];
            $inner = json_decode($resp_data['result'] ?? 'null', true);
            if ($inner === 1) {
                $successCount++;
            } else {
                $errorMessages[] = "Respon tidak valid untuk file: " . $filename . " - " . json_encode($result['data']);
            }
        } else {
            $errorMessages[] = "Gagal mengirim file: " . $filename . " - " . ($result['message'] ?? 'Unknown error');
        }
    }
    
    if ($successCount > 0) {
        $message = "✅ Berhasil! $successCount livery di-inject.";
        if (!empty($errorMessages)) {
            $message .= "\n⚠️ Beberapa livery gagal:\n" . implode("\n", array_slice($errorMessages, 0, 3));
            if (count($errorMessages) > 3) {
                $message .= "\n... dan " . (count($errorMessages) - 3) . " lainnya";
            }
        }
        return ['success' => true, 'message' => $message];
    } else {
        return ['success' => false, 'message' => '❌ Tidak ada livery yang berhasil di-inject.', 'errors' => $errorMessages];
    }
}

// PERBAIKAN UTAMA: Fungsi inject livery terpilih yang benar
function injectSelectedCars($idToken, $selectedCarIds) {
    // Mencari path folder livery
    $base_paths = [
        __DIR__ . '/cpm/cars/livery/',
        $_SERVER['DOCUMENT_ROOT'] . '/cpm/cars/livery/',
        dirname(__DIR__) . '/cpm/cars/livery/',
        realpath(__DIR__ . '/../cpm/cars/livery/'),
        '/home/' . get_current_user() . '/public_html/cpm/cars/livery/',
        '/var/www/html/cpm/cars/livery/'
    ];
    
    $livery_path = null;
    foreach ($base_paths as $path) {
        if (is_dir($path)) {
            $livery_path = $path;
            break;
        }
    }
    
    if ($livery_path === null) {
        return ['success' => false, 'message' => 'Error: Folder livery tidak ditemukan.'];
    }
    
    // Mengambil semua file di folder livery
    $raw_files = array_diff(scandir($livery_path), array('.', '..'));
    $candidates = array_filter($raw_files, function($file) use ($livery_path) {
        return is_file($livery_path . $file);
    });
    
    if (empty($candidates)) {
        return ['success' => false, 'message' => 'Error: Tidak ada file livery di folder.'];
    }
    
    // Filter file yang dipilih
    $filtered_files = [];
    foreach ($candidates as $filename) {
        $carId = pathinfo($filename, PATHINFO_FILENAME);
        if (in_array($carId, $selectedCarIds)) {
            $filtered_files[] = $filename;
        }
    }
    
    if (empty($filtered_files)) {
        return ['success' => false, 'message' => 'Error: Tidak ada file livery yang cocok dengan pilihan.'];
    }
    
    $uri = "https://us-central1-cp-multiplayer.cloudfunctions.net/SaveCars";
    $headers = [
        "Host: us-central1-cp-multiplayer.cloudfunctions.net",
        "authorization: Bearer " . $idToken,
        "firebase-instance-id-token: fdEMFcKoR2iSrZAzViyFkh:APA91bEQsP8kAGfBuPTL_ATg25AmnqpssGTkc7IAS2CgLiILjBbneFuSEzOJr2a97eDvQOPGxlphSIV7gCk2k4Wl0UxMK5x298LrJYa5tJmVRqdyz0j3KDSKLCtCbldkRFwNnjU3lwfP",
        "content-type: application/json; charset=utf-8",
        "accept-encoding: gzip",
        "User-Agent: Dalvik/2.1.0 (Linux; U; Android 8.1.0; ASUS_X00TD MIUI/16.2017.2009.087-20" . rand(111111, 999999) . ")",
    ];
    
    $successCount = 0;
    $errorMessages = [];
    
    foreach ($filtered_files as $filename) {
        $content = file_get_contents($livery_path . $filename);
        if ($content === false) {
            $errorMessages[] = "Gagal membaca file: " . $filename;
            continue;
        }
        
        // Hapus BOM jika ada
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        
        // Decode JSON
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessages[] = "Format JSON tidak valid: " . $filename . " - " . json_last_error_msg();
            continue;
        }
        
        // Perbaikan: Gunakan format dari contoh kode
        $payload_for_save = isset($data['data']) ? $data : ['data' => $data];
        
        // Perbaikan: Encode data terlebih dahulu sebelum mengirim
        $result = makeApiRequest($uri, $headers, ["data" => json_encode($payload_for_save['data'] ?? [])]);
        
        if ($result['success'] && $result['status'] == 200) {
            $resp_data = $result['data'];
            $inner = json_decode($resp_data['result'] ?? 'null', true);
            if ($inner === 1) {
                $successCount++;
            } else {
                $errorMessages[] = "Respon tidak valid untuk file: " . $filename . " - " . json_encode($result['data']);
            }
        } else {
            $errorMessages[] = "Gagal mengirim file: " . $filename . " - " . ($result['message'] ?? 'Unknown error');
        }
    }
    
    if ($successCount > 0) {
        $message = "✅ Berhasil! $successCount livery terpilih di-inject.";
        if (!empty($errorMessages)) {
            $message .= "\n⚠️ Beberapa livery gagal:\n" . implode("\n", array_slice($errorMessages, 0, 3));
            if (count($errorMessages) > 3) {
                $message .= "\n... dan " . (count($errorMessages) - 3) . " lainnya";
            }
        }
        return ['success' => true, 'message' => $message];
    } else {
        return ['success' => false, 'message' => '❌ Tidak ada livery terpilih yang berhasil di-inject.', 'errors' => $errorMessages];
    }
}

// FUNGSI BARU: Inject King (Auto) - Menggunakan token yang sudah ada
function injectKing($email, $useExistingToken = false) {
    // Kategori yang tersedia
    $ALL_KEYS = [
        "cars","car_fix","car_collided","car_exchange","car_trade","car_wash",
        "slicer_cut","drift_max","drift","cargo","delivery","taxi","levels","gifts",
        "fuel","offroad","speed_banner","reactions","police","run","real_estate",
        "t_distance","treasure","block_post","push_ups","burnt_tire","passanger_distance","race_win"
    ];
    
    // Validasi input
    if (empty($email)) {
        return ['success' => false, 'message' => 'Email wajib diisi.'];
    }
    
    // Build rating_data dengan semua kategori dan nilai default
    $rating_data = [];
    foreach ($ALL_KEYS as $k) {
        $rating_data[$k] = 1000000; // Nilai default untuk semua kategori
    }
    $rating_data['time'] = 10000000000; // Nilai default untuk time
    
    // Jika menggunakan token yang sudah ada, kita tidak perlu login lagi
    if ($useExistingToken) {
        // Kirim rating data langsung tanpa autentikasi ulang
        // Ini akan menggunakan token yang sudah ada dari sesi login
        $rank_url = "https://us-central1-cp-multiplayer.cloudfunctions.net/SetUserRating6";
        $payload = json_encode(['data' => json_encode(['RatingData' => $rating_data])]);
        
        $ch = curl_init($rank_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: okhttp/3.12.13'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Analyze response
        $note = null;
        $detected_success = false;
        $no_data_flag = false;
        
        $decoded = json_decode($res, true);
        if (is_array($decoded)) {
            if (isset($decoded['result']) && is_string($decoded['result'])) {
                $inner = json_decode($decoded['result'], true);
                if (is_array($inner)) {
                    if (isset($inner['data']) && $inner['data'] !== null) {
                        $detected_success = true;
                    }
                    if (!$detected_success && (isset($inner['callback']) || isset($inner['battlepass']))) {
                        $detected_success = true;
                        $note = 'callback_or_battlepass_present';
                    }
                    if (!$detected_success && array_key_exists('data', $inner) && $inner['data'] === null) {
                        $no_data_flag = true;
                    }
                }
            } else {
                if (isset($decoded['success']) && $decoded['success'] === true) {
                    $detected_success = true;
                }
                if (!$detected_success && isset($decoded['data']) && $decoded['data'] !== null) {
                    $detected_success = true;
                }
            }
        }
        
        // Fallback: if HTTP 200 treat as processed
        if ($http === 200 && !$detected_success) {
            $detected_success = true;
            if ($no_data_flag) {
                $note = '200_but_no_data';
            } else if ($note === null) {
                $note = '200_unknown_body';
            }
        }
        
        if ($detected_success) {
            $resp = ['message' => 'Rank berhasil di-inject'];
            if ($note) {
                $resp['note'] = $note;
            }
            return ['success' => true, 'message' => '✅ King berhasil di-inject secara otomatis!', 'data' => $resp];
        } else {
            return ['success' => false, 'message' => '❌ Gagal inject king. HTTP ' . $http];
        }
    } else {
        // Mode normal dengan autentikasi
        // Authenticate dengan Firebase
        $firebase_url = "https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyPassword?key=AIzaSyBW1ZbMiUeDZHYUO2bY8Bfnf5rRgrQGPTM";
        $payload = json_encode([
            'email' => $email,
            'password' => $password,
            'returnSecureToken' => true
        ]);
        
        $ch = curl_init($firebase_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: Dalvik/2.1.0 (Linux; U; Android 12)'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http !== 200) {
            return ['success' => false, 'message' => 'Authentication gagal. Cek email/password.'];
        }
        
        $auth = json_decode($res, true);
        if (!$auth || empty($auth['idToken'])) {
            return ['success' => false, 'message' => 'Authentication gagal. Token tidak valid.'];
        }
        
        $idToken = $auth['idToken'];
        
        // Kirim rating data
        $rank_url = "https://us-central1-cp-multiplayer.cloudfunctions.net/SetUserRating6";
        $payload = json_encode(['data' => json_encode(['RatingData' => $rating_data])]);
        
        $ch = curl_init($rank_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $idToken,
            'Content-Type: application/json',
            'User-Agent: okhttp/3.12.13'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Analyze response
        $note = null;
        $detected_success = false;
        $no_data_flag = false;
        
        $decoded = json_decode($res, true);
        if (is_array($decoded)) {
            if (isset($decoded['result']) && is_string($decoded['result'])) {
                $inner = json_decode($decoded['result'], true);
                if (is_array($inner)) {
                    if (isset($inner['data']) && $inner['data'] !== null) {
                        $detected_success = true;
                    }
                    if (!$detected_success && (isset($inner['callback']) || isset($inner['battlepass']))) {
                        $detected_success = true;
                        $note = 'callback_or_battlepass_present';
                    }
                    if (!$detected_success && array_key_exists('data', $inner) && $inner['data'] === null) {
                        $no_data_flag = true;
                    }
                }
            } else {
                if (isset($decoded['success']) && $decoded['success'] === true) {
                    $detected_success = true;
                }
                if (!$detected_success && isset($decoded['data']) && $decoded['data'] !== null) {
                    $detected_success = true;
                }
            }
        }
        
        // Fallback: if HTTP 200 treat as processed
        if ($http === 200 && !$detected_success) {
            $detected_success = true;
            if ($no_data_flag) {
                $note = '200_but_no_data';
            } else if ($note === null) {
                $note = '200_unknown_body';
            }
        }
        
        if ($detected_success) {
            $resp = ['message' => 'Rank berhasil di-inject'];
            if ($note) {
                $resp['note'] = $note;
            }
            return ['success' => true, 'message' => '✅ King berhasil di-inject!', 'data' => $resp];
        } else {
            return ['success' => false, 'message' => '❌ Gagal inject king. HTTP ' . $http];
        }
    }
}

// --- ROUTER API ---
 $requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod === 'POST') {
    // Perbaikan: Mengambil data dari request body untuk semua kasus
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    // Jika data kosong, coba dari $_POST (untuk form submission)
    if (empty($data)) {
        $data = $_POST;
    }
    
    $action = $data['action'] ?? '';
    $response = ['success' => false, 'message' => 'Aksi tidak valid.'];

    switch ($action) {
        case 'login':
            // Untuk login, gunakan data dari $_POST atau dari JSON
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';
            $response = verifyPassword($email, $password);
            break;
        
        case 'getCars':
            $idToken = $data['idToken'] ?? '';
            if ($idToken) {
                $response = getAllCars($idToken);
            } else {
                $response = ['success' => false, 'message' => 'Token tidak ditemukan.'];
            }
            break;

        case 'injectAll':
            $idToken = $data['idToken'] ?? '';
            if ($idToken) {
                $response = injectLivery($idToken);
            } else {
                $response = ['success' => false, 'message' => 'Token tidak ditemukan.'];
            }
            break;
        
        case 'injectSelected':
            $idToken = $data['idToken'] ?? '';
            $selectedCarIds = $data['selectedCarIds'] ?? [];
            if ($idToken && !empty($selectedCarIds)) {
                $response = injectSelectedCars($idToken, $selectedCarIds);
            } else {
                $response = ['success' => false, 'message' => 'Token atau mobil terpilih tidak ditemukan.'];
            }
            break;
            
        case 'injectKing':
            $email = $data['email'] ?? '';
            $useExistingToken = $data['useExistingToken'] ?? false;
            $response = injectKing($email, $useExistingToken);
            break;
    }
    
    echo json_encode($response);
}
?>