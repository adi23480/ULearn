<?php
// Stage 9: user-uploaded profile image, with initials fallback.
$photoStmt = $pdo->prepare('SELECT image_path FROM user_profile_photos WHERE user_id=? LIMIT 1');
$photoStmt->execute([$userId]);
$photoPath = $photoStmt->fetchColumn();
$photoUrl = $photoPath ? url((string)$photoPath) : null;
?>
<section class="stage9-card profile-photo-card">
  <div class="photo-card-layout">
    <div class="photo-preview" id="photoPreview" aria-label="Current profile picture">
      <?php if ($photoUrl): ?><img src="<?= e($photoUrl) ?>" alt="Your profile picture"><?php else: ?><span><?= e(mb_strtoupper(mb_substr((string)$profile['full_name'],0,1))) ?></span><?php endif ?>
    </div>
    <div class="photo-content">
      <div class="photo-kicker">YOUR IDENTITY</div>
      <h2>Profile photo</h2>
      <p>Make your ULearn profile your own. Upload a clear JPG, PNG or WebP image (up to 3 MB).</p>
      <form method="post" enctype="multipart/form-data" class="photo-form">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="action" value="upload_profile_photo">
        <label class="photo-upload-button" for="profilePhotoInput">Choose photo</label>
        <input id="profilePhotoInput" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required>
        <button class="photo-save" type="submit">Save photo</button>
      </form>
      <?php if($photoUrl): ?><form method="post" class="photo-remove-form"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="remove_profile_photo"><button type="submit" class="photo-remove">Remove current photo</button></form><?php endif ?>
    </div>
  </div>
</section>
<link rel="stylesheet" href="<?= e(url('assets/css/stage9-profile-v3.css')) ?>">
<script>
const profilePhotoInput=document.getElementById('profilePhotoInput');
profilePhotoInput?.addEventListener('change',()=>{const file=profilePhotoInput.files?.[0];if(!file)return;if(file.size>3145728){alert('Please choose an image under 3 MB.');profilePhotoInput.value='';return;}const preview=document.getElementById('photoPreview');const objectUrl=URL.createObjectURL(file);const img=document.createElement('img');img.alt='Selected photo preview';img.onload=()=>URL.revokeObjectURL(objectUrl);img.src=objectUrl;preview.replaceChildren(img);});
</script>
