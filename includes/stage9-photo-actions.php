<?php
// Include inside an existing authenticated, CSRF-checked POST action switch.
if ($action === 'upload_profile_photo') {
    if (!isset($_FILES['profile_photo']) || !is_array($_FILES['profile_photo'])) throw new RuntimeException('Choose a photo to upload.');
    $file = $_FILES['profile_photo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Please try again.');
    if (!is_uploaded_file((string)$file['tmp_name']) || (int)$file['size'] > 3145728 || (int)$file['size'] < 100) throw new RuntimeException('Choose a valid image under 3 MB.');
    $info = @getimagesize((string)$file['tmp_name']);
    if (!$info || $info[0] < 64 || $info[1] < 64 || $info[0] > 5000 || $info[1] > 5000) throw new RuntimeException('Image dimensions must be between 64 and 5000 pixels.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($extensions[$mime]) || $mime !== ($info['mime'] ?? '')) throw new RuntimeException('Only JPG, PNG and WebP images are allowed.');
    $folder = dirname(__DIR__).'/assets/uploads/profile-photos';
    if (!is_dir($folder) && !mkdir($folder,0755,true) && !is_dir($folder)) throw new RuntimeException('Unable to create photo storage.');
    $filename = bin2hex(random_bytes(16)).'.'.$extensions[$mime];
    $relative = 'assets/uploads/profile-photos/'.$filename;
    if (!move_uploaded_file((string)$file['tmp_name'],$folder.'/'.$filename)) throw new RuntimeException('Unable to save uploaded photo.');
    try {
        $pdo->prepare('INSERT INTO user_profile_photos(user_id,image_path) VALUES (?,?) ON DUPLICATE KEY UPDATE image_path=VALUES(image_path)')->execute([$userId,$relative]);
    } catch (Throwable $ex) { @unlink($folder.'/'.$filename); throw $ex; }
    $notice = 'Your profile photo has been updated.';
} elseif ($action === 'remove_profile_photo') {
    $pdo->prepare('DELETE FROM user_profile_photos WHERE user_id=?')->execute([$userId]);
    $notice = 'Your profile photo has been removed.';
}
