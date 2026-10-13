{{--
    Button that asks for confirmation in a dialog, then calls a Livewire method.
    <x-confirm action="delete(5)" title="Delete bot?" message="This cannot be undone." class="btn btn-danger btn-sm">Delete</x-confirm>
--}}
@props(['action', 'title' => 'Are you sure?', 'message' => '', 'confirmText' => 'Confirm', 'danger' => true])
@php $action = str_contains($action, '(') ? $action : $action.'()'; @endphp
{{-- $wire is captured when the dialog opens, so the action still works if the
     component re-renders (wire:poll) while the dialog is open. --}}
<button type="button"
        x-on:click="((wire) => $store.confirm.ask({{ \Illuminate\Support\Js::from(['title' => $title, 'message' => $message, 'confirmText' => $confirmText, 'danger' => (bool) $danger]) }}, () => wire.{{ $action }}))($wire)"
        {{ $attributes->merge(['class' => 'btn btn-secondary']) }}>
    {{ $slot }}
</button>
