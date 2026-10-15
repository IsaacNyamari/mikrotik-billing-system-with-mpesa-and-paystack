@props([
    'name',
    'label',
    'required' => false,
])

{{-- Label, control and error in one place, so every field on the public site fails the same
     way. The control itself comes in as the slot: this is the wrapper, not an input. --}}
<div>
    <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-slate-700">
        {{ $label }}
        @if ($required)
            <span class="text-red-600" aria-hidden="true">*</span>
        @endif
    </label>

    {{ $slot }}

    @error($name)
        <p class="mt-1.5 text-sm font-medium text-red-600">{{ $message }}</p>
    @enderror
</div>
