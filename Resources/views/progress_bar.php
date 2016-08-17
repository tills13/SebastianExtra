<div class="pbar <?=$class?>">
    <?php foreach ($segments as $segment) { ?>
        <div rel="popup" style="width: <?=$segment['progress']?>%;  background: <?=$segment['color']?>;" data-popup-message="<?=($segment['popup'] ?? "")?>">
            <span class="label"><?=$segment['label']?></span>
        </div>
    <?php } ?>
</div>