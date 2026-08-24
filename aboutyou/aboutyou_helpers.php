<?php
/**
 * aboutyou Helper Functions
 */

function formatDate($date_string) {
    $date = new DateTime($date_string);
    return $date->format("M d, Y");
}

function formatDateTime($datetime_string) {
    $date = new DateTime($datetime_string);
    return $date->format("M d, Y H:i");
}

function getTimeAgo($date_string) {
    $date = new DateTime($date_string);
    $now = new DateTime();
    $interval = $now->diff($date);
    
    if ($interval->days > 365) {
        return $interval->format("%y year" . ($interval->y > 1 ? "s" : "") . " ago");
    } elseif ($interval->days > 30) {
        return $interval->format("%m month" . ($interval->m > 1 ? "s" : "") . " ago");
    } elseif ($interval->days > 0) {
        return $interval->format("%d day" . ($interval->d > 1 ? "s" : "") . " ago");
    } elseif ($interval->h > 0) {
        return $interval->format("%h hour" . ($interval->h > 1 ? "s" : "") . " ago");
    } elseif ($interval->i > 0) {
        return $interval->format("%i minute" . ($interval->i > 1 ? "s" : "") . " ago");
    } else {
        return "just now";
    }
}

function isFutureDate($date_string) {
    $date = new DateTime($date_string);
    $now = new DateTime();
    return $date > $now;
}

function getDaysUntil($date_string) {
    $date = new DateTime($date_string);
    $now = new DateTime();
    $interval = $now->diff($date);
    return $interval->days;
}

function sanitizeFileName($filename) {
    return preg_replace("/[^a-zA-Z0-9._-]/", "_", $filename);
}

function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * ★ Check if file is an image (including HEIF/HEIC)
 */
function isImageFile($filename) {
    $image_extensions = ["jpg", "jpeg", "png", "gif", "webp", "bmp", "heic", "heif"];
    $ext = getFileExtension($filename);
    return in_array($ext, $image_extensions);
}

/**
 * ★ Check if file is HEIF/HEIC format
 */
function isHeifFile($filename) {
    $ext = getFileExtension($filename);
    return in_array($ext, ['heic', 'heif']);
}

function isVideoFile($filename) {
    $video_extensions = ["mp4", "webm", "avi", "mov", "mkv", "flv", "wmv"];
    $ext = getFileExtension($filename);
    return in_array($ext, $video_extensions);
}

function getMemoryIcon($type) {
    $icons = ["photo"=>"📷","video"=>"🎥","note"=>"📝","milestone"=>"🎉","letter"=>"💌","audio"=>"🎵"];
    return isset($icons[$type]) ? $icons[$type] : "📌";
}

function getMemoryTypeLabel($type) {
    $labels = ["photo"=>"Photo","video"=>"Video","note"=>"Note","milestone"=>"Milestone","letter"=>"Letter","audio"=>"Audio"];
    return isset($labels[$type]) ? $labels[$type] : ucfirst($type);
}

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function generateUniqueId() {
    return uniqid("th_", true);
}

function getCapsuleStatusColor($status) {
    $colors = ["pending"=>"#FFA500","delivered"=>"#4CAF50","opened"=>"#2196F3","expired"=>"#F44336"];
    return isset($colors[$status]) ? $colors[$status] : "#999999";
}

function getCapsuleStatusLabel($status) {
    $labels = ["pending"=>"Pending","delivered"=>"Delivered","opened"=>"Opened","expired"=>"Expired"];
    return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
}

function getCapsuleProgress($created_date, $delivery_date) {
    $created = new DateTime($created_date);
    $delivery = new DateTime($delivery_date);
    $now = new DateTime();
    $total_interval = $created->diff($delivery);
    $elapsed_interval = $created->diff($now);
    if ($total_interval->days == 0) return 100;
    $progress = ($elapsed_interval->days / $total_interval->days) * 100;
    return min(100, max(0, $progress));
}

function truncateText($text, $length = 100, $suffix = "...") {
    if (strlen($text) > $length) return substr($text, 0, $length) . $suffix;
    return $text;
}

function getMemoryCount($link, $capsule_id, $user_id) {
    $sql = "SELECT COUNT(*) as count FROM tbl_memories WHERE capsule_id = ? AND user_id = ?";
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $capsule_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        return $row["count"];
    }
    return 0;
}

function getMilestoneCount($link, $user_id) {
    $sql = "SELECT COUNT(*) as count FROM tbl_milestones WHERE user_id = ?";
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        return $row["count"];
    }
    return 0;
}

/**
 * ★★★ Convert HEIF/HEIC to JPEG using ImageMagick ★★★
 * 
 * @param string $source_path Path to HEIF/HEIC file
 * @param string $target_path Path for output JPEG
 * @param int $quality JPEG quality (1-100)
 * @return bool Success or failure
 */
function convertHeifToJpeg($source_path, $target_path, $quality = 85) {
    error_log("convertHeifToJpeg: Attempting conversion");
    error_log("  Source: " . $source_path);
    error_log("  Target: " . $target_path);
    error_log("  Source exists: " . (file_exists($source_path) ? 'YES' : 'NO'));
    error_log("  Source size: " . filesize($source_path));
    
    // Method 1: Try ImageMagick `convert` command
    $convert_path = trim(shell_exec('which convert 2>/dev/null') ?? '');
    if (!empty($convert_path)) {
        error_log("  ImageMagick found at: " . $convert_path);
        $cmd = escapeshellcmd($convert_path) . " " 
             . escapeshellarg($source_path) . " "
             . "-quality " . intval($quality) . " "
             . escapeshellarg($target_path) . " 2>&1";
        
        error_log("  Command: " . $cmd);
        $output = shell_exec($cmd);
        error_log("  Output: " . ($output ?? 'null'));
        
        if (file_exists($target_path) && filesize($target_path) > 0) {
            error_log("  ✓ Conversion successful! Output size: " . filesize($target_path));
            return true;
        }
        error_log("  ✗ convert command failed");
    } else {
        error_log("  ImageMagick 'convert' not found");
    }
    
    // Method 2: Try PHP Imagick extension
    if (extension_loaded('imagick')) {
        error_log("  Trying PHP Imagick extension");
        try {
            $imagick = new Imagick($source_path);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality($quality);
            $imagick->writeImage($target_path);
            $imagick->clear();
            $imagick->destroy();
            
            if (file_exists($target_path) && filesize($target_path) > 0) {
                error_log("  ✓ Imagick conversion successful!");
                return true;
            }
        } catch (Exception $e) {
            error_log("  ✗ Imagick error: " . $e->getMessage());
        }
    } else {
        error_log("  PHP Imagick extension not loaded");
    }
    
    // Method 3: Try `heif-convert` or `heif-dec` (libheif tools)
    $heif_convert = trim(shell_exec('which heif-convert 2>/dev/null') ?? '');
    if (empty($heif_convert)) {
        $heif_convert = trim(shell_exec('which heif-dec 2>/dev/null') ?? '');
    }
    if (!empty($heif_convert)) {
        error_log("  Trying heif-convert at: " . $heif_convert);
        $cmd = escapeshellcmd($heif_convert) . " " 
             . escapeshellarg($source_path) . " "
             . escapeshellarg($target_path) . " 2>&1";
        $output = shell_exec($cmd);
        if (file_exists($target_path) && filesize($target_path) > 0) {
            error_log("  ✓ heif-convert successful!");
            return true;
        }
    }
    
    error_log("  ✗ All HEIF conversion methods failed");
    return false;
}

/**
 * ★★★ Create thumbnail - Updated with HEIF support ★★★
 */
function createThumbnail($source_path, $thumb_path, $width = 150, $height = 150) {
    error_log("createThumbnail: Processing " . $source_path);
    
    // If HEIF/HEIC, convert to JPEG first
    if (isHeifFile($source_path)) {
        error_log("  HEIF file detected, converting to JPEG first");
        $temp_jpg = dirname($source_path) . "/temp_heif_" . uniqid() . ".jpg";
        if (convertHeifToJpeg($source_path, $temp_jpg)) {
            $source_to_use = $temp_jpg;
            error_log("  Using converted JPEG for thumbnail");
        } else {
            error_log("  HEIF conversion failed, thumbnail not possible");
            return false;
        }
    } else {
        $source_to_use = $source_path;
    }
    
    if (!function_exists('imagecreatetruecolor')) {
        error_log("  GD Library not available");
        if (isset($temp_jpg) && file_exists($temp_jpg)) @unlink($temp_jpg);
        return false;
    }

    $ext = getFileExtension($source_to_use);
    $image = null;
    
    switch ($ext) {
        case "jpg": case "jpeg":
            if (function_exists('imagecreatefromjpeg')) $image = @imagecreatefromjpeg($source_to_use);
            break;
        case "png":
            if (function_exists('imagecreatefrompng')) $image = @imagecreatefrompng($source_to_use);
            break;
        case "gif":
            if (function_exists('imagecreatefromgif')) $image = @imagecreatefromgif($source_to_use);
            break;
        case "webp":
            if (function_exists('imagecreatefromwebp')) $image = @imagecreatefromwebp($source_to_use);
            break;
    }
    
    // Clean up temp file
    if (isset($temp_jpg) && file_exists($temp_jpg)) @unlink($temp_jpg);
    
    if (!$image) {
        error_log("  Failed to create image resource");
        return false;
    }
    
    $orig_width = imagesx($image);
    $orig_height = imagesy($image);
    
    $ratio = min($width / $orig_width, $height / $orig_height);
    $new_width = round($orig_width * $ratio);
    $new_height = round($orig_height * $ratio);
    
    $thumb = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefill($thumb, 0, 0, $white);
    
    $x = round(($width - $new_width) / 2);
    $y = round(($height - $new_height) / 2);
    
    imagecopyresampled($thumb, $image, $x, $y, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
    
    $success = @imagejpeg($thumb, $thumb_path, 80);
    imagedestroy($image);
    imagedestroy($thumb);
    
    error_log("  Thumbnail " . ($success ? 'created' : 'failed'));
    return $success;
}

/**
 * ★ Get capture date - Updated for HEIF files
 */
function getMediaCaptureDate($file_path, $client_date = null) {
    $fallback_date = date("Y-m-d", filemtime($file_path));
    
    // Use client-provided date if available
    if ($client_date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $client_date)) {
        error_log("getMediaCaptureDate: Using client date: " . $client_date);
        return $client_date;
    }
    
    error_log("getMediaCaptureDate: Processing " . $file_path);
    
    if (!file_exists($file_path)) {
        error_log("  File not found");
        return $fallback_date;
    }
    
    // HEIF files - try EXIF, fall back to filemtime
    if (isHeifFile($file_path)) {
        error_log("  HEIF file detected");
        if (function_exists("exif_read_data")) {
            $exif = @exif_read_data($file_path);
            if ($exif !== false && is_array($exif)) {
                if (!empty($exif['DateTimeOriginal'])) {
                    $ts = strtotime($exif['DateTimeOriginal']);
                    if ($ts && $ts > 0) {
                        $date = date("Y-m-d", $ts);
                        error_log("  HEIF EXIF DateTimeOriginal: " . $date);
                        return $date;
                    }
                }
                if (!empty($exif['DateTime'])) {
                    $ts = strtotime($exif['DateTime']);
                    if ($ts && $ts > 0) {
                        $date = date("Y-m-d", $ts);
                        error_log("  HEIF EXIF DateTime: " . $date);
                        return $date;
                    }
                }
            }
        }
        error_log("  Using filemtime for HEIF: " . $fallback_date);
        return $fallback_date;
    }
    
    // Regular images - try EXIF
    if (isImageFile($file_path) && function_exists("exif_read_data")) {
        $exif = @exif_read_data($file_path);
        if ($exif !== false && is_array($exif)) {
            if (!empty($exif['DateTimeOriginal'])) {
                $ts = strtotime($exif['DateTimeOriginal']);
                if ($ts && $ts > 0) return date("Y-m-d", $ts);
            }
            if (!empty($exif['DateTimeDigitized'])) {
                $ts = strtotime($exif['DateTimeDigitized']);
                if ($ts && $ts > 0) return date("Y-m-d", $ts);
            }
            if (!empty($exif['DateTime'])) {
                $ts = strtotime($exif['DateTime']);
                if ($ts && $ts > 0) return date("Y-m-d", $ts);
            }
        }
        
        // Sectioned read
        $exif2 = @exif_read_data($file_path, null, true);
        if ($exif2 !== false && is_array($exif2)) {
            if (!empty($exif2['EXIF']['DateTimeOriginal'])) {
                $ts = strtotime($exif2['EXIF']['DateTimeOriginal']);
                if ($ts && $ts > 0) return date("Y-m-d", $ts);
            }
            if (!empty($exif2['IFD0']['DateTime'])) {
                $ts = strtotime($exif2['IFD0']['DateTime']);
                if ($ts && $ts > 0) return date("Y-m-d", $ts);
            }
        }
    }
    
    // Binary fallback
    $binary_date = extractExifDateFromBinary($file_path);
    if ($binary_date !== null) return $binary_date;
    
    return $fallback_date;
}

function extractExifDateFromBinary($file_path) {
    $handle = fopen($file_path, 'rb');
    if (!$handle) return null;
    $data = fread($handle, 131072);
    fclose($handle);
    
    $pattern = '/DateTimeOriginal\x00.{4}(\d{4}):(\d{2}):(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/s';
    if (preg_match($pattern, $data, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
    
    $pattern2 = '/DateTimeDigitized\x00.{4}(\d{4}):(\d{2}):(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/s';
    if (preg_match($pattern2, $data, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
    
    return null;
}

function debugExifData($file_path) {
    echo "<pre>File: " . $file_path . "\n\n";
    if (!file_exists($file_path)) { echo "ERROR: File does not exist!\n"; return; }
    echo "Size: " . filesize($file_path) . " bytes\n";
    echo "MIME: " . mime_content_type($file_path) . "\n";
    echo "HEIF: " . (isHeifFile($file_path) ? 'YES' : 'NO') . "\n\n";
    
    if (function_exists("exif_read_data")) {
        echo "=== EXIF ===\n";
        $exif = @exif_read_data($file_path, null, true);
        if ($exif) print_r($exif);
        else echo "No EXIF data\n";
    }
    echo "</pre>";
}
?>
