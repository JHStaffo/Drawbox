@php($s = $assignment->status())
<span @class(['pill', 'pill-green' => $s === 'completed', 'pill-red' => $s === 'overdue', 'pill-teal' => $s === 'in_progress'])>{{ $assignment->statusLabel() }}</span>
