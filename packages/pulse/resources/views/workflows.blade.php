<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Laraflow" details="past {{ $this->periodForHumans() }}">
        <x-slot:icon>
            <x-pulse::icons.queue-list />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        <div class="grid grid-cols-3 gap-3 text-center text-xs mb-4">
            <div><div class="text-xl font-bold">{{ (int) $now['unfinished'] }}</div>unfinished</div>
            <div><div class="text-xl font-bold">{{ (int) ($now['laraflow_retries_due'] + $now['laraflow_waits_due']) }}</div>due now</div>
            <div><div class="text-xl font-bold">{{ (int) $now['laraflow_executions_deferred'] }}</div>deferred</div>
            <div><div class="text-xl font-bold">{{ (int) $now['laraflow_steps_overdue'] }}</div>steps overdue</div>
            <div><div class="text-xl font-bold">{{ (int) $now['laraflow_schedules_stalled'] }}</div>schedules stalled</div>
        </div>

        @if ($finished->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Workflow</x-pulse::th>
                        <x-pulse::th class="text-right">Completed</x-pulse::th>
                        <x-pulse::th class="text-right">Failed</x-pulse::th>
                        <x-pulse::th class="text-right">Cancelled</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($finished as $workflow => $counts)
                        <tr wire:key="{{ $workflow }}">
                            <x-pulse::td class="truncate" title="{{ $workflow }}">{{ class_basename($workflow) }}</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($counts['completed']) }}</x-pulse::td>
                            <x-pulse::td numeric class="{{ $counts['failed'] > 0 ? 'text-red-600' : '' }}">{{ number_format($counts['failed']) }}</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($counts['cancelled']) }}</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif

        @if ($slowest->isNotEmpty())
            <x-pulse::table class="mt-4">
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Slowest step</x-pulse::th>
                        <x-pulse::th class="text-right">Max</x-pulse::th>
                        <x-pulse::th class="text-right">Avg</x-pulse::th>
                        <x-pulse::th class="text-right">Attempts</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($slowest as $row)
                        <tr wire:key="{{ $row['workflow'] }}-{{ $row['step'] }}">
                            <x-pulse::td class="truncate" title="{{ $row['workflow'] }} → {{ $row['step'] }}">{{ class_basename($row['step']) }}</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($row['max']) }} ms</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($row['avg']) }} ms</x-pulse::td>
                            <x-pulse::td numeric>{{ number_format($row['count']) }}</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
