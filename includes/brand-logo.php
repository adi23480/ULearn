<?php
// All site logos use this single image. Replace assets/images/ulearn-logo.png.
$ulearnLogoFile = __DIR__ . '/../assets/images/ulearn-logo.png';
$ulearnLogoVersion = is_file($ulearnLogoFile) ? (string) filemtime($ulearnLogoFile) : '1';
?>
<div class="ulearn-shared-brand" role="img" aria-label="ULearn" style="display:block!important;position:relative!important;width:180px!important;max-width:100%!important;height:48px!important;flex-shrink:0!important;overflow:hidden!important;border-radius:10px!important;background:#03131f!important;margin:0 0 22px!important;padding:0!important;cursor:default!important;line-height:0!important">
<img src="<?= e(url('assets/images/ulearn-logo.png')) ?>?v=<?= e($ulearnLogoVersion) ?>" alt="" aria-hidden="true" decoding="async" style="display:block!important;position:absolute!important;left:50%!important;top:50%!important;width:200%!important;max-width:none!important;height:auto!important;max-height:none!important;transform:translate(-50%,-50%)!important;object-fit:contain!important;border:0!important;border-radius:0!important;margin:0!important;padding:0!important;filter:none!important">
</div>
