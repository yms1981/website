<?php $bp = base_url(); ?>
<footer class="bg-gray-950 border-t border-gray-200 mt-auto">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
      <div>
        <a href="<?= e($bp) ?>/<?= e($lang) ?>" class="text-xl font-bold text-white"><?= e($dict['nav']['home']) ?></a>
        <p class="text-xs text-gray-500 mt-0.5">Home Value LLC</p>
        <p class="text-sm text-gray-400 max-w-xs mt-2"><?= e($dict['home']['hero_subtitle']) ?></p>
      </div>
      <div>
        <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-[0.2em] mb-4"><?= e($dict['footer']['links']) ?></h4>
        <ul class="space-y-2">
          <li><a href="<?= e($bp) ?>/<?= e($lang) ?>" class="text-sm text-gray-500 hover:text-white transition"><?= e($dict['nav']['home']) ?></a></li>
          <li><a href="<?= e($bp) ?>/<?= e($lang) ?>/contact" class="text-sm text-gray-500 hover:text-white transition"><?= e($dict['nav']['contact']) ?></a></li>
          <li><a href="<?= e($bp) ?>/<?= e($lang) ?>/register" class="text-sm text-gray-500 hover:text-white transition"><?= e($dict['nav']['register']) ?></a></li>
        </ul>
      </div>
      <div>
        <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-[0.2em] mb-4"><?= e($dict['footer']['contact']) ?></h4>
        <ul class="space-y-3 text-sm text-gray-500">
          <li>📞 773.681.2440</li>
          <li>✉️ info@homevalue.com</li>
          <li>525 W University Dr, Arlington Heights, IL 60004</li>
        </ul>
      </div>
      <div>
        <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-[0.2em] mb-4"><?= e($dict['footer']['social']) ?></h4>
        <p class="text-sm text-gray-500"><?= e($dict['footer']['rights']) ?></p>
      </div>
    </div>
    <div class="border-t border-gray-700 mt-8 pt-6">
      <p class="text-xs text-gray-600 text-center">© <?= date('Y') ?> Home Value. <?= e($dict['footer']['rights']) ?></p>
    </div>
  </div>
</footer>
