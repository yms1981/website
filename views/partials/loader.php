<?php
$loaderText = e($dict['common']['loading'] ?? 'Loading…');
?>
<div id="hv-global-loader" class="hv-global-loader" aria-hidden="true" aria-busy="false">
  <div class="hv-global-loader__panel">
    <div class="hv-global-loader__spinner" aria-hidden="true"></div>
    <p class="hv-global-loader__text"><span data-hv-loader-label><?= $loaderText ?></span></p>
  </div>
</div>
