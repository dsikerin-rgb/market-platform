@php
  $isMapLinked = isset($isMapLinked) ? (bool) $isMapLinked : false;
  $statusText = $statusText ?? ($isMapLinked ? 'Торговое место привязано к карте.' : 'Торговое место не привязано к объектам карты.');
@endphp

<div class="flex flex-wrap items-center gap-3 text-sm">
  <span class="font-medium text-gray-700">Статус на карте:</span>
  <span class="{{ $isMapLinked ? 'text-emerald-600' : 'text-rose-600' }}">
    {{ $statusText }}
  </span>
</div>
