{{-- resources/views/filament/market-spaces/group-composition.blade.php --}}

@if($hasChildren)
    <div class="fi-ta">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Слот</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Номер</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Название</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Арендатор</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Статус</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Действие</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($children as $child)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-900">
                                {{ $child['slot'] }}
                            </td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                {{ $child['number'] }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">
                                {{ $child['display_name'] }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">
                                {{ $child['tenant_name'] }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <x-filament::badge :color="$child['status_color']">
                                    {{ $child['status_label'] }}
                                </x-filament::badge>
                            </td>
                            <td class="px-4 py-3 text-sm text-right">
                                <a href="{{ $child['edit_url'] }}"
                                   target="_blank"
                                   rel="noopener"
                                   class="inline-flex items-center px-3 py-1 text-sm font-medium text-white bg-primary-600 rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                    Открыть
                                    <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                    </svg>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@else
    <div class="text-center py-8 text-gray-500">
        В группе пока нет дочерних мест.
    </div>
@endif
